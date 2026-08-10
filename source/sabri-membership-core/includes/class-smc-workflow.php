<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Workflow {
	public static function init() {
		add_shortcode( 'smc_membership_application', array( __CLASS__, 'application_shortcode' ) );
		add_shortcode( 'smc_membership_status', array( __CLASS__, 'status_shortcode' ) );
		add_shortcode( 'smc_guardian_consent', array( __CLASS__, 'guardian_shortcode' ) );
		foreach (
			array(
				'submit_application',
				'request_contact_otp',
				'verify_contact_otp',
				'revoke_session',
				'revoke_all_sessions',
				'resubmit',
				'appeal',
				'withdraw_guardian',
			) as $action
		) {
			add_action( 'admin_post_smc_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'admin_post_nopriv_smc_verify_guardian', array( __CLASS__, 'handle_verify_guardian' ) );
		add_action( 'admin_post_smc_verify_guardian', array( __CLASS__, 'handle_verify_guardian' ) );
	}

	private static function login_required() {
		if ( ! is_user_logged_in() ) {
			return smc_notice( __( 'Please sign in through Sabri Authentication to continue.', 'sabri-membership-core' ), 'warning' );
		}
		return '';
	}

	private static function message() {
		$key = isset( $_GET['smc_message'] ) ? sanitize_key( wp_unslash( $_GET['smc_message'] ) ) : '';
		$messages = array(
			'saved'          => array( __( 'Your membership application was saved and submitted.', 'sabri-membership-core' ), 'success' ),
			'otp_sent'       => array( __( 'A verification code was sent through the configured secure provider.', 'sabri-membership-core' ), 'success' ),
			'otp_verified'   => array( __( 'Contact ownership was verified.', 'sabri-membership-core' ), 'success' ),
			'session_revoked'=> array( __( 'The selected session was revoked.', 'sabri-membership-core' ), 'success' ),
			'resubmitted'    => array( __( 'The requested information was resubmitted for review.', 'sabri-membership-core' ), 'success' ),
			'appealed'       => array( __( 'Your appeal was submitted for independent review.', 'sabri-membership-core' ), 'success' ),
			'guardian'       => array( __( 'Guardian consent was verified.', 'sabri-membership-core' ), 'success' ),
			'withdrawn'      => array( __( 'Guardian consent was withdrawn and the minor account was restricted.', 'sabri-membership-core' ), 'warning' ),
			'provider'       => array( __( 'The required verification provider is unavailable. No approval state changed.', 'sabri-membership-core' ), 'error' ),
			'cooldown'       => array( __( 'Please wait before requesting another verification code.', 'sabri-membership-core' ), 'warning' ),
			'invalid'        => array( __( 'The request could not be verified. Review the fields and try again.', 'sabri-membership-core' ), 'error' ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return '';
		}
		$text = $messages[ $key ][0];
		$trace_id = isset( $_GET['trace_id'] ) ? sanitize_text_field( wp_unslash( $_GET['trace_id'] ) ) : '';
		if ( $trace_id && preg_match( '/^[0-9a-f-]{36}$/i', $trace_id ) ) {
			$text .= ' ' . sprintf( __( 'Reference: %s', 'sabri-membership-core' ), strtolower( $trace_id ) );
		}
		return smc_notice( $text, $messages[ $key ][1] );
	}

	private static function redirect( $key, $message, $args = array() ) {
		$url = add_query_arg( array_merge( array( 'smc_message' => $message ), $args ), smc_page_url( $key, '/membership-' . $key . '/' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function application_shortcode() {
		$required = self::login_required();
		if ( $required ) {
			return $required;
		}
		$user_id = get_current_user_id();
		$row = smc_application( $user_id );
		if ( $row && ! in_array( $row['status'], array( 'draft', 'more_information' ), true ) ) {
			return self::message() . smc_notice( __( 'The application is already in a controlled review state. Use Membership Status for the next permitted action.', 'sabri-membership-core' ) );
		}
		$user = wp_get_current_user();
		$draft = SMC_Completion::load_draft( $user_id );
		$types = ! empty( $draft['membership_types'] ) ? smc_sanitize_membership_types( $draft['membership_types'] ) : SMC_Contracts::requested_types( $user_id );
		$current_step = max( 1, min( 7, absint( $draft['current_step'] ?? 1 ) ) );
		$idempotency = wp_generate_uuid4();
		$existing_documents = array();
		foreach ( smc_required_identity_documents() as $document_key => $document_label ) {
			if ( SMC_Security::has_current_document( $user_id, $document_key ) ) { $existing_documents[ $document_key ] = true; }
		}
		$document_total = count( smc_required_identity_documents() );
		$document_received = count( $existing_documents );
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-application-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-application-title"><?php esc_html_e( 'Membership Application', 'sabri-membership-core' ); ?></h1>
			<p><?php esc_html_e( 'Sabri Authentication owns sign-in. File 00 records membership eligibility, identity assurance, guardian consent and approved role grants.', 'sabri-membership-core' ); ?></p>
			<div class="smc-progress-summary" aria-live="polite">
				<strong><?php esc_html_e( 'Application progress', 'sabri-membership-core' ); ?></strong>
				<progress id="smc-application-progress" max="7" value="<?php echo absint( $current_step ); ?>"><?php echo absint( $current_step ); ?>/7</progress>
				<span id="smc-step-label"></span><span id="smc-draft-status"></span>
			</div>
			<form id="smc-membership-application" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form" data-current-step="<?php echo absint( $current_step ); ?>">
				<input type="hidden" name="action" value="smc_submit_application">
				<input type="hidden" name="submission_key" value="<?php echo esc_attr( $idempotency ); ?>">
				<input type="hidden" name="expected_row_version" value="<?php echo esc_attr( $row ? (int) $row['row_version'] : 0 ); ?>">
				<?php wp_nonce_field( 'smc_submit_application', 'smc_nonce' ); ?>

				<fieldset class="smc-step" data-smc-step="1"><legend><?php esc_html_e( 'Step 1 of 7 — Account classes', 'sabri-membership-core' ); ?></legend>
					<p><?php esc_html_e( 'Choose every membership role you are applying for. Each role is approved independently and each protected action is revalidated by domain.', 'sabri-membership-core' ); ?></p>
					<div class="smc-role-grid">
					<?php foreach ( smc_account_types() as $value => $label ) : ?>
						<label class="smc-check"><input type="checkbox" name="membership_types[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $types, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="smc-step" data-smc-step="2"><legend><?php esc_html_e( 'Step 2 of 7 — Personal and eligibility data', 'sabri-membership-core' ); ?></legend>
					<label><?php esc_html_e( 'Legal name', 'sabri-membership-core' ); ?><input name="legal_name" value="<?php echo esc_attr( $draft['legal_name'] ?? ( $row['legal_name'] ?? $user->display_name ) ); ?>" required maxlength="190" autocomplete="name"></label>
					<label><?php esc_html_e( 'Date of birth', 'sabri-membership-core' ); ?><input name="date_of_birth" value="<?php echo esc_attr( $draft['date_of_birth'] ?? '' ); ?>" type="date" required autocomplete="bday"></label>
					<label><?php esc_html_e( 'Gender for the approved minimum-age rule', 'sabri-membership-core' ); ?><select name="gender" required><option value=""><?php esc_html_e( 'Select', 'sabri-membership-core' ); ?></option><?php foreach ( smc_allowed_genders() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $draft['gender'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Country of residence (ISO two-letter code)', 'sabri-membership-core' ); ?><input name="residence_country" value="<?php echo esc_attr( $draft['residence_country'] ?? '' ); ?>" pattern="[A-Za-z]{2}" maxlength="2" required autocomplete="country"></label>
					<label><?php esc_html_e( 'City', 'sabri-membership-core' ); ?><input name="city" value="<?php echo esc_attr( $draft['city'] ?? '' ); ?>" maxlength="120" required autocomplete="address-level2"></label>
					<label><?php esc_html_e( 'Private residential address', 'sabri-membership-core' ); ?><textarea name="address" maxlength="500" required autocomplete="street-address" aria-describedby="smc-address-privacy"><?php echo esc_textarea( $draft['address'] ?? '' ); ?></textarea></label>
					<p id="smc-address-privacy" class="smc-muted"><?php esc_html_e( 'Purpose: jurisdiction and eligibility assurance. Visibility: private. Retention: governed by the File 00 retention/erasure policy and applicable holds.', 'sabri-membership-core' ); ?></p>
					<p class="smc-age-status" role="status" aria-live="polite"></p>
				</fieldset>

				<fieldset class="smc-step" data-smc-step="3"><legend><?php esc_html_e( 'Step 3 of 7 — Contact ownership', 'sabri-membership-core' ); ?></legend>
					<label><?php esc_html_e( 'Account email', 'sabri-membership-core' ); ?><input value="<?php echo esc_attr( $user->user_email ); ?>" type="email" readonly aria-describedby="smc-email-owner-note"></label>
					<p id="smc-email-owner-note"><?php esc_html_e( 'Email/password and Google sign-in remain owned by File 02. File 00 separately verifies ownership before protected membership actions.', 'sabri-membership-core' ); ?></p>
					<label><?php esc_html_e( 'International phone number', 'sabri-membership-core' ); ?><input name="phone" value="<?php echo esc_attr( $draft['phone'] ?? '' ); ?>" type="tel" placeholder="+923001234567" required autocomplete="tel"></label>
				</fieldset>

				<fieldset class="smc-step smc-guardian-step" data-smc-step="4"><legend><?php esc_html_e( 'Step 4 of 7 — Guardian, when legally required', 'sabri-membership-core' ); ?></legend>
					<p><?php esc_html_e( 'These fields are used only when the applicant is under 18. Guardian contact remains private and is retained only for the governed consent purpose.', 'sabri-membership-core' ); ?></p>
					<label><?php esc_html_e( 'Guardian legal name', 'sabri-membership-core' ); ?><input name="guardian_name" value="<?php echo esc_attr( $draft['guardian_name'] ?? '' ); ?>" maxlength="190"></label>
					<label><?php esc_html_e( 'Guardian relationship', 'sabri-membership-core' ); ?><select name="guardian_relationship"><option value=""><?php esc_html_e( 'Select', 'sabri-membership-core' ); ?></option><option value="parent" <?php selected( $draft['guardian_relationship'] ?? '', 'parent' ); ?>><?php esc_html_e( 'Parent', 'sabri-membership-core' ); ?></option><option value="legal_guardian" <?php selected( $draft['guardian_relationship'] ?? '', 'legal_guardian' ); ?>><?php esc_html_e( 'Court-appointed legal guardian', 'sabri-membership-core' ); ?></option></select></label>
					<label><?php esc_html_e( 'Guardian email', 'sabri-membership-core' ); ?><input name="guardian_email" value="<?php echo esc_attr( $draft['guardian_email'] ?? '' ); ?>" type="email" autocomplete="email"></label>
					<label><?php esc_html_e( 'Guardian international phone', 'sabri-membership-core' ); ?><input name="guardian_phone" value="<?php echo esc_attr( $draft['guardian_phone'] ?? '' ); ?>" type="tel" placeholder="+923001234567" autocomplete="tel"></label>
					<label class="smc-check"><input type="checkbox" name="guardian_authority" value="1"> <?php esc_html_e( 'The named adult has legal authority to consent for this applicant.', 'sabri-membership-core' ); ?></label>
				</fieldset>

				<fieldset class="smc-step" data-smc-step="5"><legend><?php esc_html_e( 'Step 5 of 7 — Government identity and private evidence', 'sabri-membership-core' ); ?></legend>
					<p><?php esc_html_e( 'Document numbers and files are encrypted and private. They are not included in public profiles, search indexes, ordinary logs or event payloads.', 'sabri-membership-core' ); ?></p>
					<label><?php esc_html_e( 'Document type', 'sabri-membership-core' ); ?><select name="identity_type"><option value="national_id" <?php selected( $draft['identity_type'] ?? 'national_id', 'national_id' ); ?>><?php esc_html_e( 'National identity card', 'sabri-membership-core' ); ?></option><option value="passport" <?php selected( $draft['identity_type'] ?? '', 'passport' ); ?>><?php esc_html_e( 'Passport', 'sabri-membership-core' ); ?></option></select></label>
					<label><?php esc_html_e( 'Issuing country (ISO two-letter code)', 'sabri-membership-core' ); ?><input name="issuing_country" value="<?php echo esc_attr( $draft['issuing_country'] ?? '' ); ?>" pattern="[A-Za-z]{2}" maxlength="2" required autocomplete="country"></label>
					<label><?php esc_html_e( 'Document number', 'sabri-membership-core' ); ?><input name="identity_number" autocomplete="off" minlength="5" maxlength="24" required aria-describedby="smc-identity-privacy"></label>
					<p id="smc-identity-privacy" class="smc-muted"><?php esc_html_e( 'Purpose: identity assurance and duplicate-identity prevention. Visibility: restricted reviewers only. Retention: governed private evidence with controlled deletion/holds; never public.', 'sabri-membership-core' ); ?></p>
					<?php foreach ( smc_required_identity_documents() as $key => $label ) : $already_received = ! empty( $existing_documents[ $key ] ); ?>
						<label><?php echo esc_html( $label ); ?><input type="file" name="<?php echo esc_attr( $key ); ?>" accept="<?php echo 'identity_selfie' === $key ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,application/pdf'; ?>" <?php echo $already_received ? '' : 'required'; ?> aria-describedby="smc-doc-state-<?php echo esc_attr( $key ); ?>"></label>
						<p id="smc-doc-state-<?php echo esc_attr( $key ); ?>" class="smc-muted"><?php echo esc_html( $already_received ? __( 'Secure evidence already received. Leave this blank to keep the accepted copy, or choose a new file to replace it.', 'sabri-membership-core' ) : __( 'Not yet received by the server. This evidence is required.', 'sabri-membership-core' ) ); ?></p>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'Evidence checkpoint', 'sabri-membership-core' ); ?><progress id="smc-upload-progress" max="<?php echo absint( max( 1, $document_total ) ); ?>" value="<?php echo absint( $document_received ); ?>"><?php echo absint( $document_received ); ?>/<?php echo absint( $document_total ); ?></progress></label>
					<p class="smc-muted"><?php esc_html_e( 'Interrupted submissions resume at the document checkpoint: evidence already accepted by the server is not uploaded again unless you deliberately select a replacement.', 'sabri-membership-core' ); ?></p>
				</fieldset>

				<fieldset class="smc-step" data-smc-step="6"><legend><?php esc_html_e( 'Step 6 of 7 — Consents', 'sabri-membership-core' ); ?></legend>
					<p><?php esc_html_e( 'Identity evidence is processed for membership assurance, reviewer decision, fraud prevention, audit and the published retention/erasure process. Donation is optional and never affects approval, visibility or service access.', 'sabri-membership-core' ); ?></p>
					<label class="smc-check"><input type="checkbox" name="truth" value="1" required> <?php esc_html_e( 'I declare that the submitted identity information is true and belongs to me.', 'sabri-membership-core' ); ?></label>
					<label class="smc-check"><input type="checkbox" name="privacy" value="1" required> <?php esc_html_e( 'I consent to the stated identity-verification processing and retention policy.', 'sabri-membership-core' ); ?></label>
					<label class="smc-check"><input type="checkbox" name="terms" value="1" required> <?php esc_html_e( 'I accept the platform membership terms and governed account rules.', 'sabri-membership-core' ); ?></label>
					<label class="smc-check"><input type="checkbox" name="ethical" value="1" required> <?php esc_html_e( 'I accept the ethical-use duties, truthful conduct and prohibition of impersonation or misuse.', 'sabri-membership-core' ); ?></label>
				</fieldset>

				<fieldset class="smc-step" data-smc-step="7"><legend><?php esc_html_e( 'Step 7 of 7 — Review and submit', 'sabri-membership-core' ); ?></legend>
					<div id="smc-review-summary" class="smc-review-summary"></div>
					<p><?php esc_html_e( 'Submission is idempotency-protected. The server revalidates age, guardian, roles, files, identity uniqueness, consent, current row version and security health.', 'sabri-membership-core' ); ?></p>
					<button class="smc-button" type="submit"><?php esc_html_e( 'Submit Membership Application', 'sabri-membership-core' ); ?></button>
					<button class="button" type="button" id="smc-retry-submit" hidden><?php esc_html_e( 'Retry submission', 'sabri-membership-core' ); ?></button>
				</fieldset>

				<div class="smc-step-controls"><button type="button" class="smc-button smc-button--secondary" data-smc-prev><?php esc_html_e( 'Previous', 'sabri-membership-core' ); ?></button><button type="button" class="smc-button" data-smc-next><?php esc_html_e( 'Next', 'sabri-membership-core' ); ?></button></div>
			</form>
			<noscript><p class="smc-notice smc-notice--info"><?php esc_html_e( 'JavaScript is optional. All steps remain visible and the server validates the complete application.', 'sabri-membership-core' ); ?></p></noscript>
		</main>
		<?php
		return ob_get_clean();
	}

	public static function handle_submit_application() {
		self::guard_user_action( 'smc_submit_application' );
		$user_id = get_current_user_id();
		$existing_application = smc_application( $user_id );
		if ( $existing_application && ! in_array( sanitize_key( $existing_application['status'] ?? '' ), array( 'draft', 'more_information' ), true ) ) {
			self::redirect( 'status', 'saved' );
		}
		$is_resubmission = $existing_application && 'more_information' === sanitize_key( $existing_application['status'] ?? '' );
		$submission_key = sanitize_text_field( wp_unslash( $_POST['submission_key'] ?? '' ) );
		$expected_row_raw = sanitize_text_field( wp_unslash( $_POST['expected_row_version'] ?? '' ) );
		$expected_row_version = preg_match( '/^\d+$/', $expected_row_raw ) ? (int) $expected_row_raw : -1;
		if ( $expected_row_version < 0 || ! preg_match( '/^[0-9a-f-]{36}$/i', $submission_key ) ) {
			self::redirect( 'application', 'invalid' );
		}
		if ( hash_equals( (string) get_user_meta( $user_id, '_smc_last_submission_key', true ), $submission_key ) ) {
			self::redirect( 'status', 'saved' );
		}
		$submission_receipt_key = '_smc_submission_' . substr( hash( 'sha256', $submission_key ), 0, 32 );
		$processing_receipt = array( 'status' => 'processing', 'started_at' => time() );
		if ( ! add_user_meta( $user_id, $submission_receipt_key, $processing_receipt, true ) ) {
			$receipt = get_user_meta( $user_id, $submission_receipt_key, true );
			if ( is_array( $receipt ) && 'completed' === ( $receipt['status'] ?? '' ) ) {
				self::redirect( 'status', 'saved' );
			}
			$is_stale = is_array( $receipt ) && 'processing' === ( $receipt['status'] ?? '' ) && absint( $receipt['started_at'] ?? 0 ) <= time() - 15 * MINUTE_IN_SECONDS;
			if ( ! $is_stale || ! delete_user_meta( $user_id, $submission_receipt_key, $receipt ) || ! add_user_meta( $user_id, $submission_receipt_key, $processing_receipt, true ) ) {
				self::redirect( 'application', 'invalid', array( 'duplicate' => 1 ) );
			}
			SMC_Security::audit( 'stale_application_submission_reclaimed', $user_id, array( 'receipt_key_hash' => hash( 'sha256', $submission_receipt_key ) ) );
		}
		if ( SMC_Security::rate_limited( 'application|' . $user_id, 5, HOUR_IN_SECONDS ) || ! SMC_Security::key_ready() || SMC_Completion::safe_mode() ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		$legal_name = sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) );
		$dob = sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) );
		$gender = sanitize_key( wp_unslash( $_POST['gender'] ?? '' ) );
		$types = smc_sanitize_membership_types( isset( $_POST['membership_types'] ) ? (array) wp_unslash( $_POST['membership_types'] ) : array() );
		$residence_country = strtoupper( sanitize_text_field( wp_unslash( $_POST['residence_country'] ?? '' ) ) );
		$city = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
		$address = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );
		$phone = smc_normalize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		$id_type = sanitize_key( wp_unslash( $_POST['identity_type'] ?? '' ) );
		$id_number = strtoupper( sanitize_text_field( wp_unslash( $_POST['identity_number'] ?? '' ) ) );
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['issuing_country'] ?? '' ) ) );
		$age = smc_age_from_dob( $dob );
		$has_professional = (bool) array_filter( $types, 'smc_is_professional_type' );
		$baseline_minimum = smc_minimum_age_for_gender( $gender );
		$effective_minimum = smc_effective_minimum_age( $gender, $residence_country );
		if (
			'' === $legal_name || false === $age || ! isset( smc_allowed_genders()[ $gender ] ) ||
			! $types || is_wp_error( $phone ) ||
			! preg_match( '/^[A-Z]{2}$/', $residence_country ) || '' === $city || '' === $address ||
			! in_array( $id_type, array( 'national_id', 'passport' ), true ) ||
			! preg_match( '/^[A-Z]{2}$/', $country ) ||
			! preg_match( '/^[A-Z0-9][A-Z0-9 -]{4,23}$/', $id_number ) ||
			false === $baseline_minimum || false === $effective_minimum ||
			$age < $effective_minimum ||
			( $age < 18 && $has_professional ) ||
			empty( $_POST['truth'] ) || empty( $_POST['privacy'] ) || empty( $_POST['terms'] ) || empty( $_POST['ethical'] )
		) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		$type = $types[0]; // Backward-compatible primary class; role grants retain every selected class.
		$id_number = apply_filters( 'smc_validate_identity_number', $id_number, $id_type, $country );
		if ( is_wp_error( $id_number ) ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		$phone_hash = SMC_Security::blind_index( $phone, 'phone' );
		$id_hash = SMC_Security::blind_index( $country . '|' . $id_type . '|' . $id_number, 'identity-number' );
		if ( is_wp_error( $phone_hash ) || is_wp_error( $id_hash ) ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		global $wpdb;
		$duplicate_phone = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_applications WHERE phone_hash=%s AND user_id<>%d", $phone_hash, $user_id ) );
		$duplicate_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_identity_records WHERE document_number_hash=%s AND user_id<>%d", $id_hash, $user_id ) );
		if ( $duplicate_phone || $duplicate_id ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		$dob_enc = SMC_Security::encrypt( $dob, 'date-of-birth', array( 'user_id' => $user_id ) );
		$phone_enc = SMC_Security::encrypt( $phone, 'membership-phone', array( 'user_id' => $user_id ) );
		$address_enc = SMC_Security::encrypt( $address, 'residential-address', array( 'user_id' => $user_id, 'country' => $residence_country ) );
		$id_enc = SMC_Security::encrypt( $id_number, 'identity-number', array( 'user_id' => $user_id, 'type' => $id_type, 'country' => $country ) );
		if ( is_wp_error( $dob_enc ) || is_wp_error( $phone_enc ) || is_wp_error( $address_enc ) || is_wp_error( $id_enc ) ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			self::redirect( 'application', 'invalid' );
		}
		$guardian_required = $age < 18 ? 1 : 0;
		$trace_id = wp_generate_uuid4();
		$now = current_time( 'mysql', true );
		$application_version = 1;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_applications WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
			$current_version = $current ? (int) $current['row_version'] : 0;
			$current_status = $current ? sanitize_key( $current['status'] ?? '' ) : '';
			if ( $current_version !== $expected_row_version || ( $current && ! in_array( $current_status, array( 'draft', 'more_information' ), true ) ) ) {
				throw new RuntimeException( 'The membership application changed after this form was loaded.' );
			}
			// Repeat uniqueness checks under the same transaction used for the write.
			// Unique indexes remain the final database invariant; these locked reads
			// provide a deterministic conflict path before encrypted state is mutated.
			$duplicate_phone_locked = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_applications WHERE phone_hash=%s AND user_id<>%d LIMIT 1 FOR UPDATE", $phone_hash, $user_id ) );
			$duplicate_id_locked = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_identity_records WHERE document_number_hash=%s AND user_id<>%d LIMIT 1 FOR UPDATE", $id_hash, $user_id ) );
			if ( $duplicate_phone_locked || $duplicate_id_locked ) {
				throw new RuntimeException( 'A phone or identity uniqueness conflict was detected during the authoritative transaction.' );
			}
			$is_resubmission = $current && 'more_information' === $current_status;
			$application_version = $current_version + 1;
			$app_data = array(
				'legal_name'         => $legal_name,
				'date_of_birth_enc'  => $dob_enc,
				'gender'             => $gender,
				'residence_country'  => $residence_country,
				'city'               => $city,
				'address_enc'        => $address_enc,
				'phone_e164_enc'     => $phone_enc,
				'phone_hash'         => $phone_hash,
				'membership_type'    => $type,
				'status'             => 'draft',
				'guardian_required'  => $guardian_required,
				'profile_visibility' => 'private',
				'policy_version'     => smc_policy()['version'],
				'row_version'        => $application_version,
				'updated_at'         => $now,
			);
			if ( $current ) {
				$ok = $wpdb->update( $wpdb->prefix . 'smc_applications', $app_data, array( 'user_id' => $user_id, 'row_version' => (int) $current['row_version'] ) );
			} else {
				$app_data['user_id'] = $user_id;
				$app_data['created_at'] = $now;
				$ok = $wpdb->insert( $wpdb->prefix . 'smc_applications', $app_data );
			}
			if ( 1 !== $ok ) {
				throw new RuntimeException( 'Membership application concurrency or database failure.' );
			}
			$identity = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
			$id_data = array(
				'document_type'       => $id_type,
				'document_number_enc' => $id_enc,
				'document_number_hash'=> $id_hash,
				'issuing_country'     => $country,
				'name_match_status'   => 'unreviewed',
				'name_match_note'     => null,
				'verified_at'         => null,
				'verified_by'         => 0,
				'updated_at'          => $now,
			);
			if ( $identity ) {
				$ok = $wpdb->update( $wpdb->prefix . 'smc_identity_records', $id_data, array( 'id' => (int) $identity['id'] ) );
			} else {
				$id_data['user_id'] = $user_id;
				$id_data['created_at'] = $now;
				$ok = $wpdb->insert( $wpdb->prefix . 'smc_identity_records', $id_data );
			}
			if ( false === $ok || ! SMC_Contracts::replace_requested_types( $user_id, $types, $application_version ) ) {
				throw new RuntimeException( 'Identity or role-grant database failure.' );
			}
			self::record_consent( $user_id, 'identity_verification', __( 'I consent to identity verification, protected evidence review, documented retention, and the published privacy process.', 'sabri-membership-core' ), 'self' );
			self::record_consent( $user_id, 'membership_terms', __( 'I accept the platform membership terms and governed account rules.', 'sabri-membership-core' ), 'self' );
			self::record_consent( $user_id, 'ethical_use', __( 'I accept truthful conduct, ethical use, and the prohibition of impersonation or misuse.', 'sabri-membership-core' ), 'self' );
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The membership application transaction could not be committed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			delete_user_meta( $user_id, $submission_receipt_key );
			SMC_Security::audit( 'application_transaction_failed', $user_id, array( 'reason' => $error->getMessage(), 'trace_id' => $trace_id ) );
			self::redirect( 'application', 'invalid', array( 'trace_id' => $trace_id ) );
		}
		$stored_documents = array();
		foreach ( smc_required_identity_documents() as $key => $label ) {
			$upload_supplied = ! empty( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES[ $key ]['error'] ?? UPLOAD_ERR_NO_FILE );
			$result = ( ! $upload_supplied && SMC_Security::has_current_document( $user_id, $key ) ) ? true : SMC_Security::store_uploaded_document( $key, $label, $user_id, $key );
			if ( is_wp_error( $result ) ) {
				$repair_trace = SMC_Completion::record_repair( $user_id, 'application_document_incomplete', array( 'document_key' => $key, 'stored_documents' => $stored_documents, 'reason' => $result->get_error_message(), 'application_version' => $application_version ), $trace_id );
				SMC_Contracts::set_all_roles_pending( $user_id, $application_version );
				delete_user_meta( $user_id, $submission_receipt_key );
				SMC_Security::audit( 'application_document_incomplete', $user_id, array( 'document_key' => $key, 'reason' => $result->get_error_message(), 'trace_id' => $repair_trace ?: $trace_id ) );
				self::redirect( 'application', 'invalid', array( 'trace_id' => $repair_trace ?: $trace_id ) );
			}
			$stored_documents[] = $key;
		}
		if ( $guardian_required && ! self::create_guardian_invitation( $user_id ) ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			$repair_trace = SMC_Completion::record_repair( $user_id, 'guardian_delivery_pending', array( 'application_version' => $application_version ), $trace_id );
			self::redirect( 'application', 'provider', array( 'trace_id' => $repair_trace ?: $trace_id ) );
		}
		$status = $guardian_required ? 'guardian_pending' : ( $is_resubmission ? 'resubmitted' : 'submitted' );
		if ( ! self::submit_request( $user_id, $status, array( 'age' => $age, 'type' => $type, 'role_types' => $types, 'guardian_required' => $guardian_required, 'resubmission' => $is_resubmission, 'trace_id' => $trace_id ) ) ) {
			delete_user_meta( $user_id, $submission_receipt_key );
			SMC_Completion::record_repair( $user_id, 'application_submission_reconciliation', array( 'application_version' => $application_version, 'status' => $status ), $trace_id );
			self::redirect( 'application', 'invalid', array( 'trace_id' => $trace_id ) );
		}
		$completed_receipt = array( 'status' => 'completed', 'completed_at' => time(), 'trace_id' => $trace_id );
		update_user_meta( $user_id, '_smc_last_submission_key', $submission_key );
		$last_key_ok = hash_equals( $submission_key, (string) get_user_meta( $user_id, '_smc_last_submission_key', true ) );
		update_user_meta( $user_id, $submission_receipt_key, $completed_receipt );
		$stored_receipt = get_user_meta( $user_id, $submission_receipt_key, true );
		$receipt_ok = is_array( $stored_receipt ) && 'completed' === ( $stored_receipt['status'] ?? '' ) && hash_equals( $trace_id, (string) ( $stored_receipt['trace_id'] ?? '' ) );
		if ( ! $last_key_ok || ! $receipt_ok ) {
			SMC_Completion::record_repair( $user_id, 'application_idempotency_receipt', array( 'last_key_ok'=>(bool)$last_key_ok, 'receipt_ok'=>(bool)$receipt_ok ), $trace_id );
			SMC_Security::audit( 'application_idempotency_receipt_failed', $user_id, array( 'trace_id' => $trace_id, 'last_key_ok'=>(bool)$last_key_ok, 'receipt_ok'=>(bool)$receipt_ok ) );
		}
		SMC_Completion::clear_draft( $user_id );
		self::redirect( 'status', 'saved', array( 'trace_id' => $trace_id ) );
	}

	private static function record_consent( $user_id, $purpose, $text, $actor_type ) {
		global $wpdb;
		$hash = hash( 'sha256', $text );
		$ok = $wpdb->insert(
			$wpdb->prefix . 'smc_consents',
			array(
				'user_id'       => absint( $user_id ),
				'actor_type'    => sanitize_key( $actor_type ),
				'purpose'       => sanitize_key( $purpose ),
				'locale'        => determine_locale(),
				'channel'       => 'web',
				'text_snapshot' => $text,
				'text_hash'     => $hash,
				'policy_version'=> smc_policy()['version'],
				'accepted_at'   => current_time( 'mysql', true ),
			)
		);
		if ( 1 !== $ok ) {
			throw new RuntimeException( 'Consent evidence database failure.' );
		}
	}

	private static function create_guardian_invitation( $user_id ) {
		$name = sanitize_text_field( wp_unslash( $_POST['guardian_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['guardian_email'] ?? '' ) );
		$phone = smc_normalize_phone( wp_unslash( $_POST['guardian_phone'] ?? '' ) );
		$relationship = sanitize_key( wp_unslash( $_POST['guardian_relationship'] ?? '' ) );
		if ( ! $name || ! is_email( $email ) || is_wp_error( $phone ) || ! in_array( $relationship, array( 'parent', 'legal_guardian' ), true ) || empty( $_POST['guardian_authority'] ) ) { return false; }
		$code = (string) random_int( 100000, 999999 );
		$token = wp_generate_password( 48, false, false );
		$context = array( 'user_id' => absint( $user_id ) );
		$name_enc = SMC_Security::encrypt( $name, 'guardian-name', $context );
		$email_enc = SMC_Security::encrypt( $email, 'guardian-email', $context );
		$phone_enc = SMC_Security::encrypt( $phone, 'guardian-phone', $context );
		$email_hash = SMC_Security::blind_index( $email, 'guardian-email' );
		$phone_hash = SMC_Security::blind_index( $phone, 'guardian-phone' );
		$lookup = SMC_Security::blind_index( $code, 'guardian-otp' );
		$token_hash = SMC_Security::blind_index( $token, 'guardian-invitation' );
		foreach ( array( $name_enc, $email_enc, $phone_enc, $email_hash, $phone_hash, $lookup, $token_hash ) as $value ) { if ( is_wp_error( $value ) ) { return false; } }
		$consent_text = __( 'I confirm that I am the parent or lawful guardian, consent to this minor using the platform under its published rules, and understand that I may withdraw consent.', 'sabri-membership-core' );
		$link = add_query_arg( 'guardian_token', rawurlencode( $token ), smc_page_url( 'guardian', '/guardian-consent/' ) );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT id,generation FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 ORDER BY generation DESC,id DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$generation = $previous ? (int) $previous['generation'] + 1 : 1;
		if ( $previous && 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=0,withdrawn_at=IF(status='pending',%s,withdrawn_at),status=IF(status='pending','superseded',status) WHERE id=%d AND user_id=%d AND is_current=1", $now, (int) $previous['id'], $user_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$inserted = $wpdb->insert( $wpdb->prefix . 'smc_guardian_consents', array(
			'user_id'=>$user_id,'generation'=>$generation,'is_current'=>1,'guardian_name_enc'=>$name_enc,'guardian_email_enc'=>$email_enc,'guardian_email_hash'=>$email_hash,'guardian_phone_enc'=>$phone_enc,'guardian_phone_hash'=>$phone_hash,'relationship'=>$relationship,'legal_authority_confirmed'=>1,'status'=>'pending','consent_text'=>$consent_text,'consent_hash'=>hash('sha256',$consent_text),'policy_version'=>smc_policy()['version'],'otp_hash'=>wp_hash_password($code),'otp_lookup_hash'=>$lookup,'invitation_token_hash'=>$token_hash,'delivery_receipt_hash'=>null,'delivered_at'=>null,'otp_attempts'=>0,'otp_expires_at'=>gmdate('Y-m-d H:i:s',time()+15*MINUTE_IN_SECONDS),'requested_at'=>$now,'verified_at'=>null,'withdrawn_at'=>null,'ip_hash'=>SMC_Security::blind_index($_SERVER['REMOTE_ADDR']??'','guardian-ip'),'device_hash'=>SMC_Security::blind_index($_SERVER['HTTP_USER_AGENT']??'','guardian-device')
		) );
		if ( 1 !== $inserted ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$consent_id = (int) $wpdb->insert_id;
		if ( ! SMC_Security::audit( 'guardian_invitation_created', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return false; }
		$sent = apply_filters( 'smc_send_guardian_invitation', false, array( 'user_id'=>$user_id,'consent_id'=>$consent_id,'generation'=>$generation,'guardian_name'=>$name,'guardian_email'=>$email,'guardian_phone'=>$phone,'code'=>$code,'link'=>$link,'expires_in'=>900 ) );
		$accepted = true === $sent || ( is_array( $sent ) && ! empty( $sent['accepted'] ) );
		$provider_receipt = is_array( $sent ) ? (string) ( $sent['receipt_id'] ?? $sent['provider_reference'] ?? '' ) : '';
		if ( ! $accepted || '' === $provider_receipt ) {
			$wpdb->query( 'START TRANSACTION' );
			$new_failed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='delivery_failed',is_current=0,withdrawn_at=%s WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1", current_time('mysql',true), $consent_id, $user_id ) );
			$old_restored = ! $previous || 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=1,withdrawn_at=IF(status='superseded',NULL,withdrawn_at),status=IF(status='superseded','pending',status) WHERE id=%d AND user_id=%d AND is_current=0", (int) $previous['id'], $user_id ) );
			$audit_ok = $new_failed && $old_restored && SMC_Security::audit( 'guardian_invitation_delivery_failed', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) );
			if ( ! $new_failed || ! $old_restored || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
			return false;
		}
		$receipt_hash = SMC_Security::blind_index( $provider_receipt . '|' . $consent_id . '|' . $generation, 'guardian-delivery-receipt' );
		$receipt_saved = false;
		$delivery_audit_ok = false;
		if ( ! is_wp_error( $receipt_hash ) ) {
			$wpdb->query( 'START TRANSACTION' );
			$locked_delivery = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_guardian_consents WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1 LIMIT 1 FOR UPDATE", $consent_id, $user_id ), ARRAY_A );
			$receipt_saved = $locked_delivery ? $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET delivery_receipt_hash=%s,delivered_at=%s WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1", $receipt_hash, current_time( 'mysql', true ), $consent_id, $user_id ) ) : false;
			$delivery_audit_ok = 1 === $receipt_saved && SMC_Security::audit( 'guardian_invitation_delivered', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation,'receipt_hash'=>$receipt_hash ) );
			if ( 1 === $receipt_saved && $delivery_audit_ok && false !== $wpdb->query( 'COMMIT' ) ) {
				return true;
			}
			$wpdb->query( 'ROLLBACK' );
		}
		// A provider may already have delivered the code. Make that generation
		// unverifiable and restore the previous canonical generation so a delivery
		// without durable receipt/audit evidence cannot become trusted consent.
		$wpdb->query( 'START TRANSACTION' );
		$new_failed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='delivery_failed',is_current=0,withdrawn_at=%s WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1", current_time( 'mysql', true ), $consent_id, $user_id ) );
		$old_restored = ! $previous || 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=1,withdrawn_at=IF(status='superseded',NULL,withdrawn_at),status=IF(status='superseded','pending',status) WHERE id=%d AND user_id=%d AND is_current=0", (int) $previous['id'], $user_id ) );
		$failure_audit_ok = $new_failed && $old_restored && SMC_Security::audit( 'guardian_invitation_receipt_failed', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) );
		if ( ! $new_failed || ! $old_restored || ! $failure_audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); }
		return false;
	}

	private static function submit_request( $user_id, $status, $audit_details = array() ) {
		global $wpdb;
		$app = smc_application( $user_id );
		if ( ! $app ) {
			return false;
		}
		$audit_details = is_array( $audit_details ) ? $audit_details : array();
		$trace_id = ! empty( $audit_details['trace_id'] ) && preg_match( '/^[0-9a-f-]{36}$/i', (string) $audit_details['trace_id'] ) ? strtolower( (string) $audit_details['trace_id'] ) : wp_generate_uuid4();
		$queue_type = 'guardian_pending' === $status ? ( ! empty( $audit_details['resubmission'] ) ? 'guardian_resubmission' : 'guardian' ) : ( 'resubmitted' === $status ? 'resubmitted' : 'new' );
		$submitted_roles = ! empty( $audit_details['role_types'] ) ? smc_sanitize_membership_types( $audit_details['role_types'] ) : array( $audit_details['type'] ?? 'member' );
		if ( array_intersect( $submitted_roles, smc_professional_types() ) ) {
			$queue_type = 'professional';
		}
		$now = current_time( 'mysql', true );
		$sla_hours = max( 1, absint( SMC_Completion::service_levels()['review_queue_target_hours'] ?? 72 ) );
		$sla_due = gmdate( 'Y-m-d H:i:s', time() + $sla_hours * HOUR_IN_SECONDS );
		$applicant_version = (int) $app['row_version'] + 1;
		$hold = array( 'operation' => 'submit', 'target_status' => sanitize_key( $status ), 'started_at' => time() );
		update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );
		if ( get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { return false; }
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_verification_requests
				(user_id,status,queue_type,assigned_reviewer,assigned_at,conflict_status,conflict_note,reason_code,trace_id,sla_due_at,reviewer_note,applicant_version,row_version,submitted_at,created_at,updated_at)
				VALUES (%d,%s,%s,0,NULL,'undeclared',NULL,NULL,%s,%s,NULL,%d,1,%s,%s,%s)
				ON DUPLICATE KEY UPDATE status=VALUES(status),queue_type=VALUES(queue_type),assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,reason_code=NULL,trace_id=VALUES(trace_id),sla_due_at=VALUES(sla_due_at),reviewer_note=NULL,applicant_version=VALUES(applicant_version),approval_generation=NULL,approval_snapshot_hash=NULL,row_version=row_version+1,submitted_at=VALUES(submitted_at),decided_at=NULL,updated_at=VALUES(updated_at)",
				$user_id, $status, $queue_type, $trace_id, $sla_due, $applicant_version, $now, $now, $now
			)
		);
		$ok2 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,submitted_at=%s,updated_at=%s,row_version=row_version+1 WHERE user_id=%d AND row_version=%d",
				$status, $now, $now, $user_id, (int) $app['row_version']
			)
		);
		$role_ok = false !== $ok1 && 1 === $ok2 && SMC_Contracts::set_all_roles_pending( $user_id, $applicant_version, false );
		$audit_details['queue_type'] = $queue_type;
		$audit_details['trace_id'] = $trace_id;
		$audit_details['applicant_version'] = $applicant_version;
		$audit_ok = $role_ok && SMC_Security::audit( 'membership_application_submitted', $user_id, $audit_details );
		if ( false === $ok1 || 1 !== $ok2 || ! $role_ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
			clean_user_cache( $user_id );
			return false;
		}
		$roles_wp_ok = SMC_Contracts::sync_wordpress_roles( $user_id );
		$sessions_ok = $roles_wp_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_application_submitted' );
		if ( ! $roles_wp_ok || ! $sessions_ok ) {
			SMC_Completion::queue_effects_repair( $user_id, 'submit', $status, 'postcommit_effects' );
			clean_user_cache( $user_id );
			return false;
		}
		delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
		if ( metadata_exists( 'user', $user_id, '_smc_membership_effects_hold_v1' ) ) {
			SMC_Completion::queue_effects_repair( $user_id, 'submit', $status, 'hold_release_failed' );
			return false;
		}
		clean_user_cache( $user_id );
		return true;
	}

	public static function status_shortcode() {
		$required = self::login_required();
		if ( $required ) {
			return $required;
		}
		$user_id = get_current_user_id();
		$row = smc_application( $user_id );
		if ( ! $row ) {
			return smc_notice( __( 'No membership application exists yet.', 'sabri-membership-core' ), 'warning' );
		}
		$a = SMC_Contracts::assertions( $user_id );
		global $wpdb;
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT status,queue_type,sla_due_at,submitted_at,updated_at FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id ), ARRAY_A );
		$next_action = __( 'No immediate action. The authoritative review state will update here.', 'sabri-membership-core' );
		if ( ! $a['email_verified'] ) { $next_action = __( 'Verify email ownership using the configured delivery provider.', 'sabri-membership-core' ); }
		elseif ( ! $a['phone_verified'] ) { $next_action = __( 'Verify mobile ownership using the configured SMS provider.', 'sabri-membership-core' ); }
		elseif ( ! $a['guardian_verified'] ) { $next_action = __( 'Complete the current guardian-consent generation.', 'sabri-membership-core' ); }
		elseif ( 'more_information' === $a['status'] ) { $next_action = __( 'Provide the requested correction and resubmit.', 'sabri-membership-core' ); }
		elseif ( in_array( $a['status'], array( 'rejected','suspended' ), true ) ) { $next_action = __( 'Use the governed appeal path if you have corrective evidence.', 'sabri-membership-core' ); }
		$application_label = smc_statuses()[ $a['status']] ?? $a['status'];
		if ( ! empty( $a['institutional_account'] ) && 'verified' === $a['status'] && ( ! $a['email_verified'] || ! $a['phone_verified'] ) ) {
			$application_label = __( 'Institutionally recognized — contact setup incomplete', 'sabri-membership-core' );
		}
		$blockers = array();
		if ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { $blockers[] = __( 'Safe Mode is active; risky writes are restricted.', 'sabri-membership-core' ); }
		if ( metadata_exists( 'user', $user_id, '_smc_membership_effects_hold_v1' ) ) { $blockers[] = __( 'A membership side-effect repair is pending.', 'sabri-membership-core' ); }
		if ( ! $a['email_verified'] && class_exists( 'SMC_Contact_Delivery' ) && ! SMC_Contact_Delivery::email_provider_ready() ) { $blockers[] = __( 'Email delivery readiness is not advertised by File 19.', 'sabri-membership-core' ); }
		if ( ! $a['phone_verified'] && class_exists( 'SMC_Contact_Delivery' ) && ! SMC_Contact_Delivery::mobile_provider_ready() ) { $blockers[] = __( 'SMS delivery readiness is not advertised by File 19.', 'sabri-membership-core' ); }
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-status-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-status-title"><?php esc_html_e( 'Membership Status', 'sabri-membership-core' ); ?></h1>
			<nav class="smc-status-journey" aria-label="<?php esc_attr_e( 'Membership journey', 'sabri-membership-core' ); ?>"><a class="smc-button" href="<?php echo esc_url( smc_page_url( 'application', '/membership-application/' ) ); ?>"><?php esc_html_e( 'Membership Application', 'sabri-membership-core' ); ?></a> <a class="smc-button" href="<?php echo esc_url( smc_page_url( 'security', '/membership-security/' ) ); ?>"><?php esc_html_e( 'Security Center', 'sabri-membership-core' ); ?></a> <a class="smc-button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'sabri-membership-core' ); ?></a></nav>
			<dl class="smc-status-grid">
				<div><dt><?php esc_html_e( 'Application', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( $application_label ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Email ownership', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['email_verified'] ? esc_html__( 'Verified', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Mobile ownership', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['phone_verified'] ? esc_html__( 'Verified', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Guardian consent', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['guardian_verified'] ? esc_html__( 'Verified or not required', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Authentication owner', 'sabri-membership-core' ); ?></dt><dd><?php esc_html_e( 'Sabri Authentication (File 02); File 00 MFA retired', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Submitted', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( (string) ( $row['submitted_at'] ?: ( $request['submitted_at'] ?? '—' ) ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Last updated', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( (string) ( $row['updated_at'] ?? '—' ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Review state', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( (string) ( $request['status'] ?? __( 'Not queued', 'sabri-membership-core' ) ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Next action', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( $next_action ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Known blockers', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( $blockers ? implode( ' ', $blockers ) : __( 'None currently reported by File 00.', 'sabri-membership-core' ) ); ?></dd></div>
			</dl>
			<?php self::contact_forms( $user_id ); ?>
			<p><a class="smc-button" href="<?php echo esc_url( smc_page_url( 'security', '/membership-security/' ) ); ?>"><?php esc_html_e( 'Open Security Center', 'sabri-membership-core' ); ?></a></p>
			<?php if ( 'more_information' === $a['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_resubmit"><?php wp_nonce_field( 'smc_resubmit', 'smc_nonce' ); ?><label><?php esc_html_e( 'Response to reviewer', 'sabri-membership-core' ); ?><textarea name="response" required></textarea></label><button class="smc-button"><?php esc_html_e( 'Resubmit', 'sabri-membership-core' ); ?></button></form>
			<?php endif; ?>
			<?php if ( in_array( $a['status'], array( 'rejected', 'suspended' ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_appeal"><?php wp_nonce_field( 'smc_appeal', 'smc_nonce' ); ?><label><?php esc_html_e( 'Appeal grounds and corrective evidence', 'sabri-membership-core' ); ?><textarea name="reason" required minlength="20"></textarea></label><button class="smc-button"><?php esc_html_e( 'Submit Appeal', 'sabri-membership-core' ); ?></button></form>
			<?php endif; ?>
			<?php if ( $row['guardian_required'] && $a['guardian_verified'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smc_withdraw_guardian"><?php wp_nonce_field( 'smc_withdraw_guardian', 'smc_nonce' ); ?><button class="smc-button smc-button--danger"><?php esc_html_e( 'Withdraw Guardian Consent', 'sabri-membership-core' ); ?></button></form>
			<?php endif; ?>
		</main>
		<?php
		return ob_get_clean();
	}

	private static function contact_forms( $user_id ) {
		foreach ( array( 'email' => __( 'Email', 'sabri-membership-core' ), 'mobile' => __( 'Mobile', 'sabri-membership-core' ) ) as $channel => $label ) {
			$verified = SMC_Contracts::contact_verified( $user_id, $channel );
			if ( $verified ) {
				continue;
			}
			?>
			<section class="smc-subpanel"><h2><?php echo esc_html( $label . ' ' . __( 'Verification', 'sabri-membership-core' ) ); ?></h2>
				<?php $provider_ready = class_exists( 'SMC_Contact_Delivery' ) && ( 'email' === $channel ? SMC_Contact_Delivery::email_provider_ready() : SMC_Contact_Delivery::mobile_provider_ready() ); ?>
				<?php if ( ! $provider_ready ) : ?><p class="smc-notice smc-notice--warning <?php echo 'email' === $channel ? 'smc-email-provider-warning' : 'smc-sms-provider-warning'; ?>"><?php echo esc_html( 'email' === $channel ? __( 'Email OTP provider readiness is not advertised. File 19 must supply a receipt-bearing delivery adapter; the send action remains available so a configured adapter can respond authoritatively.', 'sabri-membership-core' ) : __( 'Mobile SMS provider readiness is not advertised. File 19 must supply a receipt-bearing SMS adapter; the send action remains available so a configured adapter can respond authoritatively.', 'sabri-membership-core' ) ); ?></p><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smc_request_contact_otp"><input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>"><?php wp_nonce_field( 'smc_request_contact_otp', 'smc_nonce' ); ?><button class="smc-button"><?php echo esc_html( sprintf( __( 'Send %s Code', 'sabri-membership-core' ), $label ) ); ?></button></form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-inline-form"><input type="hidden" name="action" value="smc_verify_contact_otp"><input type="hidden" name="channel" value="<?php echo esc_attr( $channel ); ?>"><?php wp_nonce_field( 'smc_verify_contact_otp', 'smc_nonce' ); ?><label><?php esc_html_e( 'Six-digit code', 'sabri-membership-core' ); ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><button class="smc-button"><?php esc_html_e( 'Verify', 'sabri-membership-core' ); ?></button></form>
			</section>
			<?php
		}
	}

	public static function handle_request_contact_otp() {
		self::guard_user_action( 'smc_request_contact_otp' );
		$user_id = get_current_user_id();
		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		if ( ! in_array( $channel, array( 'email', 'mobile' ), true ) || SMC_Contracts::contact_verified( $user_id, $channel ) || SMC_Security::rate_limited( 'contact-otp|' . $user_id . '|' . $channel, 4, HOUR_IN_SECONDS ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$user = get_userdata( $user_id );
		$app = smc_application( $user_id );
		if ( ! $user ) { self::redirect( 'status', 'invalid' ); }
		$target = 'email' === $channel ? $user->user_email : self::decrypt_phone( $app );
		if ( ! $target || is_wp_error( $target ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$code = (string) random_int( 100000, 999999 );
		$lookup = SMC_Security::blind_index( $code, 'contact-otp' );
		$target_hash = SMC_Security::blind_index( $target, 'contact-target' );
		if ( is_wp_error( $lookup ) || is_wp_error( $target_hash ) ) {
			self::redirect( 'status', 'invalid' );
		}
		global $wpdb;
		$last_created = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND verified_at IS NULL LIMIT 1", $user_id, $channel ) );
		if ( $last_created && strtotime( $last_created . ' UTC' ) > time() - 60 ) {
			self::redirect( 'status', 'cooldown' );
		}
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_contact_otps (user_id,channel,target_hash,code_lookup_hash,code_hash,attempts,expires_at,delivery_receipt_hash,delivered_at,verified_at,created_at)
			VALUES (%d,%s,%s,%s,%s,0,%s,NULL,NULL,NULL,%s)
			ON DUPLICATE KEY UPDATE target_hash=VALUES(target_hash),code_lookup_hash=VALUES(code_lookup_hash),code_hash=VALUES(code_hash),attempts=0,expires_at=VALUES(expires_at),delivery_receipt_hash=NULL,delivered_at=NULL,verified_at=NULL,created_at=VALUES(created_at)",
			$user_id, $channel, $target_hash, $lookup, wp_hash_password( $code ), gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ), $now
		);
		if ( false === $wpdb->query( $sql ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$sent = class_exists( 'SMC_Contact_Delivery' ) ? SMC_Contact_Delivery::send_otp( array( 'user_id' => $user_id, 'channel' => $channel, 'target' => $target, 'code' => $code, 'expires_in' => 600 ) ) : false;
		$accepted = true === $sent || ( is_array( $sent ) && ! empty( $sent['accepted'] ) );
		if ( ! $accepted ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s AND verified_at IS NULL", $user_id, $channel, $target_hash, $lookup ) );
			SMC_Security::audit( 'contact_otp_delivery_failed', $user_id, array( 'channel' => $channel ) );
			self::redirect( 'status', 'provider' );
		}
		$provider_receipt = is_array( $sent ) ? (string) ( $sent['receipt_id'] ?? $sent['provider_reference'] ?? '' ) : '';
		if ( '' === $provider_receipt ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s AND verified_at IS NULL", $user_id, $channel, $target_hash, $lookup ) );
			SMC_Security::audit( 'contact_otp_provider_receipt_missing', $user_id, array( 'channel' => $channel ) );
			self::redirect( 'status', 'provider' );
		}
		$receipt_hash = SMC_Security::blind_index( $provider_receipt . '|' . $channel . '|' . $target_hash . '|' . $lookup, 'contact-delivery-receipt' );
		$delivered_at = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$locked_otp = is_wp_error( $receipt_hash ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s AND verified_at IS NULL LIMIT 1 FOR UPDATE", $user_id, $channel, $target_hash, $lookup ), ARRAY_A );
		$receipt_saved = $locked_otp ? $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET delivery_receipt_hash=%s,delivered_at=%s WHERE id=%d AND verified_at IS NULL", $receipt_hash, $delivered_at, (int) $locked_otp['id'] ) ) : false;
		$delivery_audit_ok = 1 === $receipt_saved && SMC_Security::audit( 'contact_otp_delivered', $user_id, array( 'channel' => $channel, 'receipt_hash' => $receipt_hash ) );
		if ( 1 !== $receipt_saved || ! $delivery_audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s AND verified_at IS NULL", $user_id, $channel, $target_hash, $lookup ) );
			SMC_Security::audit( 'contact_otp_delivery_receipt_failed', $user_id, array( 'channel' => $channel ) );
			self::redirect( 'status', 'invalid' );
		}
		self::redirect( 'status', 'otp_sent' );
	}

	public static function handle_verify_contact_otp() {
		self::guard_user_action( 'smc_verify_contact_otp' );
		$user_id = get_current_user_id();
		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		if ( ! in_array( $channel, array( 'email', 'mobile' ), true ) || 6 !== strlen( $code ) || SMC_Security::rate_limited( 'verify-contact|' . $user_id . '|' . $channel, 7, 900 ) ) {
			self::redirect( 'status', 'invalid' );
		}
		global $wpdb;
		$lookup = SMC_Security::blind_index( $code, 'contact-otp' );
		if ( is_wp_error( $lookup ) ) { self::redirect( 'status', 'invalid' ); }
		$user = get_userdata( $user_id );
		$app = smc_application( $user_id );
		$current_target = 'email' === $channel ? ( $user ? $user->user_email : '' ) : self::decrypt_phone( $app );
		$current_target_hash = ( ! $current_target || is_wp_error( $current_target ) ) ? false : SMC_Security::blind_index( $current_target, 'contact-target' );
		if ( is_wp_error( $current_target_hash ) || ! is_string( $current_target_hash ) ) { self::redirect( 'status', 'invalid' ); }
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND code_lookup_hash=%s AND verified_at IS NULL AND delivery_receipt_hash IS NOT NULL AND delivered_at IS NOT NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE", $user_id, $channel, $lookup ), ARRAY_A );
		$target_is_current = $row && hash_equals( (string) $row['target_hash'], $current_target_hash );
		if ( ! $row || ! $target_is_current || (int) $row['attempts'] >= 7 || ! wp_check_password( $code, $row['code_hash'] ) ) {
			if ( $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET attempts=attempts+1 WHERE id=%d AND verified_at IS NULL", (int) $row['id'] ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); }
			self::redirect( 'status', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET verified_at=%s,code_hash='',code_lookup_hash='',expires_at=%s WHERE id=%d AND verified_at IS NULL AND delivery_receipt_hash IS NOT NULL AND delivered_at IS NOT NULL", $now, $now, (int) $row['id'] ) );
		$audit_ok = 1 === $ok && SMC_Security::audit( $channel . '_ownership_verified', $user_id, array( 'delivery_receipt_hash' => (string) $row['delivery_receipt_hash'] ) );
		if ( 1 !== $ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'status', 'invalid' );
		}
		self::redirect( 'status', 'otp_verified' );
	}

	private static function decrypt_phone( $app ) {
		if ( ! $app || empty( $app['phone_e164_enc'] ) ) {
			return false;
		}
		return SMC_Security::decrypt( $app['phone_e164_enc'], 'membership-phone', array( 'user_id' => (int) $app['user_id'] ) );
	}

	public static function security_shortcode() {
		$required = self::login_required();
		if ( $required ) { return $required; }
		$user_id = get_current_user_id();
		ob_start(); ?>
		<main class="smc-panel" aria-labelledby="smc-security-title"><h1 id="smc-security-title"><?php esc_html_e( 'Membership Security', 'sabri-membership-core' ); ?></h1><?php echo smc_notice( __( 'File 00 no longer uses two-factor authentication, authenticator codes or recovery codes. Normal sign-in and account recovery belong to Sabri Authentication (File 02).', 'sabri-membership-core' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p><?php esc_html_e( 'Membership session visibility and revocation remain available below.', 'sabri-membership-core' ); ?></p><?php self::session_list( $user_id ); ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_revoke_all_sessions"><?php wp_nonce_field( 'smc_revoke_all_sessions', 'smc_nonce' ); ?><label class="smc-check"><input type="checkbox" name="confirm_revoke_all" value="1" required> <?php esc_html_e( 'I understand this signs out every device, including this one.', 'sabri-membership-core' ); ?></label><button class="smc-button smc-button--danger"><?php esc_html_e( 'Revoke All Sessions', 'sabri-membership-core' ); ?></button></form></main><?php return ob_get_clean();
	}

	private static function security_event_list( $user_id ) {
		global $wpdb;
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( '' === $subject_hash ) { return; }
		$actions = array( 'two_factor_enabled','two_factor_replaced','two_factor_challenge_passed','recovery_codes_rotated','recovery_code_used','membership_session_revoked','sessions_revoked','email_ownership_verified','mobile_ownership_verified' );
		$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
		$params = array_merge( array( $subject_hash ), $actions );
		$sql = $wpdb->prepare( "SELECT action,created_at FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s AND action IN ({$placeholders}) ORDER BY id DESC LIMIT 10", $params );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		echo '<section class="smc-subpanel"><h2>' . esc_html__( 'Recent security events', 'sabri-membership-core' ) . '</h2><ul>';
		if ( $rows ) {
			foreach ( $rows as $row ) { echo '<li><code>' . esc_html( (string) $row['action'] ) . '</code> · ' . esc_html( (string) $row['created_at'] ) . '</li>'; }
		} else { echo '<li>' . esc_html__( 'No recent File 00 security events were found.', 'sabri-membership-core' ) . '</li>'; }
		echo '</ul></section>';
	}

	public static function session_list( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,token_hash,expires_at,two_factor_at,ip_hash,device_hash,created_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC LIMIT 20",
				absint( $user_id )
			),
			ARRAY_A
		);
		$current_hash = SMC_Security::blind_index( wp_get_session_token(), 'session-token' );
		echo '<section class="smc-subpanel"><h2>' . esc_html__( 'Active Membership Sessions', 'sabri-membership-core' ) . '</h2><ul>';
		foreach ( $rows as $row ) {
			$current = ! is_wp_error( $current_hash ) && hash_equals( $current_hash, $row['token_hash'] );
			echo '<li><strong>' . esc_html( $current ? __( 'Current session', 'sabri-membership-core' ) : __( 'Other session', 'sabri-membership-core' ) ) . '</strong> · ' . esc_html( $row['created_at'] ) . ' · ' . esc_html( sprintf( __( 'Device %s', 'sabri-membership-core' ), substr( $row['device_hash'], 0, 10 ) ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="smc-inline-form"><input type="hidden" name="action" value="smc_revoke_session"><input type="hidden" name="session_id" value="' . absint( $row['id'] ) . '">';
			wp_nonce_field( 'smc_revoke_session_' . $row['id'], 'smc_nonce' );
			echo '<button class="smc-button smc-button--danger">' . esc_html__( 'Revoke', 'sabri-membership-core' ) . '</button></form></li>';
		}
		if ( ! $rows ) {
			echo '<li>' . esc_html__( 'No active membership sessions were found.', 'sabri-membership-core' ) . '</li>';
		}
		echo '</ul></section>';
	}


	public static function handle_revoke_all_sessions() {
		self::guard_user_action( 'smc_revoke_all_sessions' );
		$user_id = get_current_user_id();
		if ( empty( $_POST['confirm_revoke_all'] ) ) {
			self::redirect( 'security', 'invalid' );
		}
		if ( ! SMC_Security::revoke_all_sessions( $user_id, 'user_requested_revoke_all' ) ) {
			self::redirect( 'security', 'invalid' );
		}
		wp_clear_auth_cookie();
		wp_safe_redirect( add_query_arg( 'smc_sessions_revoked', '1', home_url( '/' ) ) );
		exit;
	}

	private static function write_user_meta_verified( $user_id, $key, $value ) {
		update_user_meta( $user_id, $key, $value );
		$stored = get_user_meta( $user_id, $key, true );
		return is_scalar( $value ) && (string) $stored === (string) $value;
	}

	private static function delete_user_meta_verified( $user_id, $key ) {
		delete_user_meta( $user_id, $key );
		return ! metadata_exists( 'user', $user_id, $key );
	}

	public static function handle_revoke_session() {
		$user_id = get_current_user_id();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$id = absint( $_POST['session_id'] ?? 0 );
		check_admin_referer( 'smc_revoke_session_' . $id, 'smc_nonce' );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $id, $user_id ), ARRAY_A );
		if ( ! $row ) {
			self::redirect( 'security', 'invalid' );
		}
		$current = SMC_Security::blind_index( wp_get_session_token(), 'session-token' );
		$is_current = ! is_wp_error( $current ) && hash_equals( $current, $row['token_hash'] );
		if ( ! SMC_Security::revoke_session_by_id( $user_id, $id, 'user_requested' ) ) {
			self::redirect( 'security', 'invalid' );
		}
		if ( $is_current ) {
			wp_logout();
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		self::redirect( 'security', 'session_revoked' );
	}

	public static function handle_verify_guardian() {
		check_admin_referer( 'smc_verify_guardian', 'smc_nonce' );
		if ( ! self::request_is_same_origin() ) { wp_die( esc_html__( 'The protected request origin could not be verified.', 'sabri-membership-core' ), '', array( 'response'=>403 ) ); }
		$token = sanitize_text_field( wp_unslash( $_POST['guardian_token'] ?? '' ) );
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		$token_hash = SMC_Security::blind_index( $token, 'guardian-invitation' );
		$lookup = SMC_Security::blind_index( $code, 'guardian-otp' );
		if ( empty( $_POST['consent'] ) || is_wp_error( $token_hash ) || is_wp_error( $lookup ) || SMC_Security::rate_limited( 'guardian|' . $token_hash, 7, 900 ) ) {
			self::redirect( 'guardian', 'invalid' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_guardian_consents WHERE invitation_token_hash=%s AND status='pending' AND is_current=1 AND delivery_receipt_hash IS NOT NULL AND delivered_at IS NOT NULL AND otp_expires_at>UTC_TIMESTAMP() LIMIT 1", $token_hash ), ARRAY_A );
		$lookup_matches = $row && ! empty( $row['otp_lookup_hash'] ) && hash_equals( (string) $row['otp_lookup_hash'], (string) $lookup );
		$password_matches = $row && ! empty( $row['otp_hash'] ) && wp_check_password( $code, (string) $row['otp_hash'] );
		if ( ! $row || ! $lookup_matches || (int) $row['otp_attempts'] >= 7 || ! $password_matches ) {
			if ( $row ) {
				$attempted = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET otp_attempts=otp_attempts+1 WHERE id=%d AND status='pending' AND is_current=1", (int) $row['id'] ) );
				if ( 1 !== $attempted ) { SMC_Security::audit( 'guardian_otp_attempt_record_failed', (int) $row['user_id'] ); }
			}
			self::redirect( 'guardian', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$app = $wpdb->get_row( $wpdb->prepare( "SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status='guardian_pending' LIMIT 1 FOR UPDATE", (int) $row['user_id'] ), ARRAY_A );
			$request = $wpdb->get_row( $wpdb->prepare( "SELECT queue_type FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d AND status='guardian_pending' LIMIT 1 FOR UPDATE", (int) $row['user_id'] ), ARRAY_A );
			if ( ! $app || ! $request ) {
				throw new RuntimeException( 'Guardian applicant generation is unavailable.' );
			}
			$next_status = 'guardian_resubmission' === sanitize_key( $request['queue_type'] ?? '' ) ? 'resubmitted' : 'submitted';
			$next_queue = 'resubmitted' === $next_status ? 'resubmitted' : 'new';
			$next_applicant_version = (int) $app['row_version'] + 1;
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='verified',verified_at=%s,otp_hash=NULL,otp_lookup_hash=NULL,invitation_token_hash=NULL WHERE id=%d AND status='pending' AND is_current=1", $now, (int) $row['id'] ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=%d,updated_at=%s WHERE user_id=%d AND status='guardian_pending' AND row_version=%d", $next_status, $next_applicant_version, $now, (int) $row['user_id'], (int) $app['row_version'] ) );
			$ok3 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,queue_type=%s,applicant_version=%d,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status='guardian_pending'", $next_status, $next_queue, $next_applicant_version, $now, (int) $row['user_id'] ) );
			if ( 1 !== $ok1 || 1 !== $ok2 || 1 !== $ok3 ) {
				throw new RuntimeException( 'Guardian consent state changed concurrently.' );
			}
			self::record_consent( (int) $row['user_id'], 'guardian_membership', $row['consent_text'], 'guardian' );
			if ( ! SMC_Security::audit( 'guardian_consent_verified', (int) $row['user_id'], array( 'applicant_version' => $next_applicant_version ) ) ) {
				throw new RuntimeException( 'Guardian consent audit evidence could not be recorded.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Guardian consent verification could not be committed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			SMC_Security::audit( 'guardian_consent_transaction_failed', (int) $row['user_id'], array( 'reason' => $error->getMessage() ) );
			self::redirect( 'guardian', 'invalid' );
		}
		self::redirect( 'guardian', 'guardian' );
	}

	public static function handle_resubmit() {
		self::guard_user_action( 'smc_resubmit' );
		$user_id = get_current_user_id();
		$response = sanitize_textarea_field( wp_unslash( $_POST['response'] ?? '' ) );
		if ( 'more_information' !== smc_user_status( $user_id ) || strlen( $response ) < 10 ) {
			self::redirect( 'status', 'invalid' );
		}
		if ( ! self::transition_self_service( $user_id, 'more_information', 'resubmitted', $response ) ) {
			self::redirect( 'status', 'invalid' );
		}
		self::redirect( 'status', 'resubmitted' );
	}

	public static function handle_appeal() {
		self::guard_user_action( 'smc_appeal' );
		$user_id = get_current_user_id();
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$old = smc_user_status( $user_id );
		if ( ! in_array( $old, array( 'rejected', 'suspended' ), true ) || strlen( $reason ) < 20 || ! self::transition_self_service( $user_id, $old, 'appeal_review', $reason ) ) {
			self::redirect( 'status', 'invalid' );
		}
		self::redirect( 'status', 'appealed' );
	}

	private static function transition_self_service( $user_id, $old, $new, $note ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$app = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE",
				$user_id,
				$old
			),
			ARRAY_A
		);
		if ( ! $app ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$current_version = (int) $app['row_version'];
		$next_applicant_version = $current_version + 1;
		$queue_type = 'appeal_review' === $new ? 'appeal' : ( 'resubmitted' === $new ? 'resubmitted' : $new );
		$ok1 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=%d,updated_at=%s WHERE user_id=%d AND status=%s AND row_version=%d",
				$new,
				$next_applicant_version,
				$now,
				$user_id,
				$old,
				$current_version
			)
		);
		$ok2 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,queue_type=%s,reviewer_note=%s,assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,approval_generation=NULL,approval_snapshot_hash=NULL,applicant_version=%d,row_version=row_version+1,decided_at=NULL,updated_at=%s WHERE user_id=%d AND status=%s",
				$new,
				$queue_type,
				$note,
				$next_applicant_version,
				$now,
				$user_id,
				$old
			)
		);
		$audit_ok = 1 === $ok1 && 1 === $ok2 && SMC_Security::audit(
			'membership_' . $new,
			$user_id,
			array(
				'note'              => $note,
				'applicant_version' => $next_applicant_version,
			)
		);
		if ( 1 !== $ok1 || 1 !== $ok2 || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
	}

	public static function handle_withdraw_guardian() {
		self::guard_user_action( 'smc_withdraw_guardian' );
		$user_id = get_current_user_id();
		global $wpdb;
		$app = smc_application( $user_id );
		if ( ! $app || empty( $app['guardian_required'] ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$next_applicant_version = (int) $app['row_version'] + 1;
		$hold = array( 'operation'=>'guardian_withdrawal','target_status'=>'suspended','started_at'=>time() );
		update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );
		if ( get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { self::redirect( 'status', 'invalid' ); }
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='withdrawn',is_current=0,withdrawn_at=%s WHERE user_id=%d AND status='verified' AND is_current=1", $now, $user_id ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='suspended',row_version=%d,updated_at=%s WHERE user_id=%d AND guardian_required=1 AND row_version=%d", $next_applicant_version, $now, $user_id, (int) $app['row_version'] ) );
		$ok3 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='suspended',reviewer_note=%s,applicant_version=%d,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE user_id=%d", __( 'Guardian consent was withdrawn.', 'sabri-membership-core' ), $next_applicant_version, $now, $now, $user_id ) );
		$role_ok = 1 === $ok1 && 1 === $ok2 && 1 === $ok3 && SMC_Contracts::set_all_roles_pending( $user_id, $next_applicant_version, false );
		$audit_ok = $role_ok && SMC_Security::audit( 'guardian_consent_withdrawn', $user_id, array( 'applicant_version' => $next_applicant_version ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || 1 !== $ok3 || ! $role_ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
			clean_user_cache( $user_id );
			self::redirect( 'status', 'invalid' );
		}
		$roles_wp_ok = SMC_Contracts::sync_wordpress_roles( $user_id );
		$sessions_ok = $roles_wp_ok && SMC_Security::revoke_all_sessions( $user_id, 'guardian_consent_withdrawn' );
		if ( ! $roles_wp_ok || ! $sessions_ok ) {
			SMC_Completion::queue_effects_repair( $user_id, 'guardian_withdrawal', 'suspended', 'postcommit_effects' );
			clean_user_cache( $user_id );
			self::redirect( 'status', 'invalid' );
		}
		delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
		clean_user_cache( $user_id );
		self::redirect( 'status', 'withdrawn' );
	}

	private static function request_is_same_origin() {
		/*
		 * The WordPress nonce remains the primary CSRF control. This host check is
		 * defence-in-depth and must tolerate a site's canonical www/non-www alias
		 * and WordPress installations where Home URL and Site URL differ.
		 *
		 * Prefer Origin when the browser sends it. Only fall back to Referer when
		 * Origin is absent; requiring both headers to match caused legitimate
		 * Hostinger/proxy requests to fail even though their nonce was valid.
		 */
		$normalize_host = static function ( $host ) {
			$host = strtolower( rtrim( trim( (string) $host ), '.' ) );
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}
			return $host;
		};

		$trusted_hosts = array();
		foreach ( array( home_url( '/' ), site_url( '/' ), admin_url( '/' ) ) as $trusted_url ) {
			$trusted_host = $normalize_host( wp_parse_url( $trusted_url, PHP_URL_HOST ) );
			if ( '' !== $trusted_host ) {
				$trusted_hosts[ $trusted_host ] = true;
			}
		}
		if ( empty( $trusted_hosts ) ) {
			return false;
		}

		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$source  = '' !== $origin ? $origin : $referer;

		/* Browsers/proxies may omit both headers. The nonce below still fails
		 * closed for forged requests, so omission alone must not brick wp-admin. */
		if ( '' === $source ) {
			return true;
		}

		$scheme = strtolower( (string) wp_parse_url( $source, PHP_URL_SCHEME ) );
		$host   = $normalize_host( wp_parse_url( $source, PHP_URL_HOST ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		return isset( $trusted_hosts[ $host ] );
	}

	private static function guard_user_action( $action ) {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		/*
		 * WordPress nonces are bound to the authenticated user/session and are the
		 * authoritative CSRF gate for File 00's logged-in admin-post actions.
		 *
		 * Hostinger/reverse-proxy stacks can legitimately rewrite, suppress, or
		 * canonicalize Origin/Referer independently of WordPress Home/Site URL.
		 * Treating that defence-in-depth signal as a hard prerequisite locked real
		 * users out of 2FA and other protected actions even with a valid session
		 * nonce. Verify the nonce first and never downgrade a valid authenticated
		 * request solely because those optional browser headers disagree.
		 */
		check_admin_referer( $action, 'smc_nonce' );

		try {
			if ( ! self::request_is_same_origin() ) {
				/*
				 * Keep the host signal observable without storing raw Origin/Referer
				 * values or blocking the nonce-authenticated request. A failed audit must
				 * not turn this compatibility diagnostic back into an availability gate.
				 */
				SMC_Security::audit(
					'protected_request_origin_mismatch_nonce_valid',
					get_current_user_id(),
					array( 'action' => sanitize_key( (string) $action ) )
				);
			}
		} catch ( Throwable $diagnostic_error ) {
			// Compatibility diagnostics must never become an authenticated-action blocker.
		}
	}
}

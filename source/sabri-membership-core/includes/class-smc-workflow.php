<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Workflow {
	public static function init() {
		add_shortcode( 'smc_membership_application', array( __CLASS__, 'application_shortcode' ) );
		add_shortcode( 'smc_membership_status', array( __CLASS__, 'status_shortcode' ) );
		add_shortcode( 'smc_membership_security', array( __CLASS__, 'security_shortcode' ) );
		add_shortcode( 'smc_guardian_consent', array( __CLASS__, 'guardian_shortcode' ) );
		foreach (
			array(
				'submit_application',
				'request_contact_otp',
				'verify_contact_otp',
				'start_2fa',
				'finish_2fa',
				'challenge_2fa',
				'rotate_recovery',
				'ack_recovery_receipt',
				'revoke_session',
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
			'two_factor'     => array( __( 'Two-factor authentication is active. Save the one-time recovery codes now.', 'sabri-membership-core' ), 'success' ),
			'challenge'      => array( __( 'This session passed the two-factor challenge.', 'sabri-membership-core' ), 'success' ),
			'resubmitted'    => array( __( 'The requested information was resubmitted for review.', 'sabri-membership-core' ), 'success' ),
			'appealed'       => array( __( 'Your appeal was submitted for independent review.', 'sabri-membership-core' ), 'success' ),
			'guardian'       => array( __( 'Guardian consent was verified.', 'sabri-membership-core' ), 'success' ),
			'withdrawn'      => array( __( 'Guardian consent was withdrawn and the minor account was restricted.', 'sabri-membership-core' ), 'warning' ),
			'provider'       => array( __( 'The required verification provider is unavailable. No approval state changed.', 'sabri-membership-core' ), 'error' ),
			'invalid'        => array( __( 'The request could not be verified. Review the fields and try again.', 'sabri-membership-core' ), 'error' ),
		);
		return isset( $messages[ $key ] ) ? smc_notice( $messages[ $key ][0], $messages[ $key ][1] ) : '';
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
			<form id="smc-membership-application" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form" data-current-step="<?php echo absint( $current_step ); ?>" novalidate>
				<input type="hidden" name="action" value="smc_submit_application">
				<input type="hidden" name="submission_key" value="<?php echo esc_attr( $idempotency ); ?>">
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
					<label><?php esc_html_e( 'Private residential address', 'sabri-membership-core' ); ?><textarea name="address" maxlength="500" required autocomplete="street-address"><?php echo esc_textarea( $draft['address'] ?? '' ); ?></textarea></label>
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
					<label><?php esc_html_e( 'Document number', 'sabri-membership-core' ); ?><input name="identity_number" autocomplete="off" minlength="5" maxlength="24" required></label>
					<?php foreach ( smc_required_identity_documents() as $key => $label ) : ?>
						<label><?php echo esc_html( $label ); ?><input type="file" name="<?php echo esc_attr( $key ); ?>" accept="<?php echo 'identity_selfie' === $key ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,application/pdf'; ?>" required></label>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'Upload progress', 'sabri-membership-core' ); ?><progress id="smc-upload-progress" max="100" value="0">0%</progress></label>
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

				<div class="smc-step-controls"><button type="button" class="button" data-smc-prev><?php esc_html_e( 'Previous', 'sabri-membership-core' ); ?></button><button type="button" class="button button-primary" data-smc-next><?php esc_html_e( 'Next', 'sabri-membership-core' ); ?></button></div>
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
		$submission_key = sanitize_text_field( wp_unslash( $_POST['submission_key'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $submission_key ) ) {
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
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
			$application_version = $current ? (int) $current['row_version'] + 1 : 1;
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
			$identity = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
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
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			delete_user_meta( $user_id, $submission_receipt_key );
			SMC_Security::audit( 'application_transaction_failed', $user_id, array( 'reason' => $error->getMessage(), 'trace_id' => $trace_id ) );
			self::redirect( 'application', 'invalid', array( 'trace_id' => $trace_id ) );
		}
		$stored_documents = array();
		foreach ( smc_required_identity_documents() as $key => $label ) {
			$result = SMC_Security::store_uploaded_document( $key, $label, $user_id, $key );
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
		$status = $guardian_required ? 'guardian_pending' : 'submitted';
		if ( ! self::submit_request( $user_id, $status, array( 'age' => $age, 'type' => $type, 'role_types' => $types, 'guardian_required' => $guardian_required, 'trace_id' => $trace_id ) ) ) {
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
		if ( ! $last_key_ok && ! $receipt_ok ) {
			SMC_Security::audit( 'application_idempotency_receipt_failed', $user_id, array( 'trace_id' => $trace_id ) );
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
		if ( $previous && 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=0 WHERE id=%d AND user_id=%d AND is_current=1", (int) $previous['id'], $user_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$inserted = $wpdb->insert( $wpdb->prefix . 'smc_guardian_consents', array(
			'user_id'=>$user_id,'generation'=>$generation,'is_current'=>1,'guardian_name_enc'=>$name_enc,'guardian_email_enc'=>$email_enc,'guardian_email_hash'=>$email_hash,'guardian_phone_enc'=>$phone_enc,'guardian_phone_hash'=>$phone_hash,'relationship'=>$relationship,'legal_authority_confirmed'=>1,'status'=>'pending','consent_text'=>$consent_text,'consent_hash'=>hash('sha256',$consent_text),'policy_version'=>smc_policy()['version'],'otp_hash'=>wp_hash_password($code),'otp_lookup_hash'=>$lookup,'invitation_token_hash'=>$token_hash,'otp_attempts'=>0,'otp_expires_at'=>gmdate('Y-m-d H:i:s',time()+15*MINUTE_IN_SECONDS),'requested_at'=>$now,'verified_at'=>null,'withdrawn_at'=>null,'ip_hash'=>SMC_Security::blind_index($_SERVER['REMOTE_ADDR']??'','guardian-ip'),'device_hash'=>SMC_Security::blind_index($_SERVER['HTTP_USER_AGENT']??'','guardian-device')
		) );
		if ( 1 !== $inserted ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$consent_id = (int) $wpdb->insert_id;
		if ( ! SMC_Security::audit( 'guardian_invitation_created', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return false; }
		$sent = apply_filters( 'smc_send_guardian_invitation', false, array( 'user_id'=>$user_id,'consent_id'=>$consent_id,'generation'=>$generation,'guardian_name'=>$name,'guardian_email'=>$email,'guardian_phone'=>$phone,'code'=>$code,'link'=>$link,'expires_in'=>900 ) );
		if ( true !== $sent ) {
			$wpdb->query( 'START TRANSACTION' );
			$new_failed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='delivery_failed',is_current=0,withdrawn_at=%s WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1", current_time('mysql',true), $consent_id, $user_id ) );
			$old_restored = ! $previous || 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=1 WHERE id=%d AND user_id=%d AND is_current=0", (int) $previous['id'], $user_id ) );
			$audit_ok = $new_failed && $old_restored && SMC_Security::audit( 'guardian_invitation_delivery_failed', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) );
			if ( ! $new_failed || ! $old_restored || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); return false; }
			$wpdb->query( 'COMMIT' );
			return false;
		}
		return true;
	}

	private static function submit_request( $user_id, $status, $audit_details = array() ) {
		global $wpdb;
		$app = smc_application( $user_id );
		if ( ! $app ) {
			return false;
		}
		$audit_details = is_array( $audit_details ) ? $audit_details : array();
		$trace_id = ! empty( $audit_details['trace_id'] ) && preg_match( '/^[0-9a-f-]{36}$/i', (string) $audit_details['trace_id'] ) ? strtolower( (string) $audit_details['trace_id'] ) : wp_generate_uuid4();
		$queue_type = 'guardian_pending' === $status ? 'guardian' : ( 'resubmitted' === $status ? 'resubmitted' : 'new' );
		$submitted_roles = ! empty( $audit_details['role_types'] ) ? smc_sanitize_membership_types( $audit_details['role_types'] ) : array( $audit_details['type'] ?? 'member' );
		if ( array_intersect( $submitted_roles, smc_professional_types() ) ) {
			$queue_type = 'professional';
		}
		$now = current_time( 'mysql', true );
		$sla_hours = max( 1, absint( SMC_Completion::service_levels()['review_queue_target_hours'] ?? 72 ) );
		$sla_due = gmdate( 'Y-m-d H:i:s', time() + $sla_hours * HOUR_IN_SECONDS );
		$applicant_version = (int) $app['row_version'] + 1;
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_verification_requests
				(user_id,status,queue_type,assigned_reviewer,assigned_at,conflict_status,conflict_note,reason_code,trace_id,sla_due_at,reviewer_note,applicant_version,row_version,submitted_at,created_at,updated_at)
				VALUES (%d,%s,%s,0,NULL,'undeclared',NULL,NULL,%s,%s,NULL,%d,1,%s,%s,%s)
				ON DUPLICATE KEY UPDATE status=VALUES(status),queue_type=VALUES(queue_type),assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,reason_code=NULL,trace_id=VALUES(trace_id),sla_due_at=VALUES(sla_due_at),reviewer_note=NULL,applicant_version=VALUES(applicant_version),row_version=row_version+1,submitted_at=VALUES(submitted_at),decided_at=NULL,updated_at=VALUES(updated_at)",
				$user_id, $status, $queue_type, $trace_id, $sla_due, $applicant_version, $now, $now, $now
			)
		);
		$ok2 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,submitted_at=%s,updated_at=%s,row_version=row_version+1 WHERE user_id=%d AND row_version=%d",
				$status, $now, $now, $user_id, (int) $app['row_version']
			)
		);
		$role_ok = false !== $ok1 && 1 === $ok2 && SMC_Contracts::set_all_roles_pending( $user_id, $applicant_version );
		$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_application_submitted' );
		$audit_details['queue_type'] = $queue_type;
		$audit_details['trace_id'] = $trace_id;
		$audit_details['applicant_version'] = $applicant_version;
		$audit_ok = $sessions_ok && SMC_Security::audit( 'membership_application_submitted', $user_id, $audit_details );
		if ( false === $ok1 || 1 !== $ok2 || ! $role_ok || ! $sessions_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return false;
		}
		$wpdb->query( 'COMMIT' );
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
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-status-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-status-title"><?php esc_html_e( 'Membership Status', 'sabri-membership-core' ); ?></h1>
			<dl class="smc-status-grid">
				<div><dt><?php esc_html_e( 'Application', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( smc_statuses()[ $a['status']] ?? $a['status'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Email ownership', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['email_verified'] ? esc_html__( 'Verified', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Mobile ownership', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['phone_verified'] ? esc_html__( 'Verified', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Guardian consent', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['guardian_verified'] ? esc_html__( 'Verified or not required', 'sabri-membership-core' ) : esc_html__( 'Pending', 'sabri-membership-core' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Two-factor security', 'sabri-membership-core' ); ?></dt><dd><?php echo $a['two_factor_ready'] ? esc_html__( 'Enabled', 'sabri-membership-core' ) : esc_html__( 'Required', 'sabri-membership-core' ); ?></dd></div>
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
		if ( ! in_array( $channel, array( 'email', 'mobile' ), true ) || SMC_Security::rate_limited( 'contact-otp|' . $user_id . '|' . $channel, 4, HOUR_IN_SECONDS ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$user = get_userdata( $user_id );
		$app = smc_application( $user_id );
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
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_contact_otps (user_id,channel,target_hash,code_lookup_hash,code_hash,attempts,expires_at,verified_at,created_at)
			VALUES (%d,%s,%s,%s,%s,0,%s,NULL,%s)
			ON DUPLICATE KEY UPDATE target_hash=VALUES(target_hash),code_lookup_hash=VALUES(code_lookup_hash),code_hash=VALUES(code_hash),attempts=0,expires_at=VALUES(expires_at),verified_at=NULL,created_at=VALUES(created_at)",
			$user_id, $channel, $target_hash, $lookup, wp_hash_password( $code ), gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ), $now
		);
		if ( false === $wpdb->query( $sql ) ) {
			self::redirect( 'status', 'invalid' );
		}
		$sent = apply_filters( 'smc_send_contact_otp', false, array( 'user_id' => $user_id, 'channel' => $channel, 'target' => $target, 'code' => $code, 'expires_in' => 600 ) );
		if ( true !== $sent ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s AND verified_at IS NULL", $user_id, $channel, $target_hash, $lookup ) );
			SMC_Security::audit( 'contact_otp_delivery_failed', $user_id, array( 'channel' => $channel ) );
			self::redirect( 'status', 'provider' );
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
		$row = is_wp_error( $lookup ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND code_lookup_hash=%s AND verified_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1", $user_id, $channel, $lookup ), ARRAY_A );
		if ( ! $row || (int) $row['attempts'] >= 7 || ! wp_check_password( $code, $row['code_hash'] ) ) {
			if ( $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET attempts=attempts+1 WHERE id=%d AND verified_at IS NULL", (int) $row['id'] ) );
			}
			self::redirect( 'status', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET verified_at=%s WHERE id=%d AND verified_at IS NULL", $now, (int) $row['id'] ) );
		if ( 1 !== $ok ) {
			self::redirect( 'status', 'invalid' );
		}
		if ( ! SMC_Security::audit( $channel . '_ownership_verified', $user_id ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_contact_otps SET verified_at=NULL WHERE id=%d AND verified_at=%s", (int) $row['id'], $now ) );
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
		if ( $required ) {
			return $required;
		}
		$user_id = get_current_user_id();
		$enabled = SMC_Security::two_factor_ready( $user_id );
		$pending = get_user_meta( $user_id, '_smc_totp_pending_enc', true );
		$expires = (int) get_user_meta( $user_id, '_smc_totp_pending_expires', true );
		$secret = '';
		if ( $pending && $expires > time() ) {
			$secret = SMC_Security::decrypt( $pending, 'totp-pending', array( 'user_id' => $user_id, 'expires' => $expires ) );
			$secret = is_wp_error( $secret ) ? '' : $secret;
		}
		$receipt = self::recovery_receipt( $user_id );
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-security-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-security-title"><?php esc_html_e( 'Membership Security', 'sabri-membership-core' ); ?></h1>
			<?php if ( $receipt ) : ?><section class="smc-subpanel" role="status"><h2><?php esc_html_e( 'One-time recovery codes', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Save these now. This protected receipt remains available for five minutes or until you explicitly confirm that it was saved.', 'sabri-membership-core' ); ?></p><ol><?php foreach ( $receipt as $code ) : ?><li><code><?php echo esc_html( $code ); ?></code></li><?php endforeach; ?></ol><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smc_ack_recovery_receipt"><?php wp_nonce_field( 'smc_ack_recovery_receipt', 'smc_nonce' ); ?><button class="smc-button"><?php esc_html_e( 'I saved these recovery codes', 'sabri-membership-core' ); ?></button></form></section><?php endif; ?>
			<?php if ( ! $enabled && ! $secret ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smc_start_2fa"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><button class="smc-button"><?php esc_html_e( 'Begin Authenticator Setup', 'sabri-membership-core' ); ?></button></form>
			<?php elseif ( $secret ) : ?>
				<section class="smc-subpanel"><h2><?php esc_html_e( 'Authenticator setup', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Enter this secret in a standards-compatible authenticator, then confirm a current code.', 'sabri-membership-core' ); ?></p><p><code><?php echo esc_html( $secret ); ?></code></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-inline-form"><input type="hidden" name="action" value="smc_finish_2fa"><?php wp_nonce_field( 'smc_finish_2fa', 'smc_nonce' ); ?><label><?php esc_html_e( 'Six-digit code', 'sabri-membership-core' ); ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><button class="smc-button"><?php esc_html_e( 'Enable Two-Factor Authentication', 'sabri-membership-core' ); ?></button></form></section>
			<?php elseif ( ! SMC_Security::session_is_verified( $user_id ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-inline-form"><input type="hidden" name="action" value="smc_challenge_2fa"><?php wp_nonce_field( 'smc_challenge_2fa', 'smc_nonce' ); ?><label><?php esc_html_e( 'Authenticator or recovery code', 'sabri-membership-core' ); ?><input name="code" autocomplete="one-time-code" required></label><button class="smc-button"><?php esc_html_e( 'Verify This Session', 'sabri-membership-core' ); ?></button></form>
			<?php else : ?>
				<p><?php esc_html_e( 'This session has a current two-factor verification.', 'sabri-membership-core' ); ?></p>
				<?php self::session_list( $user_id ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_start_2fa"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><h2><?php esc_html_e( 'Replace Authenticator', 'sabri-membership-core' ); ?></h2><label><?php esc_html_e( 'Current password', 'sabri-membership-core' ); ?><input name="password" type="password" autocomplete="current-password" required></label><label><?php esc_html_e( 'Current authenticator or recovery code', 'sabri-membership-core' ); ?><input name="current_code" autocomplete="one-time-code" required></label><button class="smc-button"><?php esc_html_e( 'Start Secure Authenticator Replacement', 'sabri-membership-core' ); ?></button></form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_rotate_recovery"><?php wp_nonce_field( 'smc_rotate_recovery', 'smc_nonce' ); ?><label><?php esc_html_e( 'Current password', 'sabri-membership-core' ); ?><input name="password" type="password" autocomplete="current-password" required></label><label><?php esc_html_e( 'Current authenticator code', 'sabri-membership-core' ); ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><button class="smc-button"><?php esc_html_e( 'Replace Recovery Codes', 'sabri-membership-core' ); ?></button></form>
			<?php endif; ?>
		</main>
		<?php
		return ob_get_clean();
	}

	private static function session_list( $user_id ) {
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


	private static function write_user_meta_verified( $user_id, $key, $value ) {
		update_user_meta( $user_id, $key, $value );
		$stored = get_user_meta( $user_id, $key, true );
		return is_scalar( $value ) && (string) $stored === (string) $value;
	}

	private static function delete_user_meta_verified( $user_id, $key ) {
		delete_user_meta( $user_id, $key );
		return ! metadata_exists( 'user', $user_id, $key );
	}

	public static function handle_start_2fa() {
		self::guard_user_action( 'smc_start_2fa' );
		$user_id = get_current_user_id();
		$replacing = SMC_Security::two_factor_ready( $user_id );
		if ( $replacing ) {
			$password = (string) wp_unslash( $_POST['password'] ?? '' ); $current_code = (string) wp_unslash( $_POST['current_code'] ?? '' ); $user = wp_get_current_user();
			if ( ! SMC_Security::session_is_verified( $user_id ) || ! wp_check_password( $password, $user->user_pass, $user_id ) || ( ! SMC_Security::verify_current_factor_without_session_rotation( $user_id, $current_code ) ) ) { self::redirect( 'security', 'invalid' ); }
			$receipt = SMC_Security::create_factor_replacement_receipt( $user_id ); if ( is_wp_error( $receipt ) ) { self::redirect( 'security', 'invalid' ); }
		}
		$secret = SMC_Security::base32_secret();
		$expires = time() + 10 * MINUTE_IN_SECONDS;
		$enc = SMC_Security::encrypt( $secret, 'totp-pending', array( 'user_id' => $user_id, 'expires' => $expires ) );
		if ( is_wp_error( $enc ) ) {
			self::redirect( 'security', 'invalid' );
		}
		$enc_ok = self::write_user_meta_verified( $user_id, '_smc_totp_pending_enc', $enc );
		$expires_ok = self::write_user_meta_verified( $user_id, '_smc_totp_pending_expires', $expires );
		if ( ! $enc_ok || ! $expires_ok ) {
			self::delete_user_meta_verified( $user_id, '_smc_totp_pending_enc' );
			self::delete_user_meta_verified( $user_id, '_smc_totp_pending_expires' );
			SMC_Security::audit( 'two_factor_pending_store_failed', $user_id );
			self::redirect( 'security', 'invalid' );
		}
		self::redirect( 'security', '' );
	}

	public static function handle_finish_2fa() {
		self::guard_user_action( 'smc_finish_2fa' ); $user_id = get_current_user_id(); $code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		$pending = get_user_meta( $user_id, '_smc_totp_pending_enc', true ); $expires = absint( get_user_meta( $user_id, '_smc_totp_pending_expires', true ) );
		if ( 6 !== strlen( $code ) || ! $pending || $expires <= time() ) { self::redirect( 'security', 'invalid' ); }
		$secret = SMC_Security::decrypt( $pending, 'totp-pending', array( 'user_id'=>$user_id,'expires'=>$expires ) );
		if ( is_wp_error( $secret ) ) { self::redirect( 'security', 'invalid' ); }
		$replacement = SMC_Security::two_factor_ready( $user_id );
		$result = SMC_Security::commit_factor_enrollment_or_replacement( $user_id, $secret, $code, $replacement, array( __CLASS__, 'store_recovery_receipt' ) );
		if ( is_wp_error( $result ) || false === $result ) { self::redirect( 'security', 'invalid' ); }
		self::delete_user_meta_verified( $user_id, '_smc_totp_pending_enc' ); self::delete_user_meta_verified( $user_id, '_smc_totp_pending_expires' );
		self::redirect( 'security', '2fa_enabled' );
	}

	public static function handle_challenge_2fa() {
		self::guard_user_action( 'smc_challenge_2fa' );
		$user_id = get_current_user_id();
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$result = ctype_digit( $code ) ? SMC_Security::verify_two_factor_challenge( $user_id, $code ) : false;
		if ( is_wp_error( $result ) || false === $result ) {
			if ( ! SMC_Security::consume_recovery_code_for_session( $user_id, $code ) ) {
				self::redirect( 'security', 'invalid' );
			}
		}
		self::redirect( 'security', 'challenge' );
	}

	public static function handle_rotate_recovery() {
		self::guard_user_action( 'smc_rotate_recovery' );
		$user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		$password = (string) wp_unslash( $_POST['password'] ?? '' );
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		$challenge = $user ? SMC_Security::verify_two_factor_challenge( $user_id, $code ) : false;
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) || true !== $challenge ) {
			self::redirect( 'security', 'invalid' );
		}
		$codes = SMC_Security::recovery_codes(
			$user_id,
			8,
			static function ( $generated_codes ) use ( $user_id ) {
				return self::store_recovery_receipt( $user_id, $generated_codes );
			}
		);
		if ( is_wp_error( $codes ) ) {
			self::redirect( 'security', 'invalid' );
		}
		self::redirect( 'security', 'two_factor' );
	}

	public static function handle_revoke_session() {
		$user_id = get_current_user_id();
		if ( ! is_user_logged_in() || ! SMC_Security::session_is_verified( $user_id ) ) {
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
		self::redirect( 'security', 'challenge' );
	}

	private static function store_recovery_receipt( $user_id, $codes ) {
		$expires = time() + 5 * MINUTE_IN_SECONDS;
		$enc = SMC_Security::encrypt( wp_json_encode( $codes ), 'recovery-receipt', array( 'user_id' => $user_id, 'expires' => $expires ) );
		if ( is_wp_error( $enc ) ) {
			return false;
		}
		$receipt = array( 'version' => 2, 'expires' => $expires, 'envelope' => $enc );
		update_user_meta( $user_id, '_smc_recovery_receipt_v2', $receipt );
		$stored = get_user_meta( $user_id, '_smc_recovery_receipt_v2', true );
		return is_array( $stored ) && (int) ( $stored['expires'] ?? 0 ) === $expires && hash_equals( $enc, (string) ( $stored['envelope'] ?? '' ) );
	}

	private static function recovery_receipt( $user_id ) {
		$receipt = get_user_meta( $user_id, '_smc_recovery_receipt_v2', true );
		if ( ! is_array( $receipt ) ) {
			$legacy_expires = (int) get_user_meta( $user_id, '_smc_recovery_receipt_expires', true );
			$legacy_enc = get_user_meta( $user_id, '_smc_recovery_receipt', true );
			if ( $legacy_enc && $legacy_expires > 0 ) {
				$receipt = array( 'version' => 2, 'expires' => $legacy_expires, 'envelope' => $legacy_enc );
				update_user_meta( $user_id, '_smc_recovery_receipt_v2', $receipt );
				$stored = get_user_meta( $user_id, '_smc_recovery_receipt_v2', true );
				if ( ! is_array( $stored ) || (string) ( $stored['envelope'] ?? '' ) !== (string) $legacy_enc ) {
					return array();
				}
				$legacy_receipt_deleted = self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt' );
				$legacy_expiry_deleted = self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_expires' );
				if ( ! $legacy_receipt_deleted || ! $legacy_expiry_deleted ) {
					self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' );
					return array();
				}
			}
		}
		if ( ! is_array( $receipt ) || empty( $receipt['envelope'] ) || empty( $receipt['expires'] ) ) {
			return array();
		}
		$expires = (int) $receipt['expires'];
		if ( $expires < time() ) {
			self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' );
			return array();
		}
		$json = SMC_Security::decrypt( $receipt['envelope'], 'recovery-receipt', array( 'user_id' => $user_id, 'expires' => $expires ) );
		if ( is_wp_error( $json ) ) {
			return array();
		}
		$codes = json_decode( $json, true );
		if ( ! is_array( $codes ) ) { return array(); }
		return $codes;
	}


	public static function handle_ack_recovery_receipt() {
		self::guard_user_action( 'smc_ack_recovery_receipt' );
		$user_id = get_current_user_id();
		$receipt = get_user_meta( $user_id, '_smc_recovery_receipt_v2', true );
		if ( ! is_array( $receipt ) || empty( $receipt['envelope'] ) || absint( $receipt['expires'] ?? 0 ) < time() ) { self::redirect( 'security', 'invalid' ); }
		if ( ! SMC_Security::audit( 'recovery_codes_receipt_acknowledged', $user_id, array( 'receipt_version'=>(int)($receipt['version']??0) ) ) ) { self::redirect( 'security', 'invalid' ); }
		if ( ! self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' ) ) { self::redirect( 'security', 'invalid' ); }
		self::redirect( 'security', 'challenge' );
	}

	public static function guardian_shortcode() {
		$token = isset( $_GET['guardian_token'] ) ? sanitize_text_field( wp_unslash( $_GET['guardian_token'] ) ) : '';
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-guardian-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-guardian-title"><?php esc_html_e( 'Verified Guardian Consent', 'sabri-membership-core' ); ?></h1>
			<p><?php esc_html_e( 'Only a parent or legally authorized guardian may complete this independent verification.', 'sabri-membership-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_verify_guardian"><input type="hidden" name="guardian_token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'smc_verify_guardian', 'smc_nonce' ); ?><label><?php esc_html_e( 'Six-digit guardian code', 'sabri-membership-core' ); ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><label class="smc-check"><input type="checkbox" name="consent" value="1" required> <?php esc_html_e( 'I am the named parent or lawful guardian, I have legal authority, and I give the stated consent.', 'sabri-membership-core' ); ?></label><button class="smc-button"><?php esc_html_e( 'Verify and Consent', 'sabri-membership-core' ); ?></button></form>
		</main>
		<?php
		return ob_get_clean();
	}

	public static function handle_verify_guardian() {
		check_admin_referer( 'smc_verify_guardian', 'smc_nonce' );
		$token = sanitize_text_field( wp_unslash( $_POST['guardian_token'] ?? '' ) );
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		$token_hash = SMC_Security::blind_index( $token, 'guardian-invitation' );
		$lookup = SMC_Security::blind_index( $code, 'guardian-otp' );
		if ( empty( $_POST['consent'] ) || is_wp_error( $token_hash ) || is_wp_error( $lookup ) || SMC_Security::rate_limited( 'guardian|' . $token_hash, 7, 900 ) ) {
			self::redirect( 'guardian', 'invalid' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_guardian_consents WHERE invitation_token_hash=%s AND otp_lookup_hash=%s AND status='pending' AND otp_expires_at>UTC_TIMESTAMP() LIMIT 1", $token_hash, $lookup ), ARRAY_A );
		if ( ! $row || (int) $row['otp_attempts'] >= 7 || ! wp_check_password( $code, $row['otp_hash'] ) ) {
			if ( $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET otp_attempts=otp_attempts+1 WHERE id=%d AND status='pending'", (int) $row['id'] ) );
			}
			self::redirect( 'guardian', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$app = $wpdb->get_row( $wpdb->prepare( "SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status='guardian_pending' LIMIT 1 FOR UPDATE", (int) $row['user_id'] ), ARRAY_A );
			if ( ! $app ) {
				throw new RuntimeException( 'Guardian applicant generation is unavailable.' );
			}
			$next_applicant_version = (int) $app['row_version'] + 1;
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='verified',verified_at=%s,otp_hash=NULL,otp_lookup_hash=NULL,invitation_token_hash=NULL WHERE id=%d AND status='pending'", $now, (int) $row['id'] ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='submitted',row_version=%d,updated_at=%s WHERE user_id=%d AND status='guardian_pending' AND row_version=%d", $next_applicant_version, $now, (int) $row['user_id'], (int) $app['row_version'] ) );
			$ok3 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='submitted',applicant_version=%d,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status='guardian_pending'", $next_applicant_version, $now, (int) $row['user_id'] ) );
			if ( 1 !== $ok1 || 1 !== $ok2 || 1 !== $ok3 ) {
				throw new RuntimeException( 'Guardian consent state changed concurrently.' );
			}
			self::record_consent( (int) $row['user_id'], 'guardian_membership', $row['consent_text'], 'guardian' );
			if ( ! SMC_Security::audit( 'guardian_consent_verified', (int) $row['user_id'], array( 'applicant_version' => $next_applicant_version ) ) ) {
				throw new RuntimeException( 'Guardian consent audit evidence could not be recorded.' );
			}
			$wpdb->query( 'COMMIT' );
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
				"UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,reviewer_note=%s,assigned_reviewer=0,applicant_version=%d,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s",
				$new,
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
		$wpdb->query( 'COMMIT' );
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
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='withdrawn',withdrawn_at=%s WHERE user_id=%d AND status='verified'", $now, $user_id ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='suspended',row_version=%d,updated_at=%s WHERE user_id=%d AND guardian_required=1 AND row_version=%d", $next_applicant_version, $now, $user_id, (int) $app['row_version'] ) );
		$ok3 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='suspended',reviewer_note=%s,applicant_version=%d,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE user_id=%d", __( 'Guardian consent was withdrawn.', 'sabri-membership-core' ), $next_applicant_version, $now, $now, $user_id ) );
		$role_ok = 1 === $ok1 && 1 === $ok2 && 1 === $ok3 && SMC_Contracts::set_all_roles_pending( $user_id, $next_applicant_version );
		$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'guardian_consent_withdrawn' );
		$audit_ok = $sessions_ok && SMC_Security::audit( 'guardian_consent_withdrawn', $user_id, array( 'applicant_version' => $next_applicant_version ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || 1 !== $ok3 || ! $role_ok || ! $sessions_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			self::redirect( 'status', 'invalid' );
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
		self::redirect( 'status', 'withdrawn' );
	}

	private static function guard_user_action( $action ) {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( $action, 'smc_nonce' );
	}
}

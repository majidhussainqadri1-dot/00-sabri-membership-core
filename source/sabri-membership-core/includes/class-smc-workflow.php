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
		if ( $row && ! in_array( $row['status'], array( 'draft', 'more_information', 'rejected' ), true ) ) {
			return self::message() . smc_notice( __( 'The application is already in a controlled review state. Use Membership Status for the next permitted action.', 'sabri-membership-core' ) );
		}
		$user = wp_get_current_user();
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-application-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-application-title"><?php esc_html_e( 'Membership Application', 'sabri-membership-core' ); ?></h1>
			<p><?php esc_html_e( 'Sabri Authentication owns sign-in. This form records only membership eligibility, identity assurance, and guardian consent.', 'sabri-membership-core' ); ?></p>
			<p class="screen-reader-text" aria-live="polite"><?php esc_html_e( 'Age and guardian eligibility guidance will be announced when the relevant fields change.', 'sabri-membership-core' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form">
				<input type="hidden" name="action" value="smc_submit_application">
				<?php wp_nonce_field( 'smc_submit_application', 'smc_nonce' ); ?>
				<label><?php esc_html_e( 'Legal name', 'sabri-membership-core' ); ?><input name="legal_name" value="<?php echo esc_attr( $row['legal_name'] ?? $user->display_name ); ?>" required maxlength="190"></label>
				<label><?php esc_html_e( 'Date of birth', 'sabri-membership-core' ); ?><input name="date_of_birth" type="date" required></label>
				<label><?php esc_html_e( 'Gender for the approved minimum-age rule', 'sabri-membership-core' ); ?><select name="gender" required><option value=""><?php esc_html_e( 'Select', 'sabri-membership-core' ); ?></option><?php foreach ( smc_allowed_genders() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'International phone number', 'sabri-membership-core' ); ?><input name="phone" type="tel" placeholder="+923001234567" required></label>
				<label><?php esc_html_e( 'Membership type', 'sabri-membership-core' ); ?><select name="membership_type" required><?php foreach ( smc_account_types() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['membership_type'] ?? 'member', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<fieldset><legend><?php esc_html_e( 'Government identity', 'sabri-membership-core' ); ?></legend>
					<label><?php esc_html_e( 'Document type', 'sabri-membership-core' ); ?><select name="identity_type"><option value="national_id"><?php esc_html_e( 'National identity card', 'sabri-membership-core' ); ?></option><option value="passport"><?php esc_html_e( 'Passport', 'sabri-membership-core' ); ?></option></select></label>
					<label><?php esc_html_e( 'Issuing country (ISO two-letter code)', 'sabri-membership-core' ); ?><input name="issuing_country" pattern="[A-Za-z]{2}" maxlength="2" required></label>
					<label><?php esc_html_e( 'Document number', 'sabri-membership-core' ); ?><input name="identity_number" autocomplete="off" minlength="5" maxlength="24" required></label>
				</fieldset>
				<fieldset><legend><?php esc_html_e( 'Private identity evidence', 'sabri-membership-core' ); ?></legend>
					<?php foreach ( smc_required_identity_documents() as $key => $label ) : ?>
						<label><?php echo esc_html( $label ); ?><input type="file" name="<?php echo esc_attr( $key ); ?>" accept="<?php echo 'identity_selfie' === $key ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,application/pdf'; ?>" required></label>
					<?php endforeach; ?>
					<p><?php esc_html_e( 'Evidence is malware-scanned, authenticated, encrypted, and stored outside the public web root. PDF evidence is download-only for reviewers.', 'sabri-membership-core' ); ?></p>
				</fieldset>
				<fieldset><legend><?php esc_html_e( 'Guardian details — required only when the applicant is under 18', 'sabri-membership-core' ); ?></legend>
					<label><?php esc_html_e( 'Guardian legal name', 'sabri-membership-core' ); ?><input name="guardian_name" maxlength="190"></label>
					<label><?php esc_html_e( 'Guardian relationship', 'sabri-membership-core' ); ?><select name="guardian_relationship"><option value=""><?php esc_html_e( 'Select', 'sabri-membership-core' ); ?></option><option value="parent"><?php esc_html_e( 'Parent', 'sabri-membership-core' ); ?></option><option value="legal_guardian"><?php esc_html_e( 'Court-appointed legal guardian', 'sabri-membership-core' ); ?></option></select></label>
					<label><?php esc_html_e( 'Guardian email', 'sabri-membership-core' ); ?><input name="guardian_email" type="email"></label>
					<label><?php esc_html_e( 'Guardian international phone', 'sabri-membership-core' ); ?><input name="guardian_phone" type="tel" placeholder="+923001234567"></label>
					<label class="smc-check"><input type="checkbox" name="guardian_authority" value="1"> <?php esc_html_e( 'The named adult has legal authority to consent for this applicant.', 'sabri-membership-core' ); ?></label>
				</fieldset>
				<label class="smc-check"><input type="checkbox" name="truth" value="1" required> <?php esc_html_e( 'I declare that the submitted identity information is true and belongs to me.', 'sabri-membership-core' ); ?></label>
				<label class="smc-check"><input type="checkbox" name="privacy" value="1" required> <?php esc_html_e( 'I consent to the stated identity-verification processing and retention policy.', 'sabri-membership-core' ); ?></label>
				<button class="smc-button" type="submit"><?php esc_html_e( 'Submit Membership Application', 'sabri-membership-core' ); ?></button>
			</form>
			<noscript><p class="smc-notice smc-notice--info"><?php esc_html_e( 'JavaScript is not required; this complete server-rendered form remains usable.', 'sabri-membership-core' ); ?></p></noscript>
		</main>
		<?php
		return ob_get_clean();
	}

	public static function handle_submit_application() {
		self::guard_user_action( 'smc_submit_application' );
		$user_id = get_current_user_id();
		if ( SMC_Security::rate_limited( 'application|' . $user_id, 5, HOUR_IN_SECONDS ) || ! SMC_Security::key_ready() ) {
			self::redirect( 'application', 'invalid' );
		}
		$legal_name = sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) );
		$dob = sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) );
		$gender = sanitize_key( wp_unslash( $_POST['gender'] ?? '' ) );
		$type = sanitize_key( wp_unslash( $_POST['membership_type'] ?? '' ) );
		$phone = smc_normalize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		$id_type = sanitize_key( wp_unslash( $_POST['identity_type'] ?? '' ) );
		$id_number = strtoupper( sanitize_text_field( wp_unslash( $_POST['identity_number'] ?? '' ) ) );
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['issuing_country'] ?? '' ) ) );
		$age = smc_age_from_dob( $dob );
		if (
			'' === $legal_name || false === $age || ! isset( smc_allowed_genders()[ $gender ] ) ||
			! isset( smc_account_types()[ $type ] ) || is_wp_error( $phone ) ||
			! in_array( $id_type, array( 'national_id', 'passport' ), true ) ||
			! preg_match( '/^[A-Z]{2}$/', $country ) ||
			! preg_match( '/^[A-Z0-9][A-Z0-9 -]{4,23}$/', $id_number ) ||
			$age < smc_minimum_age_for_gender( $gender ) ||
			( $age < 18 && smc_is_professional_type( $type ) ) ||
			empty( $_POST['truth'] ) || empty( $_POST['privacy'] )
		) {
			self::redirect( 'application', 'invalid' );
		}
		$id_number = apply_filters( 'smc_validate_identity_number', $id_number, $id_type, $country );
		if ( is_wp_error( $id_number ) ) {
			self::redirect( 'application', 'invalid' );
		}
		$phone_hash = SMC_Security::blind_index( $phone, 'phone' );
		$id_hash = SMC_Security::blind_index( $country . '|' . $id_type . '|' . $id_number, 'identity-number' );
		if ( is_wp_error( $phone_hash ) || is_wp_error( $id_hash ) ) {
			self::redirect( 'application', 'invalid' );
		}
		global $wpdb;
		$duplicate_phone = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_applications WHERE phone_hash=%s AND user_id<>%d", $phone_hash, $user_id ) );
		$duplicate_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_identity_records WHERE document_number_hash=%s AND user_id<>%d", $id_hash, $user_id ) );
		if ( $duplicate_phone || $duplicate_id ) {
			self::redirect( 'application', 'invalid' );
		}
		$dob_enc = SMC_Security::encrypt( $dob, 'date-of-birth', array( 'user_id' => $user_id ) );
		$phone_enc = SMC_Security::encrypt( $phone, 'membership-phone', array( 'user_id' => $user_id ) );
		$id_enc = SMC_Security::encrypt( $id_number, 'identity-number', array( 'user_id' => $user_id, 'type' => $id_type, 'country' => $country ) );
		if ( is_wp_error( $dob_enc ) || is_wp_error( $phone_enc ) || is_wp_error( $id_enc ) ) {
			self::redirect( 'application', 'invalid' );
		}
		$guardian_required = $age < 18 ? 1 : 0;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$current = smc_application( $user_id );
			$app_data = array(
				'legal_name'         => $legal_name,
				'date_of_birth_enc'  => $dob_enc,
				'gender'             => $gender,
				'phone_e164_enc'     => $phone_enc,
				'phone_hash'         => $phone_hash,
				'membership_type'    => $type,
				'status'             => 'draft',
				'guardian_required'  => $guardian_required,
				'profile_visibility' => 'private',
				'policy_version'     => smc_policy()['version'],
				'row_version'        => $current ? (int) $current['row_version'] + 1 : 1,
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
			$identity = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d", $user_id ), ARRAY_A );
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
			if ( false === $ok ) {
				throw new RuntimeException( 'Identity record database failure.' );
			}
			self::record_consent( $user_id, 'identity_verification', __( 'I consent to identity verification, protected evidence review, documented retention, and the published privacy process.', 'sabri-membership-core' ), 'self' );
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			SMC_Security::audit( 'application_transaction_failed', $user_id, array( 'reason' => $error->getMessage() ) );
			self::redirect( 'application', 'invalid' );
		}
		foreach ( smc_required_identity_documents() as $key => $label ) {
			$result = SMC_Security::store_uploaded_document( $key, $label, $user_id, $key );
			if ( is_wp_error( $result ) ) {
				SMC_Security::audit( 'application_document_incomplete', $user_id, array( 'document_key' => $key, 'reason' => $result->get_error_message() ) );
				self::redirect( 'application', 'invalid' );
			}
		}
		if ( $guardian_required && ! self::create_guardian_invitation( $user_id ) ) {
			self::redirect( 'application', 'provider' );
		}
		$status = $guardian_required ? 'guardian_pending' : 'submitted';
		if ( ! self::submit_request( $user_id, $status ) ) {
			self::redirect( 'application', 'invalid' );
		}
		SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( $type, false ) );
		SMC_Security::revoke_all_sessions( $user_id, 'membership_application_submitted' );
		SMC_Security::audit( 'membership_application_submitted', $user_id, array( 'age' => $age, 'type' => $type, 'guardian_required' => $guardian_required ) );
		self::redirect( 'status', 'saved' );
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
		if ( ! $name || ! is_email( $email ) || is_wp_error( $phone ) || ! in_array( $relationship, array( 'parent', 'legal_guardian' ), true ) || empty( $_POST['guardian_authority'] ) ) {
			return false;
		}
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
		foreach ( array( $name_enc, $email_enc, $phone_enc, $email_hash, $phone_hash, $lookup, $token_hash ) as $value ) {
			if ( is_wp_error( $value ) ) {
				return false;
			}
		}
		$text = __( 'I confirm that I am the parent or lawful guardian, consent to this minor using the platform under its published rules, and understand that I may withdraw consent.', 'sabri-membership-core' );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_guardian_consents
			(user_id,guardian_name_enc,guardian_email_enc,guardian_email_hash,guardian_phone_enc,guardian_phone_hash,relationship,legal_authority_confirmed,status,consent_text,consent_hash,policy_version,otp_hash,otp_lookup_hash,invitation_token_hash,otp_attempts,otp_expires_at,requested_at,ip_hash,device_hash)
			VALUES (%d,%s,%s,%s,%s,%s,%s,1,'pending',%s,%s,%s,%s,%s,%s,0,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE guardian_name_enc=VALUES(guardian_name_enc),guardian_email_enc=VALUES(guardian_email_enc),guardian_email_hash=VALUES(guardian_email_hash),guardian_phone_enc=VALUES(guardian_phone_enc),guardian_phone_hash=VALUES(guardian_phone_hash),relationship=VALUES(relationship),legal_authority_confirmed=1,status='pending',consent_text=VALUES(consent_text),consent_hash=VALUES(consent_hash),policy_version=VALUES(policy_version),otp_hash=VALUES(otp_hash),otp_lookup_hash=VALUES(otp_lookup_hash),invitation_token_hash=VALUES(invitation_token_hash),otp_attempts=0,otp_expires_at=VALUES(otp_expires_at),requested_at=VALUES(requested_at),verified_at=NULL,withdrawn_at=NULL,ip_hash=VALUES(ip_hash),device_hash=VALUES(device_hash)",
			$user_id, $name_enc, $email_enc, $email_hash, $phone_enc, $phone_hash, $relationship, $text, hash( 'sha256', $text ), smc_policy()['version'], wp_hash_password( $code ), $lookup, $token_hash, gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ), $now, hash( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) ), hash( 'sha256', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) . wp_salt( 'nonce' ) )
		);
		if ( false === $wpdb->query( $sql ) ) {
			return false;
		}
		$link = add_query_arg( 'guardian_token', rawurlencode( $token ), smc_page_url( 'guardian', '/guardian-consent/' ) );
		$sent = apply_filters( 'smc_send_guardian_invitation', false, array( 'user_id' => $user_id, 'guardian_name' => $name, 'guardian_email' => $email, 'guardian_phone' => $phone, 'code' => $code, 'link' => $link, 'expires_in' => 900 ) );
		return true === $sent;
	}

	private static function submit_request( $user_id, $status ) {
		global $wpdb;
		$app = smc_application( $user_id );
		if ( ! $app ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_verification_requests
				(user_id,status,assigned_reviewer,reviewer_note,applicant_version,row_version,submitted_at,created_at,updated_at)
				VALUES (%d,%s,0,NULL,%d,1,%s,%s,%s)
				ON DUPLICATE KEY UPDATE status=VALUES(status),assigned_reviewer=0,reviewer_note=NULL,applicant_version=VALUES(applicant_version),row_version=row_version+1,submitted_at=VALUES(submitted_at),decided_at=NULL,updated_at=VALUES(updated_at)",
				$user_id, $status, (int) $app['row_version'], $now, $now, $now
			)
		);
		$ok2 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,submitted_at=%s,updated_at=%s,row_version=row_version+1 WHERE user_id=%d AND row_version=%d",
				$status, $now, $now, $user_id, (int) $app['row_version']
			)
		);
		if ( false === $ok1 || 1 !== $ok2 ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
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
		update_user_meta( $user_id, '_smc_' . ( 'email' === $channel ? 'email' : 'mobile' ) . '_verified', 1 );
		SMC_Security::audit( $channel . '_ownership_verified', $user_id );
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
			<?php if ( $receipt ) : ?><section class="smc-subpanel" role="status"><h2><?php esc_html_e( 'One-time recovery codes', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Save these now. They will not be displayed again.', 'sabri-membership-core' ); ?></p><ol><?php foreach ( $receipt as $code ) : ?><li><code><?php echo esc_html( $code ); ?></code></li><?php endforeach; ?></ol></section><?php endif; ?>
			<?php if ( ! $enabled && ! $secret ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smc_start_2fa"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><button class="smc-button"><?php esc_html_e( 'Begin Authenticator Setup', 'sabri-membership-core' ); ?></button></form>
			<?php elseif ( ! $enabled ) : ?>
				<section class="smc-subpanel"><h2><?php esc_html_e( 'Authenticator setup', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Enter this secret in a standards-compatible authenticator, then confirm a current code.', 'sabri-membership-core' ); ?></p><p><code><?php echo esc_html( $secret ); ?></code></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-inline-form"><input type="hidden" name="action" value="smc_finish_2fa"><?php wp_nonce_field( 'smc_finish_2fa', 'smc_nonce' ); ?><label><?php esc_html_e( 'Six-digit code', 'sabri-membership-core' ); ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><button class="smc-button"><?php esc_html_e( 'Enable Two-Factor Authentication', 'sabri-membership-core' ); ?></button></form></section>
			<?php elseif ( ! SMC_Security::session_is_verified( $user_id ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-inline-form"><input type="hidden" name="action" value="smc_challenge_2fa"><?php wp_nonce_field( 'smc_challenge_2fa', 'smc_nonce' ); ?><label><?php esc_html_e( 'Authenticator or recovery code', 'sabri-membership-core' ); ?><input name="code" autocomplete="one-time-code" required></label><button class="smc-button"><?php esc_html_e( 'Verify This Session', 'sabri-membership-core' ); ?></button></form>
			<?php else : ?>
				<p><?php esc_html_e( 'This session has a current two-factor verification.', 'sabri-membership-core' ); ?></p>
				<?php self::session_list( $user_id ); ?>
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

	public static function handle_start_2fa() {
		self::guard_user_action( 'smc_start_2fa' );
		$user_id = get_current_user_id();
		$secret = SMC_Security::base32_secret();
		$expires = time() + 10 * MINUTE_IN_SECONDS;
		$enc = SMC_Security::encrypt( $secret, 'totp-pending', array( 'user_id' => $user_id, 'expires' => $expires ) );
		if ( is_wp_error( $enc ) ) {
			self::redirect( 'security', 'invalid' );
		}
		update_user_meta( $user_id, '_smc_totp_pending_enc', $enc );
		update_user_meta( $user_id, '_smc_totp_pending_expires', $expires );
		self::redirect( 'security', '' );
	}

	public static function handle_finish_2fa() {
		self::guard_user_action( 'smc_finish_2fa' );
		$user_id = get_current_user_id();
		$expires = (int) get_user_meta( $user_id, '_smc_totp_pending_expires', true );
		$enc = get_user_meta( $user_id, '_smc_totp_pending_enc', true );
		$secret = $expires > time() ? SMC_Security::decrypt( $enc, 'totp-pending', array( 'user_id' => $user_id, 'expires' => $expires ) ) : false;
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		if ( ! is_string( $secret ) || ! SMC_Security::verify_setup_code( $secret, $code ) ) {
			self::redirect( 'security', 'invalid' );
		}
		$saved = SMC_Security::set_two_factor_secret( $user_id, $secret );
		if ( is_wp_error( $saved ) ) {
			self::redirect( 'security', 'invalid' );
		}
		update_user_meta( $user_id, '_smc_2fa_enabled', 1 );
		delete_user_meta( $user_id, '_smc_totp_pending_enc' );
		delete_user_meta( $user_id, '_smc_totp_pending_expires' );
		$codes = SMC_Security::recovery_codes( $user_id );
		if ( is_wp_error( $codes ) || ! self::store_recovery_receipt( $user_id, $codes ) ) {
			self::redirect( 'security', 'invalid' );
		}
		SMC_Security::verify_two_factor_challenge( $user_id, $code );
		self::redirect( 'security', 'two_factor' );
	}

	public static function handle_challenge_2fa() {
		self::guard_user_action( 'smc_challenge_2fa' );
		$user_id = get_current_user_id();
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$result = ctype_digit( $code ) ? SMC_Security::verify_two_factor_challenge( $user_id, $code ) : false;
		if ( is_wp_error( $result ) || false === $result ) {
			if ( ! SMC_Security::consume_recovery_code( $user_id, $code ) ) {
				self::redirect( 'security', 'invalid' );
			}
			self::mark_recovery_session_verified( $user_id );
		}
		self::redirect( 'security', 'challenge' );
	}

	private static function mark_recovery_session_verified( $user_id ) {
		global $wpdb;
		$token_hash = SMC_Security::blind_index( wp_get_session_token(), 'session-token' );
		if ( ! is_wp_error( $token_hash ) ) {
			$wpdb->update( $wpdb->prefix . 'smc_auth_sessions', array( 'two_factor_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => $user_id, 'token_hash' => $token_hash, 'revoked_at' => null ) );
		}
	}

	public static function handle_rotate_recovery() {
		self::guard_user_action( 'smc_rotate_recovery' );
		$user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		$password = (string) wp_unslash( $_POST['password'] ?? '' );
		$code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) || is_wp_error( SMC_Security::verify_two_factor_challenge( $user_id, $code ) ) ) {
			self::redirect( 'security', 'invalid' );
		}
		$codes = SMC_Security::recovery_codes( $user_id );
		if ( is_wp_error( $codes ) || ! self::store_recovery_receipt( $user_id, $codes ) ) {
			self::redirect( 'security', 'invalid' );
		}
		self::redirect( 'security', 'two_factor' );
	}

	public static function handle_revoke_session() {
		if ( ! is_user_logged_in() || ! SMC_Security::session_is_verified( get_current_user_id() ) ) {
			auth_redirect();
		}
		$id = absint( $_POST['session_id'] ?? 0 );
		check_admin_referer( 'smc_revoke_session_' . $id, 'smc_nonce' );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_auth_sessions WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $id, get_current_user_id() ), ARRAY_A );
		if ( ! $row ) {
			self::redirect( 'security', 'invalid' );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE id=%d AND revoked_at IS NULL", $now, $now, $id ) );
		if ( 1 !== $updated ) {
			self::redirect( 'security', 'invalid' );
		}
		SMC_Security::audit( 'membership_session_revoked', get_current_user_id(), array( 'session_id' => $id ) );
		$current = SMC_Security::blind_index( wp_get_session_token(), 'session-token' );
		if ( ! is_wp_error( $current ) && hash_equals( $current, $row['token_hash'] ) ) {
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
		update_user_meta( $user_id, '_smc_recovery_receipt', $enc );
		update_user_meta( $user_id, '_smc_recovery_receipt_expires', $expires );
		return true;
	}

	private static function recovery_receipt( $user_id ) {
		$expires = (int) get_user_meta( $user_id, '_smc_recovery_receipt_expires', true );
		$enc = get_user_meta( $user_id, '_smc_recovery_receipt', true );
		delete_user_meta( $user_id, '_smc_recovery_receipt' );
		delete_user_meta( $user_id, '_smc_recovery_receipt_expires' );
		if ( ! $enc || $expires < time() ) {
			return array();
		}
		$json = SMC_Security::decrypt( $enc, 'recovery-receipt', array( 'user_id' => $user_id, 'expires' => $expires ) );
		$codes = is_wp_error( $json ) ? array() : json_decode( $json, true );
		return is_array( $codes ) ? $codes : array();
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
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='verified',verified_at=%s,otp_hash=NULL,otp_lookup_hash=NULL,invitation_token_hash=NULL WHERE id=%d AND status='pending'", $now, (int) $row['id'] ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='submitted',row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status='guardian_pending'", $now, (int) $row['user_id'] ) );
			$ok3 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='submitted',row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status='guardian_pending'", $now, (int) $row['user_id'] ) );
			if ( 1 !== $ok1 || 1 !== $ok2 || 1 !== $ok3 ) {
				throw new RuntimeException( 'Guardian consent state changed concurrently.' );
			}
			self::record_consent( (int) $row['user_id'], 'guardian_membership', $row['consent_text'], 'guardian' );
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			SMC_Security::audit( 'guardian_consent_transaction_failed', (int) $row['user_id'], array( 'reason' => $error->getMessage() ) );
			self::redirect( 'guardian', 'invalid' );
		}
		SMC_Security::audit( 'guardian_consent_verified', (int) $row['user_id'] );
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
		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s", $new, $now, $user_id, $old ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,reviewer_note=%s,assigned_reviewer=0,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s", $new, $note, $now, $user_id, $old ) );
		if ( 1 !== $ok1 || 1 !== $ok2 ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		SMC_Security::audit( 'membership_' . $new, $user_id, array( 'note' => $note ) );
		return true;
	}

	public static function handle_withdraw_guardian() {
		self::guard_user_action( 'smc_withdraw_guardian' );
		$user_id = get_current_user_id();
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='withdrawn',withdrawn_at=%s WHERE user_id=%d AND status='verified'", $now, $user_id ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='suspended',row_version=row_version+1,updated_at=%s WHERE user_id=%d AND guardian_required=1", $now, $user_id ) );
		if ( 1 !== $ok1 || 1 !== $ok2 ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'status', 'invalid' );
		}
		$wpdb->query( 'COMMIT' );
		SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( smc_application( $user_id )['membership_type'], false ) );
		SMC_Security::revoke_all_sessions( $user_id, 'guardian_consent_withdrawn' );
		SMC_Security::audit( 'guardian_consent_withdrawn', $user_id );
		self::redirect( 'status', 'withdrawn' );
	}

	private static function guard_user_action( $action ) {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( $action, 'smc_nonce' );
	}
}

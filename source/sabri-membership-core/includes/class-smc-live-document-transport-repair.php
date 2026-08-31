<?php
defined( 'ABSPATH' ) || exit;

/**
 * Live-proven membership application recovery for shared-host evidence transport.
 *
 * Build 2026-08-31-r1 addresses three production-observed defects without
 * changing File 00's canonical ownership boundaries:
 * - draft AJAX requests from incomplete applicants were redirected by the
 *   retired-MFA admin-state guard before the draft handler could execute;
 * - the final admin-post submission reached File 00 with identity_front absent
 *   from $_FILES even though the browser had accepted the required file;
 * - generic "invalid" notices hid actionable field/collision/upload failures.
 *
 * Evidence files are staged through a dedicated authenticated AJAX request and
 * stored by the existing SMC_Security pipeline. The final canonical submission
 * still revalidates server-side state and accepts the already-stored evidence
 * through SMC_Security::has_current_document().
 */
final class SMC_Live_Document_Transport_Repair {
	const BUILD_ID = '1.2.44-live-20260831-document-transport-r1';
	const NONCE_ACTION = 'smc_stage_identity_document_v1';

	public static function init() {
		if ( self::BUILD_ID !== (string) get_option( 'smc_runtime_build_id', '' ) ) {
			update_option( 'smc_runtime_build_id', self::BUILD_ID, false );
		}

		add_action( 'admin_init', array( __CLASS__, 'allow_membership_ajax_before_admin_guard' ), 0 );
		add_action( 'wp_ajax_smc_stage_identity_document', array( __CLASS__, 'ajax_stage_identity_document' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_evidence_staging' ), 30 );
		add_filter( 'wp_redirect', array( __CLASS__, 'annotate_invalid_redirect' ), 20, 2 );
		add_filter( 'gettext', array( __CLASS__, 'actionable_invalid_message' ), 30, 3 );
	}

	/**
	 * admin-ajax.php executes admin_init. Incomplete applicants are not yet
	 * eligible, so the retired-MFA admin guard must not redirect the narrowly
	 * allowlisted membership recovery AJAX actions before their handlers run.
	 */
	public static function allow_membership_ajax_before_admin_guard() {
		if ( ! wp_doing_ajax() || empty( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array(
			'smc_save_application_draft',
			'smc_clear_application_draft',
			'smc_stage_identity_document',
		);
		if ( in_array( $action, $allowed, true ) && class_exists( 'SMC_MFA_Retirement' ) ) {
			remove_action( 'admin_init', array( 'SMC_MFA_Retirement', 'enforce_admin_state' ), 1 );
		}
	}

	public static function enqueue_evidence_staging() {
		if ( ! is_user_logged_in() || ! function_exists( 'smc_is_membership_page' ) || ! smc_is_membership_page() ) {
			return;
		}
		$path = SMC_PATH . 'assets/membership-evidence-stage.js';
		$hash = is_readable( $path ) ? substr( hash_file( 'sha256', $path ), 0, 12 ) : 'base';
		wp_enqueue_script(
			'smc-membership-evidence-stage',
			SMC_URL . 'assets/membership-evidence-stage.js',
			array( 'smc-membership' ),
			SMC_VERSION . '-' . $hash,
			true
		);
		wp_localize_script(
			'smc-membership-evidence-stage',
			'smcEvidenceStage',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'messages'=> array(
					'uploading' => __( 'Uploading this identity document securely…', 'sabri-membership-core' ),
					'staged'    => __( 'Secure evidence received by the server.', 'sabri-membership-core' ),
					'pending'   => __( 'Please wait until the selected identity document finishes uploading.', 'sabri-membership-core' ),
					'failed'    => __( 'The identity document could not be uploaded. Please review the file and try again.', 'sabri-membership-core' ),
				),
			)
		);
	}

	private static function transport_diagnostic( $field ) {
		$file_present = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] );
		$file = $file_present ? $_FILES[ $field ] : array();
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
		return array(
			'field_present'       => (bool) $file_present,
			'upload_error'        => $file_present ? (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) : null,
			'upload_size'         => $file_present ? max( 0, (int) ( $file['size'] ?? 0 ) ) : 0,
			'tmp_is_uploaded_file'=> $file_present && ! empty( $file['tmp_name'] ) ? (bool) is_uploaded_file( $file['tmp_name'] ) : false,
			'request_content_type'=> substr( $content_type, 0, 120 ),
			'content_length'      => isset( $_SERVER['CONTENT_LENGTH'] ) ? max( 0, (int) $_SERVER['CONTENT_LENGTH'] ) : 0,
			'file_uploads'        => filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN ),
			'upload_max_filesize' => sanitize_text_field( (string) ini_get( 'upload_max_filesize' ) ),
			'post_max_size'       => sanitize_text_field( (string) ini_get( 'post_max_size' ) ),
			'max_file_uploads'    => (int) ini_get( 'max_file_uploads' ),
		);
	}

	public static function ajax_stage_identity_document() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'unauthorized', 'message' => __( 'Your secure session could not be verified. Reload the application and try again.', 'sabri-membership-core' ) ), 403 );
		}
		$user_id = get_current_user_id();
		if ( ! SMC_Security::key_ready() || SMC_Completion::safe_mode() ) {
			wp_send_json_error( array( 'code' => 'security_unavailable', 'message' => __( 'Protected identity storage is temporarily unavailable. No document was accepted.', 'sabri-membership-core' ) ), 503 );
		}
		if ( SMC_Security::rate_limited( 'application-evidence-stage|' . $user_id, 30, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'code' => 'rate_limited', 'message' => __( 'Too many identity-document upload attempts were made. Please wait before trying again.', 'sabri-membership-core' ) ), 429 );
		}

		$document_key = sanitize_key( wp_unslash( $_POST['document_key'] ?? '' ) );
		$required = function_exists( 'smc_required_identity_documents' ) ? smc_required_identity_documents() : array();
		if ( ! isset( $required[ $document_key ] ) ) {
			wp_send_json_error( array( 'code' => 'invalid_document_key', 'message' => __( 'The selected identity-document field is not recognized.', 'sabri-membership-core' ) ), 400 );
		}
		$application = function_exists( 'smc_application' ) ? smc_application( $user_id ) : null;
		if ( $application && ! in_array( sanitize_key( $application['status'] ?? '' ), array( 'draft', 'more_information' ), true ) ) {
			wp_send_json_error( array( 'code' => 'application_locked', 'message' => __( 'This application is already in a controlled review state and cannot accept replacement evidence here.', 'sabri-membership-core' ) ), 409 );
		}

		$result = SMC_Security::store_uploaded_document( 'evidence_file', $required[ $document_key ], $user_id, $document_key );
		if ( is_wp_error( $result ) ) {
			$diagnostic = self::transport_diagnostic( 'evidence_file' );
			SMC_Security::audit(
				'application_evidence_stage_failed',
				$user_id,
				array(
					'document_key' => $document_key,
					'error_code'   => $result->get_error_code(),
					'transport'    => $diagnostic,
					'build_id'     => self::BUILD_ID,
				)
			);
			wp_send_json_error(
				array(
					'code'    => sanitize_key( $result->get_error_code() ),
					'message' => sanitize_text_field( $result->get_error_message() ),
				),
				400
			);
		}

		SMC_Security::audit( 'application_evidence_staged', $user_id, array( 'document_key' => $document_key, 'build_id' => self::BUILD_ID ) );
		wp_send_json_success( array( 'document_key' => $document_key, 'message' => __( 'Secure evidence received by the server.', 'sabri-membership-core' ) ) );
	}

	private static function trace_reason( $trace_id ) {
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', (string) $trace_id ) ) { return ''; }
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT repair_type, details FROM {$wpdb->prefix}smc_application_repairs WHERE trace_id=%s ORDER BY id DESC LIMIT 1",
				strtolower( $trace_id )
			),
			ARRAY_A
		);
		if ( ! $row ) { return 'application_state_conflict'; }
		$type = sanitize_key( $row['repair_type'] ?? '' );
		$details = json_decode( (string) ( $row['details'] ?? '' ), true );
		$details = is_array( $details ) ? $details : array();
		if ( 'application_document_incomplete' === $type ) {
			$reason = strtolower( sanitize_text_field( $details['reason'] ?? '' ) );
			if ( false !== strpos( $reason, 'is required' ) ) { return 'document_transport_missing'; }
			if ( false !== strpos( $reason, 'could not be uploaded' ) ) { return 'document_upload_failed'; }
			if ( false !== strpos( $reason, 'between 1 kb and 8 mb' ) ) { return 'document_size'; }
			if ( false !== strpos( $reason, 'jpg' ) || false !== strpos( $reason, 'png' ) || false !== strpos( $reason, 'webp' ) || false !== strpos( $reason, 'pdf' ) ) { return 'document_type'; }
			if ( false !== strpos( $reason, 'scanner' ) ) { return 'document_scan'; }
			if ( false !== strpos( $reason, 'image' ) ) { return 'document_image'; }
			return 'document_processing';
		}
		if ( 'application_submission_reconciliation' === $type ) { return 'submission_reconciliation'; }
		if ( 'application_idempotency_receipt' === $type ) { return 'submission_receipt'; }
		return 'application_state_conflict';
	}

	private static function infer_nontrace_reason() {
		if ( ! SMC_Security::key_ready() ) { return 'security_unavailable'; }
		if ( SMC_Completion::safe_mode() ) { return 'safe_mode'; }

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

		if ( '' === $legal_name ) { return 'legal_name'; }
		if ( false === $age ) { return 'date_of_birth'; }
		if ( ! isset( smc_allowed_genders()[ $gender ] ) ) { return 'gender'; }
		if ( ! $types ) { return 'membership_type'; }
		if ( is_wp_error( $phone ) ) { return 'phone_format'; }
		if ( ! preg_match( '/^[A-Z]{2}$/', $residence_country ) ) { return 'residence_country'; }
		if ( '' === $city ) { return 'city'; }
		if ( '' === $address ) { return 'address'; }
		if ( ! in_array( $id_type, array( 'national_id', 'passport' ), true ) ) { return 'identity_type'; }
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) { return 'issuing_country'; }
		if ( ! preg_match( '/^[A-Z0-9][A-Z0-9 -]{4,23}$/', $id_number ) ) { return 'identity_number'; }
		$minimum = smc_effective_minimum_age( $gender, $residence_country );
		if ( false === $minimum || $age < $minimum ) { return 'age_rule'; }
		if ( $age < 18 && (bool) array_filter( $types, 'smc_is_professional_type' ) ) { return 'professional_age'; }
		if ( empty( $_POST['truth'] ) ) { return 'truth_consent'; }
		if ( empty( $_POST['privacy'] ) ) { return 'privacy_consent'; }
		if ( empty( $_POST['terms'] ) ) { return 'terms_consent'; }
		if ( empty( $_POST['ethical'] ) ) { return 'ethical_consent'; }

		$user_id = get_current_user_id();
		$phone_hash = SMC_Security::blind_index( $phone, 'phone' );
		$id_hash = SMC_Security::blind_index( $country . '|' . $id_type . '|' . $id_number, 'identity-number' );
		if ( ! is_wp_error( $phone_hash ) && ! is_wp_error( $id_hash ) ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_applications WHERE phone_hash=%s AND user_id<>%d LIMIT 1", $phone_hash, $user_id ) ) ) { return 'phone_exists'; }
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_identity_records WHERE document_number_hash=%s AND user_id<>%d LIMIT 1", $id_hash, $user_id ) ) ) { return 'identity_exists'; }
		}
		return 'application_not_accepted';
	}

	public static function annotate_invalid_redirect( $location, $status ) {
		unset( $status );
		if ( ! is_user_logged_in() || empty( $_REQUEST['action'] ) || 'smc_submit_application' !== sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $location;
		}
		$query = wp_parse_url( $location, PHP_URL_QUERY );
		$args = array();
		if ( is_string( $query ) ) { parse_str( $query, $args ); }
		if ( 'invalid' !== sanitize_key( $args['smc_message'] ?? '' ) || ! empty( $args['smc_reason'] ) ) { return $location; }
		$trace_id = sanitize_text_field( $args['trace_id'] ?? '' );
		$reason = $trace_id ? self::trace_reason( $trace_id ) : self::infer_nontrace_reason();
		return add_query_arg( 'smc_reason', sanitize_key( $reason ?: 'application_not_accepted' ), $location );
	}

	public static function actionable_invalid_message( $translation, $text, $domain ) {
		if ( 'sabri-membership-core' !== $domain || 'The request could not be verified. Review the fields and try again.' !== $text ) {
			return $translation;
		}
		$reason = isset( $_GET['smc_reason'] ) ? sanitize_key( wp_unslash( $_GET['smc_reason'] ) ) : '';
		$messages = array(
			'legal_name' => 'Enter your legal name before submitting the application.',
			'date_of_birth' => 'Enter a valid date of birth before submitting the application.',
			'gender' => 'Select the gender used by the approved minimum-age rule.',
			'membership_type' => 'Select at least one membership role before submitting the application.',
			'phone_format' => 'Enter the mobile number in valid international format, including the country code.',
			'phone_exists' => 'This mobile number is already linked to another account. Sign in to that account, use account recovery, or contact support.',
			'residence_country' => 'Enter the country of residence as a two-letter ISO code, for example PK.',
			'city' => 'Enter your city before submitting the application.',
			'address' => 'Enter your residential address before submitting the application.',
			'identity_type' => 'Select a supported identity-document type.',
			'issuing_country' => 'Enter the identity-document issuing country as a two-letter ISO code, for example PK.',
			'identity_number' => 'Enter a valid identity-document number using the permitted letters, numbers, spaces, or hyphens.',
			'identity_exists' => 'This identity document is already linked to another account. Sign in to that account, use account recovery, or contact support.',
			'age_rule' => 'The entered date of birth does not meet the approved minimum-age rule for this application.',
			'professional_age' => 'Professional membership roles require an adult account aged 18 or older.',
			'truth_consent' => 'Accept the truthful-identity declaration before submitting the application.',
			'privacy_consent' => 'Accept the identity-verification privacy processing notice before submitting the application.',
			'terms_consent' => 'Accept the platform membership terms before submitting the application.',
			'ethical_consent' => 'Accept the ethical-use declaration before submitting the application.',
			'document_transport_missing' => 'The selected identity document did not reach the server. Reselect the document and upload it again; if this repeats, contact support with the reference shown.',
			'document_upload_failed' => 'The identity document reached the upload handler but PHP reported an upload failure. Select the file again and retry.',
			'document_size' => 'The identity evidence file must be between 1 KB and 8 MB.',
			'document_type' => 'Use a valid JPG, PNG, WebP, or permitted PDF identity document.',
			'document_scan' => 'The identity document was rejected by the required malware and active-content safety scan.',
			'document_image' => 'The identity image could not be safely decoded or re-encoded. Use a clear valid image and try again.',
			'document_processing' => 'The identity document could not be stored securely. Review the file and retry; use the reference shown if support is required.',
			'security_unavailable' => 'Protected identity processing is temporarily unavailable. No application was accepted; please try again later.',
			'safe_mode' => 'Membership writes are temporarily paused by File 00 Safe Mode. No application was accepted.',
			'submission_reconciliation' => 'The application data was stored, but the verification queue could not be reconciled. Use the reference shown for support.',
			'submission_receipt' => 'The application reached the server, but its completion receipt could not be verified. Use the reference shown for support.',
			'application_state_conflict' => 'The application changed while this request was being processed. Reload the current application state and try again.',
			'application_not_accepted' => 'The application was not accepted by the server. Review the form and use the reference shown if one is provided.',
		);
		return isset( $messages[ $reason ] ) ? $messages[ $reason ] : $translation;
	}
}

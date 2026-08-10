<?php
defined( 'ABSPATH' ) || exit;

/**
 * Repository-complete UX, role, repair, privacy-route and operational controls.
 */
final class SMC_Completion {
	const DRAFT_META = '_smc_application_draft_v1';
	const DRAFT_TTL  = 2592000; // 30 days.

	public static function init() {
		add_action( 'wp_ajax_smc_save_application_draft', array( __CLASS__, 'ajax_save_draft' ) );
		add_action( 'wp_ajax_smc_clear_application_draft', array( __CLASS__, 'ajax_clear_draft' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'private_robots' ) );
		add_filter( 'wp_headers', array( __CLASS__, 'private_headers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'private_nocache' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_safe_mode' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_post_smc_retry_repair', array( __CLASS__, 'retry_repair' ) );
		add_action( 'admin_post_smc_retry_outbox', array( __CLASS__, 'retry_outbox' ) );
		add_action( 'admin_post_smc_post_restore_reconcile', array( __CLASS__, 'post_restore_reconcile' ) );
		add_action( 'admin_post_smc_download_backup_manifest', array( __CLASS__, 'download_backup_manifest' ) );
		add_action( 'admin_post_smc_create_retention_hold', array( __CLASS__, 'create_retention_hold' ) );
		add_action( 'admin_post_smc_release_retention_hold', array( __CLASS__, 'release_retention_hold' ) );
		add_action( 'smc_reconcile_applications', array( __CLASS__, 'reconcile_applications' ) );
	}

	public static function safe_mode() {
		$constant = defined( 'SMC_SAFE_MODE' ) && SMC_SAFE_MODE;
		$declared = $constant || (bool) get_option( 'smc_safe_mode', false );
		$filtered = (bool) apply_filters( 'smc_safe_mode', $declared );
		return $declared || $filtered;
	}

	public static function enforce_safe_mode() {
		if ( ! self::safe_mode() || empty( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed = array(
			'smc_revoke_session', 'smc_revoke_all_sessions', 'smc_retry_repair', 'smc_retry_outbox',
			'smc_post_restore_reconcile', 'smc_download_backup_manifest',
		);
		if ( 0 === strpos( $action, 'smc_' ) && ! in_array( $action, $allowed, true ) ) {
			wp_die( esc_html__( 'Sabri Membership Safe Mode is active. Risky writes are temporarily blocked while status, session revocation and scoped repair remain available.', 'sabri-membership-core' ), '', array( 'response' => 503 ) );
		}
	}

	public static function private_nocache() {
		if ( ! smc_is_membership_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Frame-Options: SAMEORIGIN', true );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()', true );
	}

	public static function private_robots( $robots ) {
		if ( smc_is_membership_page() ) {
			$robots['noindex']      = true;
			$robots['nofollow']     = true;
			$robots['noarchive']    = true;
			$robots['nosnippet']    = true;
			$robots['noimageindex'] = true;
		}
		return $robots;
	}

	public static function private_headers( $headers ) {
		if ( smc_is_membership_page() ) {
			$headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma']        = 'no-cache';
			$headers['Expires']       = 'Wed, 11 Jan 1984 05:00:00 GMT';
			$headers['X-Robots-Tag']  = 'noindex, nofollow, noarchive, nosnippet, noimageindex';
			$headers['Referrer-Policy'] = 'no-referrer';
			$headers['X-Content-Type-Options'] = 'nosniff';
			$headers['X-Frame-Options'] = 'SAMEORIGIN';
			$headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=()';
		}
		return $headers;
	}

	private static function draft_context( $user_id ) {
		$user_id = absint( $user_id );
		$token = wp_get_session_token();
		if ( ! $user_id || ! is_string( $token ) || '' === $token ) {
			return new WP_Error( 'smc_draft_session_missing', __( 'A current authenticated session is required for the protected application draft.', 'sabri-membership-core' ) );
		}
		$session_hash = SMC_Security::blind_index( $token, 'application-draft-session' );
		if ( is_wp_error( $session_hash ) ) {
			return $session_hash;
		}
		return array(
			'user_id'        => $user_id,
			'policy_version' => smc_policy()['version'],
			'session_hash'   => $session_hash,
		);
	}

	public static function load_draft( $user_id ) {
		$receipt = get_user_meta( absint( $user_id ), self::DRAFT_META, true );
		$issued_at = absint( is_array( $receipt ) ? ( $receipt['issued_at'] ?? 0 ) : 0 );
		$expires_at = absint( is_array( $receipt ) ? ( $receipt['expires'] ?? 0 ) : 0 );
		if ( ! is_array( $receipt ) || empty( $receipt['envelope'] ) || ! $issued_at || $expires_at !== $issued_at + self::DRAFT_TTL || $expires_at < time() ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			return array();
		}
		$base_context = self::draft_context( $user_id );
		if ( is_wp_error( $base_context ) ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			return array();
		}
		$context = array_merge( $base_context, array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at ) );
		$json = SMC_Security::decrypt( $receipt['envelope'], 'application-draft', $context );
		if ( is_wp_error( $json ) ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			SMC_Security::audit( 'application_draft_decryption_failed', absint( $user_id ) );
			return array();
		}
		$sealed = json_decode( $json, true );
		if ( ! is_array( $sealed ) || absint( $sealed['issued_at'] ?? 0 ) !== $issued_at || absint( $sealed['expires_at'] ?? 0 ) !== $expires_at || ! is_array( $sealed['draft'] ?? null ) ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			SMC_Security::audit( 'application_draft_invalid', absint( $user_id ) );
			return array();
		}
		return $sealed['draft'];
	}

	private static function sanitize_draft( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$data = array(
			'legal_name'            => sanitize_text_field( $raw['legal_name'] ?? '' ),
			'date_of_birth'         => sanitize_text_field( $raw['date_of_birth'] ?? '' ),
			'gender'                => sanitize_key( $raw['gender'] ?? '' ),
			'residence_country'     => strtoupper( substr( sanitize_text_field( $raw['residence_country'] ?? '' ), 0, 2 ) ),
			'city'                  => sanitize_text_field( $raw['city'] ?? '' ),
			'address'               => sanitize_textarea_field( $raw['address'] ?? '' ),
			'phone'                 => sanitize_text_field( $raw['phone'] ?? '' ),
			'identity_type'         => sanitize_key( $raw['identity_type'] ?? '' ),
			'issuing_country'       => strtoupper( substr( sanitize_text_field( $raw['issuing_country'] ?? '' ), 0, 2 ) ),
			'guardian_name'         => sanitize_text_field( $raw['guardian_name'] ?? '' ),
			'guardian_relationship' => sanitize_key( $raw['guardian_relationship'] ?? '' ),
			'guardian_email'        => sanitize_email( $raw['guardian_email'] ?? '' ),
			'guardian_phone'        => sanitize_text_field( $raw['guardian_phone'] ?? '' ),
			'current_step'          => max( 1, min( 7, absint( $raw['current_step'] ?? 1 ) ) ),
			'updated_at'            => current_time( 'mysql', true ),
		);
		$types = isset( $raw['membership_types'] ) && is_array( $raw['membership_types'] ) ? $raw['membership_types'] : array();
		$data['membership_types'] = array_values( array_intersect( array_keys( smc_account_types() ), array_map( 'sanitize_key', $types ) ) );
		if ( ! $data['membership_types'] ) {
			$data['membership_types'] = array( 'member' );
		}
		return $data;
	}

	public static function ajax_save_draft() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'smc_application_draft', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'unauthorized' ), 403 );
		}
		$user_id = get_current_user_id();
		if ( SMC_Security::rate_limited( 'application-draft|' . $user_id, 120, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'code' => 'rate_limited' ), 429 );
		}
		$raw = isset( $_POST['draft'] ) ? json_decode( wp_unslash( $_POST['draft'] ), true ) : array();
		$data = self::sanitize_draft( $raw );
		$issued_at = time();
		$expires_at = $issued_at + self::DRAFT_TTL;
		$sealed = wp_json_encode( array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at, 'draft'=>$data ) );
		$base_context = self::draft_context( $user_id );
		if ( is_wp_error( $base_context ) ) { wp_send_json_error( array( 'code'=>'session_unavailable' ), 401 ); }
		$context = array_merge( $base_context, array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at ) );
		$envelope = SMC_Security::encrypt( $sealed, 'application-draft', $context );
		if ( is_wp_error( $envelope ) ) { wp_send_json_error( array( 'code'=>'encryption_unavailable' ), 503 ); }
		$receipt = array( 'envelope'=>$envelope, 'issued_at'=>$issued_at, 'expires'=>$expires_at, 'updated_at'=>$issued_at );
		update_user_meta( $user_id, self::DRAFT_META, $receipt );
		$stored = get_user_meta( $user_id, self::DRAFT_META, true );
		if ( ! is_array( $stored ) || ! hash_equals( (string) $envelope, (string) ( $stored['envelope'] ?? '' ) ) || absint( $stored['expires'] ?? 0 ) !== $expires_at ) {
			wp_send_json_error( array( 'code'=>'draft_not_persisted' ), 500 );
		}
		wp_send_json_success( array( 'updated_at'=>$data['updated_at'], 'expires'=>$expires_at ) );
	}

	public static function clear_draft( $user_id ) {
		delete_user_meta( absint( $user_id ), self::DRAFT_META );
		return ! metadata_exists( 'user', absint( $user_id ), self::DRAFT_META );
	}

	public static function ajax_clear_draft() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'smc_application_draft', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'unauthorized' ), 403 );
		}
		wp_send_json_success( array( 'cleared' => self::clear_draft( get_current_user_id() ) ) );
	}

	public static function record_repair( $user_id, $repair_type, $details, $trace_id = '' ) {
		global $wpdb;
		$trace_id = preg_match( '/^[0-9a-f-]{36}$/i', (string) $trace_id ) ? strtolower( $trace_id ) : wp_generate_uuid4();
		$details = is_array( $details ) ? $details : array();
		$details['trace_id'] = $trace_id;
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert(
			$wpdb->prefix . 'smc_application_repairs',
			array(
				'trace_id'        => $trace_id,
				'user_id'         => absint( $user_id ),
				'repair_type'     => sanitize_key( $repair_type ),
				'status'          => 'pending',
				'details'         => wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( 1 === $ok && ! wp_next_scheduled( 'smc_reconcile_applications' ) ) {
			wp_schedule_single_event( time() + 60, 'smc_reconcile_applications' );
		}
		return 1 === $ok ? $trace_id : false;
	}

	public static function reconcile_applications( $limit = 25, $only_id = 0 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$only_id = absint( $only_id );
		$wpdb->query(
			"UPDATE {$wpdb->prefix}smc_application_repairs SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error='Recovered stale processing claim.',updated_at=UTC_TIMESTAMP() WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
		);
		if ( $only_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_application_repairs WHERE id=%d AND status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() LIMIT 1", $only_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_application_repairs WHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
		}
		foreach ( (array) $rows as $row ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND status IN ('pending','retry')", current_time( 'mysql', true ), (int) $row['id'] ) );
			if ( 1 !== $claimed ) {
				continue;
			}
			$details = json_decode( (string) $row['details'], true );
			$resolved = false;
			$repair_error = '';
			try {
				$resolved = (bool) apply_filters( 'smc_repair_application_item', false, $row, is_array( $details ) ? $details : array() );
				if ( ! $resolved && 'application_document_incomplete' === $row['repair_type'] ) { $resolved = self::application_documents_complete( (int) $row['user_id'] ); }
				if ( ! $resolved && 'membership_effects_reconciliation' === $row['repair_type'] ) { $resolved = self::reconcile_membership_effects( (int) $row['user_id'] ); }
				if ( ! $resolved && 'advanced_trust_transition' === $row['repair_type'] && class_exists( 'SMC_Advanced_Trust_2026' ) ) { $resolved = SMC_Advanced_Trust_2026::repair_transition_hold( (int) $row['user_id'] ); }
			} catch ( Throwable $error ) {
				$resolved = false;
				$repair_error = 'Repair adapter raised an exception; retry is required.';
			}
			if ( $resolved ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status='complete',last_error=NULL,resolved_at=%s,updated_at=%s WHERE id=%d AND status='processing'", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $row['id'] ) );
			} else {
				$attempts = (int) $row['attempts'] + 1;
				$status = $attempts >= 10 ? 'dead_letter' : 'retry';
				$last_error = '' !== $repair_error ? $repair_error : 'The repair condition is still unresolved.';
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status=%s,next_attempt_at=%s,last_error=%s,updated_at=%s WHERE id=%d AND status='processing'", $status, gmdate( 'Y-m-d H:i:s', time() + min( DAY_IN_SECONDS, (int) pow( 2, min( 10, $attempts ) ) * MINUTE_IN_SECONDS ) ), $last_error, current_time( 'mysql', true ), (int) $row['id'] ) );
			}
		}
	}

	private static function application_documents_complete( $user_id ) {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT document_key FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d AND scan_status='passed' AND status IN ('submitted','approved') AND (expiry_date IS NULL OR expiry_date>=UTC_DATE())", absint( $user_id ) ) );
		return ! array_diff( array_keys( smc_required_identity_documents() ), array_map( 'sanitize_key', (array) $keys ) );
	}

	public static function queue_effects_repair( $user_id, $operation, $target_status, $reason ) {
		global $wpdb; $trace = wp_generate_uuid4(); $now = current_time( 'mysql', true );
		$details = wp_json_encode( array( 'operation'=>sanitize_key($operation),'target_status'=>sanitize_key($target_status),'reason'=>sanitize_key($reason) ) );
		return false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_application_repairs (trace_id,user_id,repair_type,status,details,attempts,next_attempt_at,created_at,updated_at) VALUES (%s,%d,'membership_effects_reconciliation','pending',%s,0,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", $trace, absint($user_id), $details, $now, $now, $now ) );
	}

	private static function reconcile_membership_effects( $user_id ) {
		$user_id = absint( $user_id ); $hold = get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ); if ( ! is_array( $hold ) ) { return true; }
		$role_ok = SMC_Contracts::sync_wordpress_roles( $user_id ); $sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'effects_reconciliation' );
		if ( ! $role_ok || ! $sessions_ok ) { return false; } delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' ); return ! metadata_exists( 'user', $user_id, '_smc_membership_effects_hold_v1' );
	}

	public static function health_snapshot() {
		global $wpdb;
		$key_ready = SMC_Security::key_ready() && '' !== SMC_Security::key_id();
		$dir = $key_ready ? SMC_Security::private_dir() : new WP_Error( 'key', 'Key configuration unavailable' );
		// Health/restore truth must verify the complete chain, including the serialized tail.
		$audit = SMC_Security::verify_audit_chain();
		return array(
			'version'             => SMC_VERSION,
			'database_version'    => get_option( 'smc_db_version', '' ),
			'contract_version'    => SMC_CONTRACT_VERSION,
			'safe_mode'           => self::safe_mode(),
			'key_ready'           => $key_ready,
			'private_storage'     => ! is_wp_error( $dir ),
			'scanner_configured'  => (bool) apply_filters( 'smc_scanner_health', false ),
			'notification_health' => apply_filters( 'smc_notification_health', 'unknown' ),
			'audit_valid'         => ! empty( $audit['valid'] ),
			'audit_checked'       => absint( $audit['checked'] ?? 0 ),
			'file_job_backlog'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_file_jobs WHERE status IN ('pending','retry','processing')" ),
			'file_job_failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_file_jobs WHERE status IN ('failed','dead_letter')" ),
			'repair_backlog'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_application_repairs WHERE status IN ('pending','retry','processing','dead_letter')" ),
			'outbox_backlog'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE status IN ('pending','retry','processing','dead_letter')" ),
			'review_overdue'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_verification_requests WHERE status NOT IN ('approved','rejected') AND sla_due_at IS NOT NULL AND sla_due_at<UTC_TIMESTAMP()" ),
			// Legacy releases could create non-expiring holds. New holds require expiry,
			// while any legacy indefinite hold remains fail-closed and is surfaced as
			// an explicit governance blocker until an authorized operator releases it.
			'indefinite_hold_blockers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_retention_holds WHERE released_at IS NULL AND expires_at IS NULL" ),
			'last_restore_test'   => get_option( 'smc_last_restore_test', array() ),
			'owners'              => self::operational_owners(),
			'slos'                => self::service_levels(),
		);
	}

	public static function operational_owners() {
		$defaults = array(
			'membership_operations' => 'Founder-appointed Membership Administrator',
			'reviewer_governance'    => 'Senior Membership Reviewer',
			'privacy_erasure'        => 'Privacy Officer',
			'security_keys'          => 'Security/Key Custodian',
			'backups'                => 'Backup and Restore Owner',
			'integrations'           => 'Platform Integration Owner',
			'support'                => 'Support and Case Management Owner',
		);
		return (array) apply_filters( 'smc_operational_owners', get_option( 'smc_operational_owners', $defaults ) );
	}

	public static function service_levels() {
		$defaults = array(
			'application_availability_percent' => 99.5,
			'review_queue_target_hours'         => 72,
			'provider_retry_minutes'            => 15,
			'critical_alert_response_minutes'   => 30,
			'rpo_hours'                         => 24,
			'rto_hours'                         => 8,
			'restore_test_cadence_days'         => 90,
			'privacy_export_days'               => 30,
			'erasure_completion_days'           => 30,
		);
		return (array) apply_filters( 'smc_service_levels', get_option( 'smc_service_levels', $defaults ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'smc-membership', __( 'Health and Repair', 'sabri-membership-core' ), __( 'Health and Repair', 'sabri-membership-core' ), 'smc_manage_membership', 'smc-health-repair', array( __CLASS__, 'health_page' ) );
	}

	private static function current_trust_allows_high_risk_action() {
		if ( ! is_user_logged_in() || ! class_exists( 'SMC_MFA_Retirement' ) ) {
			return false;
		}
		return SMC_MFA_Retirement::sensitive_action_authorized( get_current_user_id(), 'default' );
	}

	private static function require_high_risk_authority() {
		if ( ! current_user_can( 'smc_manage_membership' ) || ! self::current_trust_allows_high_risk_action() ) {
			wp_die( esc_html__( 'A current authenticated membership-management session with an active trust state is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
	}

	private static function require_retention_authority() {
		if ( ! current_user_can( 'smc_manage_retention_holds' ) || ! self::current_trust_allows_high_risk_action() ) {
			wp_die( esc_html__( 'A current authenticated retention-hold session with an active trust state is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
	}

	public static function health_page() {
		self::require_high_risk_authority();
		global $wpdb;
		$health = self::health_snapshot();
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Health and Scoped Repair', 'sabri-membership-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'This page reports privacy-safe health. It never exposes keys, raw identity evidence, phone numbers or guardian contact.', 'sabri-membership-core' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( $health as $key => $value ) {
			if ( is_array( $value ) ) { $value = wp_json_encode( $value ); }
			echo '<tr><th>' . esc_html( $key ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$repairs = $wpdb->get_results( "SELECT id,trace_id,user_id,repair_type,status,attempts,last_error,created_at,updated_at FROM {$wpdb->prefix}smc_application_repairs WHERE status<>'complete' ORDER BY id DESC LIMIT 100", ARRAY_A );
		echo '<h2>' . esc_html__( 'Application Repair Items', 'sabri-membership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Trace</th><th>User</th><th>Type</th><th>Status</th><th>Attempts</th><th>Error</th><th></th></tr></thead><tbody>';
		foreach ( $repairs as $row ) {
			echo '<tr><td>' . absint( $row['id'] ) . '</td><td><code>' . esc_html( $row['trace_id'] ) . '</code></td><td>' . absint( $row['user_id'] ) . '</td><td>' . esc_html( $row['repair_type'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . absint( $row['attempts'] ) . '</td><td>' . esc_html( $row['last_error'] ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_retry_repair"><input type="hidden" name="repair_id" value="' . absint( $row['id'] ) . '">'; wp_nonce_field( 'smc_retry_repair_' . $row['id'], 'smc_nonce' ); echo '<button class="button">' . esc_html__( 'Safe retry', 'sabri-membership-core' ) . '</button></form></td></tr>';
		}
		if ( ! $repairs ) { echo '<tr><td colspan="8">' . esc_html__( 'No unresolved application repair items.', 'sabri-membership-core' ) . '</td></tr>'; }
		echo '</tbody></table>';
		$outbox = $wpdb->get_results( "SELECT id,event_type,correlation_id,status,attempts,last_error,created_at,updated_at FROM {$wpdb->prefix}smc_event_outbox WHERE status IN ('retry','dead_letter') ORDER BY id DESC LIMIT 100", ARRAY_A );
		echo '<h2>' . esc_html__( 'Event Delivery Dead Letter and Retry', 'sabri-membership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Event</th><th>Correlation</th><th>Status</th><th>Attempts</th><th>Error</th><th></th></tr></thead><tbody>';
		foreach ( $outbox as $row ) { echo '<tr><td>' . absint( $row['id'] ) . '</td><td>' . esc_html( $row['event_type'] ) . '</td><td><code>' . esc_html( $row['correlation_id'] ) . '</code></td><td>' . esc_html( $row['status'] ) . '</td><td>' . absint( $row['attempts'] ) . '</td><td>' . esc_html( $row['last_error'] ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_retry_outbox"><input type="hidden" name="event_id" value="' . absint( $row['id'] ) . '">'; wp_nonce_field( 'smc_retry_outbox_' . $row['id'], 'smc_nonce' ); echo '<button class="button">' . esc_html__( 'Replay safely', 'sabri-membership-core' ) . '</button></form></td></tr>'; }
		if ( ! $outbox ) { echo '<tr><td colspan="7">' . esc_html__( 'No event delivery dead letters.', 'sabri-membership-core' ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( current_user_can( 'smc_manage_retention_holds' ) ) {
			$holds = $wpdb->get_results( "SELECT id,user_id,hold_type,reason,created_by,created_at,expires_at,released_at FROM {$wpdb->prefix}smc_retention_holds ORDER BY id DESC LIMIT 100", ARRAY_A );
			echo '<h2>' . esc_html__( 'Retention Holds', 'sabri-membership-core' ) . '</h2><p>' . esc_html__( 'Create only documented legal, safety, security, regulatory or dispute holds. Active holds pause privacy erasure until release or expiry.', 'sabri-membership-core' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="smc-form"><input type="hidden" name="action" value="smc_create_retention_hold">'; wp_nonce_field( 'smc_create_retention_hold', 'smc_nonce' );
			echo '<label>' . esc_html__( 'User ID', 'sabri-membership-core' ) . '<input name="user_id" type="number" min="1" required></label> <label>' . esc_html__( 'Hold type', 'sabri-membership-core' ) . '<select name="hold_type" required><option value="legal">Legal</option><option value="safety">Safety</option><option value="security">Security</option><option value="regulatory">Regulatory</option><option value="dispute">Dispute</option></select></label> <label>' . esc_html__( 'Documented reason', 'sabri-membership-core' ) . '<input name="reason" minlength="12" maxlength="500" required></label> <label>' . esc_html__( 'Required review/expiry date (UTC)', 'sabri-membership-core' ) . '<input name="expires_on" type="date" required></label> <button class="button button-primary">' . esc_html__( 'Create retention hold', 'sabri-membership-core' ) . '</button></form>';
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Type</th><th>Reason</th><th>Created</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $holds as $hold ) {
				$legacy_indefinite = empty( $hold['released_at'] ) && empty( $hold['expires_at'] );
				$active = empty( $hold['released_at'] ) && ( $legacy_indefinite || strtotime( $hold['expires_at'] . ' UTC' ) > time() );
				$status_label = $legacy_indefinite ? __( 'Legacy indefinite — release and recreate with an expiry', 'sabri-membership-core' ) : ( $active ? __( 'Active', 'sabri-membership-core' ) : __( 'Released / expired', 'sabri-membership-core' ) );
				echo '<tr><td>' . absint( $hold['id'] ) . '</td><td>' . absint( $hold['user_id'] ) . '</td><td>' . esc_html( $hold['hold_type'] ) . '</td><td>' . esc_html( $hold['reason'] ) . '</td><td>' . esc_html( $hold['created_at'] ) . '</td><td>' . esc_html( $hold['expires_at'] ?: '—' ) . '</td><td>' . esc_html( $status_label ) . '</td><td>';
				if ( $active ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_release_retention_hold"><input type="hidden" name="hold_id" value="' . absint( $hold['id'] ) . '">'; wp_nonce_field( 'smc_release_retention_hold_' . $hold['id'], 'smc_nonce' ); echo '<button class="button">' . esc_html__( 'Release hold', 'sabri-membership-core' ) . '</button></form>'; }
				echo '</td></tr>';
			}
			if ( ! $holds ) { echo '<tr><td colspan="8">' . esc_html__( 'No retention holds recorded.', 'sabri-membership-core' ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Backup and Restore Evidence', 'sabri-membership-core' ) . '</h2><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=smc_download_backup_manifest' ), 'smc_download_backup_manifest', 'smc_nonce' ) ) . '">' . esc_html__( 'Download privacy-safe backup manifest', 'sabri-membership-core' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_post_restore_reconcile">'; wp_nonce_field( 'smc_post_restore_reconcile', 'smc_nonce' ); echo '<label>' . esc_html__( 'Restore evidence reference', 'sabri-membership-core' ) . '<input name="evidence_reference" required maxlength="190"></label> <button class="button button-primary">' . esc_html__( 'Run post-restore reconciliation', 'sabri-membership-core' ) . '</button></form></div>';
	}

	public static function retry_repair() {
		self::require_high_risk_authority();
		$id = absint( $_POST['repair_id'] ?? 0 );
		check_admin_referer( 'smc_retry_repair_' . $id, 'smc_nonce' );
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter','pending')", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );
		if ( 1 !== $updated ) {
			wp_die( esc_html__( 'The selected repair item is no longer retryable.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		self::reconcile_applications( 1, $id );
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}

	public static function retry_outbox() {
		self::require_high_risk_authority();
		$id = absint( $_POST['event_id'] ?? 0 );
		check_admin_referer( 'smc_retry_outbox_' . $id, 'smc_nonce' );
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_event_outbox SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter')", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );
		if ( 1 !== $updated ) {
			wp_die( esc_html__( 'The selected event is no longer replayable.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		SMC_Events::process_outbox( 1, $id );
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}

	public static function create_retention_hold() {
		self::require_retention_authority();
		check_admin_referer( 'smc_create_retention_hold', 'smc_nonce' );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$hold_type = sanitize_key( wp_unslash( $_POST['hold_type'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$expires_on = sanitize_text_field( wp_unslash( $_POST['expires_on'] ?? '' ) );
		if ( ! $user_id || ! get_userdata( $user_id ) || ! in_array( $hold_type, array( 'legal','safety','security','regulatory','dispute' ), true ) || strlen( $reason ) < 12 ) {
			wp_die( esc_html__( 'The retention hold request is invalid.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );
		}
		if ( '' === $expires_on ) { wp_die( esc_html__( 'Retention holds must be time-bound with a future UTC review/expiry date.', 'sabri-membership-core' ), '', array( 'response'=>400 ) ); }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $expires_on, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $expires_on || $date->getTimestamp() <= time() ) { wp_die( esc_html__( 'Retention hold expiry must be a future UTC date.', 'sabri-membership-core' ), '', array( 'response' => 400 ) ); }
		$expires_at = $date->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$created_at = current_time( 'mysql', true );
		$inserted = $wpdb->insert( $wpdb->prefix . 'smc_retention_holds', array( 'user_id'=>$user_id,'hold_type'=>$hold_type,'reason'=>$reason,'created_by'=>get_current_user_id(),'created_at'=>$created_at,'expires_at'=>$expires_at,'released_at'=>null ), array('%d','%s','%s','%d','%s','%s','%s') );
		$hold_id = 1 === $inserted ? (int) $wpdb->insert_id : 0;
		$audit_ok = $hold_id && SMC_Security::audit( 'retention_hold_created', $user_id, array( 'hold_id'=>$hold_id,'hold_type'=>$hold_type,'reason'=>$reason,'expires_at'=>$expires_at ?: '' ) );
		if ( ! $hold_id || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The retention hold could not be committed with its audit evidence.', 'sabri-membership-core' ), '', array( 'response' => 503 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}

	public static function release_retention_hold() {
		self::require_retention_authority();
		$hold_id = absint( $_POST['hold_id'] ?? 0 );
		check_admin_referer( 'smc_release_retention_hold_' . $hold_id, 'smc_nonce' );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$hold = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_retention_holds WHERE id=%d LIMIT 1 FOR UPDATE", $hold_id ), ARRAY_A );
		if ( ! $hold || ! empty( $hold['released_at'] ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'This retention hold is unavailable or already released.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$released_at = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_retention_holds SET released_at=%s WHERE id=%d AND released_at IS NULL", $released_at, $hold_id ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'retention_hold_released', (int) $hold['user_id'], array( 'hold_id'=>$hold_id,'hold_type'=>(string)$hold['hold_type'] ) );
		if ( 1 !== $updated || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The retention hold release could not be committed with its audit evidence.', 'sabri-membership-core' ), '', array( 'response' => 503 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}

	public static function backup_manifest() {
		global $wpdb;
		$tables = array( 'smc_applications','smc_identity_records','smc_identity_documents','smc_guardian_consents','smc_verification_requests','smc_approval_votes','smc_verification_events','smc_consents','smc_contact_otps','smc_auth_sessions','smc_mfa_factor_state','smc_recovery_codes','smc_rate_limits','smc_file_jobs','smc_retention_holds','smc_audit_log','smc_audit_tail','smc_migrations','smc_role_grants','smc_event_outbox','smc_event_inbox','smc_application_repairs' );
		$counts = array();
		foreach ( $tables as $suffix ) { $counts[ $suffix ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$suffix}" ); }
		$dir = SMC_Security::private_dir();
		$files = 0;
		if ( ! is_wp_error( $dir ) ) {
			foreach ( new DirectoryIterator( $dir ) as $entry ) { if ( ! $entry->isDot() && $entry->isFile() && ! $entry->isLink() ) { ++$files; } }
		}
		return array(
			'manifest_version' => '1.0.0', 'generated_at' => gmdate( 'c' ), 'plugin_version' => SMC_VERSION,
			'database_version' => SMC_DB_VERSION, 'contract_version' => SMC_CONTRACT_VERSION,
			'table_counts' => $counts, 'private_file_count' => $files,
			'audit_tail_hash' => (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1" ),
			'audit_log_last_hash' => (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 1" ),
			'key_identifier' => (string) apply_filters( 'smc_backup_key_identifier', SMC_Security::key_id() ),
			'required_components' => array( 'database', 'encrypted_private_evidence', 'key_recovery_metadata', 'retention_holds', 'audit_chain', 'migration_registry' ),
		);
	}

	public static function download_backup_manifest() {
		self::require_high_risk_authority();
		check_admin_referer( 'smc_download_backup_manifest', 'smc_nonce' );
		nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="smc-backup-manifest-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( self::backup_manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); exit;
	}

	public static function post_restore_reconcile() {
		self::require_high_risk_authority(); check_admin_referer( 'smc_post_restore_reconcile', 'smc_nonce' );
		$reference = sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) );
		$proof = apply_filters( 'smc_restore_proof_v1', null, $reference );
		$required = array( 'restore_run_id','manifest_verified','isolated_restore','component_digests_match','row_counts_match','private_files_match','decrypt_samples_pass','key_recovery_pass','audit_chain_pass','retention_holds_reconciled','migrations_reconciled' );
		$ok = is_array( $proof ) && strlen( $reference ) >= 8;
		foreach ( $required as $key ) { if ( ! $ok || empty( $proof[ $key ] ) ) { $ok = false; break; } }
		$health = self::health_snapshot(); $ok = $ok && $health['key_ready'] && $health['private_storage'] && $health['audit_valid'] && SMC_DB_VERSION === $health['database_version'] && 0 === (int) $health['file_job_failed'] && 0 === (int) ( $health['indefinite_hold_blockers'] ?? 0 );
		$result = $ok ? 'passed' : 'failed';
		if ( ! SMC_Security::audit( 'post_restore_reconciliation_' . $result, 0, array( 'evidence_reference'=>$reference,'restore_run_id'=>is_array($proof)?sanitize_text_field($proof['restore_run_id']??''):'' ) ) ) { wp_die( esc_html__( 'Restore reconciliation evidence could not be appended to the audit chain.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		$record = array( 'evidence_reference'=>$reference,'restore_run_id'=>is_array($proof)?sanitize_text_field($proof['restore_run_id']??''):'','checked_at'=>current_time('mysql',true),'result'=>$result,'health'=>$health );
		update_option( 'smc_last_restore_test', $record, false );
		if ( get_option( 'smc_last_restore_test', null ) !== $record ) { wp_die( esc_html__( 'Restore reconciliation finished, but its evidence record could not be persisted.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		if ( ! $ok ) { wp_die( esc_html__( 'Restore proof did not satisfy the isolated-restore acceptance contract.', 'sabri-membership-core' ), '', array( 'response'=>409 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}
}

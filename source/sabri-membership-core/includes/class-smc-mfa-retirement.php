<?php
defined( 'ABSPATH' ) || exit;

/**
 * Founder-approved File 00 MFA retirement — 10 August 2026.
 *
 * File 00 no longer owns or requires an authenticator/TOTP, recovery codes,
 * a user-entered per-session MFA challenge, authenticator replacement, or
 * lost-factor recovery. Password/login/account recovery remains an
 * authentication-domain responsibility (File 02).
 *
 * A short-lived internal compatibility stamp is maintained on the existing
 * File 00 session ledger so older File 00 governance code that still asks
 * whether a session is "verified" cannot lock users behind a retired factor.
 * The stamp is derived solely from the already-authenticated WordPress session;
 * it is not a second factor and is never exposed as an MFA claim through the
 * public File 00 contract.
 *
 * Historical audit rows are preserved. Obsolete factor secrets and recovery
 * material are removed only after the current File 00 database/audit migration
 * is healthy, and cleanup is rolled back if its audit append fails.
 */
final class SMC_MFA_Retirement {
	const POLICY_VERSION = '2026-08-10-founder-mfa-retirement-v1';
	const STATE_OPTION   = 'smc_mfa_retirement_state_v1';

	private static $restricted_caps = array(
		'upload_files', 'edit_posts', 'publish_posts', 'edit_published_posts', 'delete_posts',
		'create_sabri_medical_content', 'publish_sabri_medical_content', 'smc_message_members',
		'smc_book_appointments', 'smc_review_verification', 'smc_view_private_documents',
		'smc_finalize_verification', 'smc_manage_membership', 'smc_manage_retention_holds',
	);

	public static function init() {
		self::remove_mfa_runtime_hooks();
		self::replace_authorization_boundary();
		remove_shortcode( 'smc_membership_security' );
		add_shortcode( 'smc_membership_security', array( __CLASS__, 'security_shortcode' ) );
		remove_shortcode( 'smc_membership_recovery' );
		add_filter( 'smc_assertions_v1', array( __CLASS__, 'retire_mfa_assertions' ), 999, 2 );
		add_filter( 'gettext', array( __CLASS__, 'retire_mfa_wording' ), 20, 3 );
		add_action( 'init', array( __CLASS__, 'mark_primary_session_current' ), 100 );
		add_action( 'admin_init', array( __CLASS__, 'retire_recovery_page' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'retire_legacy_factor_state' ), 100 );
		add_action( 'admin_init', array( __CLASS__, 'mark_primary_session_current' ), 999 );
	}

	private static function remove_mfa_runtime_hooks() {
		foreach ( array( 'start_2fa', 'finish_2fa', 'challenge_2fa', 'rotate_recovery', 'ack_recovery_receipt' ) as $action ) {
			remove_action( 'admin_post_smc_' . $action, array( 'SMC_Workflow', 'handle_' . $action ) );
		}
		if ( class_exists( 'SMC_Account_Recovery' ) ) {
			remove_filter( 'do_shortcode_tag', array( 'SMC_Account_Recovery', 'append_security_recovery_link' ), 20 );
			remove_action( 'admin_init', array( 'SMC_Account_Recovery', 'ensure_page' ), 30 );
			remove_action( 'admin_menu', array( 'SMC_Account_Recovery', 'admin_menu' ), 40 );
			foreach ( array( 'request', 'cancel', 'complete', 'approve', 'reject' ) as $action ) {
				remove_action( 'admin_post_smc_account_recovery_' . $action, array( 'SMC_Account_Recovery', 'handle_' . $action ) );
			}
		}
	}

	private static function replace_authorization_boundary() {
		remove_action( 'template_redirect', array( 'SMC_Authorization', 'enforce_frontend_state' ), 1 );
		remove_action( 'admin_init', array( 'SMC_Authorization', 'enforce_admin_state' ), 1 );
		remove_filter( 'rest_authentication_errors', array( 'SMC_Authorization', 'enforce_rest_state' ), 90 );
		remove_filter( 'user_has_cap', array( 'SMC_Authorization', 'filter_capabilities' ), 90 );
		add_action( 'template_redirect', array( __CLASS__, 'enforce_frontend_state' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_admin_state' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enforce_rest_state' ), 90 );
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_capabilities' ), 90, 4 );
	}

	public static function advanced_trust_allows_without_mfa( $user_id ) {
		if ( ! class_exists( 'SMC_Advanced_Trust_2026' ) ) { return true; }
		$user_id = absint( $user_id );
		if ( metadata_exists( 'user', $user_id, '_smc_trust_transition_hold_v1' ) ) { return false; }
		$containment = is_callable( array( 'SMC_Advanced_Trust_2026', 'containment_state' ) ) ? SMC_Advanced_Trust_2026::containment_state( $user_id ) : array( 'state' => 'clear' );
		$continuity = is_callable( array( 'SMC_Advanced_Trust_2026', 'continuity_state' ) ) ? SMC_Advanced_Trust_2026::continuity_state( $user_id ) : array( 'state' => 'active' );
		$reverification = is_callable( array( 'SMC_Advanced_Trust_2026', 'reverification_status' ) ) ? SMC_Advanced_Trust_2026::reverification_status( $user_id ) : array( 'applicable' => false, 'current' => true );
		$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );
		$reverification_stale = ! empty( $reverification['applicable'] ) && empty( $reverification['current'] );
		$critical = get_user_meta( $user_id, '_smc_critical_identity_change_v1', true );
		$critical_pending = is_array( $critical ) && 'reverification_required' === ( $critical['state'] ?? '' );
		$merge = get_user_meta( $user_id, '_smc_account_merge_v1', true );
		$merge_finalizing = is_array( $merge ) && 'finalizing' === ( $merge['state'] ?? '' );
		return 'clear' === ( $containment['state'] ?? 'unknown' )
			&& 'active' === ( $continuity['state'] ?? 'unknown' )
			&& ! $reverification_required && ! $reverification_stale && ! $critical_pending && ! $merge_finalizing;
	}

	public static function sensitive_action_authorized( $user_id, $action = 'default' ) {
		$user_id = absint( $user_id );
		$action = sanitize_key( $action );
		if ( ! $user_id || ! self::advanced_trust_allows_without_mfa( $user_id ) || ! class_exists( 'SMC_Advanced_Trust_2026' ) || ! is_callable( array( 'SMC_Advanced_Trust_2026', 'step_up_requirement' ) ) ) { return false; }
		$requirement = SMC_Advanced_Trust_2026::step_up_requirement( $user_id, $action ?: 'default' );
		return is_array( $requirement ) && ! empty( $requirement['satisfied'] );
	}

	private static function assertions_without_mfa( $user_id ) {
		$a = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( absint( $user_id ) ) : array();
		$a = is_array( $a ) ? $a : array();
		$a['mfa_required'] = false;
		$a['mfa_owner'] = 'none';
		$a['mfa_policy_version'] = self::POLICY_VERSION;
		$a['two_factor_ready'] = false;
		$a['session_two_factor'] = false;
		return $a;
	}

	public static function retire_mfa_assertions( $assertions, $user_id ) {
		$existing = is_array( $assertions ) ? $assertions : array();
		$advanced = $existing['advanced_trust'] ?? null;
		$merged = array_merge( $existing, self::assertions_without_mfa( $user_id ) );
		if ( null !== $advanced ) { $merged['advanced_trust'] = $advanced; }
		$merged['mfa_required'] = false;
		$merged['mfa_owner'] = 'none';
		$merged['two_factor_ready'] = false;
		$merged['session_two_factor'] = false;
		return $merged;
	}

	private static function restricted_capabilities() {
		$filtered = (array) apply_filters( 'smc_restricted_capabilities', self::$restricted_caps );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( self::$restricted_caps, $filtered ) ) ) ) );
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		unset( $args );
		$restricted = self::restricted_capabilities();
		if ( ! $user instanceof WP_User || ! array_intersect( (array) $caps, $restricted ) ) { return $allcaps; }
		$a = self::assertions_without_mfa( $user->ID );
		if ( empty( $a['eligible'] ) ) { foreach ( $restricted as $cap ) { $allcaps[ $cap ] = false; } }
		return $allcaps;
	}

	private static function request_action() { return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	private static function request_is_membership_surface() { return ( function_exists( 'smc_is_membership_page' ) && smc_is_membership_page() ) || wp_doing_cron(); }
	public static function enforce_frontend_state() {
		if ( ! is_user_logged_in() || is_admin() || self::request_is_membership_surface() ) { return; }
		$user_id = get_current_user_id();
		if ( ! apply_filters( 'smc_request_requires_membership', false, $user_id ) ) { return; }
		$a = self::assertions_without_mfa( $user_id );
		if ( ! empty( $a['suspended'] ) || empty( $a['approved'] ) || empty( $a['eligible'] ) ) { wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) ); exit; }
	}
	public static function enforce_admin_state() {
		if ( ! is_user_logged_in() || self::request_is_membership_surface() ) { return; }
		$user_id = get_current_user_id(); $a = self::assertions_without_mfa( $user_id );
		if ( ! empty( $a['suspended'] ) || empty( $a['eligible'] ) ) { wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) ); exit; }
		if ( 'smc_save_founder' === self::request_action() ) {
			$current = function_exists( 'smc_founder_user_id' ) ? smc_founder_user_id() : 0;
			$requested = isset( $_POST['founder_user_id'] ) ? absint( $_POST['founder_user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $current && $current !== $requested ) { wp_die( esc_html__( 'Founder reassignment is locked. Use Founder-approved change-control or the SMC_FOUNDER_USER_ID configuration constant.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		}
	}
	private static function rest_route() {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) { return '/' . ltrim( sanitize_text_field( (string) $GLOBALS['wp']->query_vars['rest_route'] ), '/' ); }
		if ( isset( $_GET['rest_route'] ) ) { return '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; $path = wp_parse_url( $uri, PHP_URL_PATH ); $prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/'; $position = is_string( $path ) ? strpos( $path, $prefix ) : false;
		return false === $position ? '' : '/' . ltrim( substr( $path, $position + strlen( $prefix ) ), '/' );
	}
	private static function deny( $code, $message, $status = 403 ) { return new WP_Error( sanitize_key( $code ), $message, array( 'status' => absint( $status ) ) ); }
	public static function enforce_rest_state( $result ) {
		if ( ! empty( $result ) || ! is_user_logged_in() ) { return $result; }
		$route = self::rest_route(); $method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ); $default = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$requires = (bool) apply_filters( 'smc_rest_request_requires_membership', $default, get_current_user_id(), $route, $method );
		if ( ! $requires ) { return $result; }
		$a = self::assertions_without_mfa( get_current_user_id() );
		if ( ! empty( $a['suspended'] ) ) { return self::deny( 'smc_membership_hard_block', __( 'This membership is under an explicit hard block.', 'sabri-membership-core' ) ); }
		if ( empty( $a['eligible'] ) ) { return self::deny( 'smc_membership_restricted', __( 'Membership approval and current eligibility are required.', 'sabri-membership-core' ) ); }
		return $result;
	}

	public static function mark_primary_session_current() {
		if ( ! is_user_logged_in() || ! class_exists( 'SMC_Security' ) ) { return; }
		$user_id = get_current_user_id(); $token = wp_get_session_token(); if ( ! $user_id || ! $token ) { return; }
		$hash = SMC_Security::blind_index( $token, 'session-token' ); if ( is_wp_error( $hash ) ) { return; }
		global $wpdb; $table = $wpdb->prefix . 'smc_auth_sessions'; if ( ! self::table_exists( $table ) ) { return; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,two_factor_at FROM {$table} WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1", $user_id, $hash ), ARRAY_A );
		if ( ! $row ) { if ( ! SMC_Security::register_session( $user_id, $token, time() + DAY_IN_SECONDS ) ) { return; } $row = $wpdb->get_row( $wpdb->prepare( "SELECT id,two_factor_at FROM {$table} WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1", $user_id, $hash ), ARRAY_A ); }
		if ( ! $row ) { return; }
		$stamped = ! empty( $row['two_factor_at'] ) ? strtotime( (string) $row['two_factor_at'] . ' UTC' ) : 0; if ( $stamped && $stamped >= time() - 5 * MINUTE_IN_SECONDS ) { return; }
		$now = current_time( 'mysql', true ); $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET two_factor_at=%s,last_totp_slice=NULL,updated_at=%s WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $now, $now, absint( $row['id'] ), $user_id ) );
	}

	public static function security_shortcode() {
		if ( ! is_user_logged_in() ) { return smc_notice( __( 'Please sign in through Sabri Authentication to continue.', 'sabri-membership-core' ), 'warning' ); }
		$user_id = get_current_user_id();
		ob_start(); ?>
		<main class="smc-panel" aria-labelledby="smc-security-title"><h1 id="smc-security-title"><?php esc_html_e( 'Membership Security', 'sabri-membership-core' ); ?></h1><?php echo smc_notice( __( 'File 00 no longer uses two-factor authentication, authenticator codes or recovery codes. Your normal account sign-in and password recovery remain with Sabri Authentication (File 02).', 'sabri-membership-core' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p><?php esc_html_e( 'Membership eligibility, identity assurance, guardian consent, verification state, audit evidence, session revocation and access restrictions remain active.', 'sabri-membership-core' ); ?></p><?php if ( class_exists( 'SMC_Workflow' ) && is_callable( array( 'SMC_Workflow', 'session_list' ) ) ) { SMC_Workflow::session_list( $user_id ); } ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form"><input type="hidden" name="action" value="smc_revoke_all_sessions"><?php wp_nonce_field( 'smc_revoke_all_sessions', 'smc_nonce' ); ?><label class="smc-check"><input type="checkbox" name="confirm_revoke_all" value="1" required> <?php esc_html_e( 'I understand this signs out every device, including this one.', 'sabri-membership-core' ); ?></label><button class="smc-button smc-button--danger"><?php esc_html_e( 'Revoke All Sessions', 'sabri-membership-core' ); ?></button></form></main><?php return ob_get_clean();
	}
	public static function retire_mfa_wording( $translated, $text, $domain ) {
		if ( 'sabri-membership-core' !== $domain ) { return $translated; }
		$replacements = array(
			'Approve, reject, suspend and restore require a current two-factor session. Appeals must be decided by an independent reviewer.' => 'Approve, reject, suspend and restore require a current authenticated reviewer session. Appeals must be decided by an independent reviewer.',
			'A current two-factor reviewer session is required for this high-risk decision.' => 'A current authenticated reviewer session is required for this high-risk decision.',
			'Founder reassignment is locked. Use the explicit audited recovery process or SMC_FOUNDER_USER_ID.' => 'Founder reassignment is locked. Use Founder-approved change-control or SMC_FOUNDER_USER_ID.',
		);
		return $replacements[ $text ] ?? $translated;
	}
	public static function retire_recovery_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$map = (array) get_option( 'smc_page_map', array() ); $id = ! empty( $map['recovery'] ) ? absint( $map['recovery'] ) : 0;
		if ( $id && 'page' === get_post_type( $id ) && '1' === get_post_meta( $id, '_smc_managed_page', true ) && 'draft' !== get_post_status( $id ) ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ); }
		if ( isset( $map['recovery'] ) ) { unset( $map['recovery'] ); update_option( 'smc_page_map', $map, false ); }
	}
	private static function table_exists( $table ) { global $wpdb; return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); }
	private static function flush_factor_user_caches( $user_ids ) {
		foreach ( array_unique( array_map( 'absint', (array) $user_ids ) ) as $user_id ) { if ( ! $user_id ) { continue; } wp_cache_delete( $user_id, 'user_meta' ); clean_user_cache( $user_id ); }
	}

	public static function retire_legacy_factor_state() {
		if ( ! current_user_can( 'manage_options' ) || SMC_DB_VERSION !== (string) get_option( 'smc_db_version', '' ) ) { return; }
		if ( class_exists( 'SMC_Installer' ) && is_callable( array( 'SMC_Installer', 'audit_infrastructure_ready' ) ) && ! SMC_Installer::audit_infrastructure_ready() ) { return; }
		$state = get_option( self::STATE_OPTION, array() ); if ( is_array( $state ) && SMC_VERSION === (string) ( $state['release'] ?? '' ) && ! empty( $state['completed_at'] ) ) { return; }
		global $wpdb;
		$meta_keys = array( '_smc_2fa_enabled', '_smc_totp_secret_enc', '_smc_totp_secret', '_smc_totp_pending_enc', '_smc_totp_pending_expires', '_smc_factor_replace_receipt', '_smc_recovery_receipt_v2', '_smc_recovery_receipt', '_smc_recovery_receipt_expires', '_smc_revalidation_required_at' );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$affected_user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})", $meta_keys ) );
		$recovery_table = $wpdb->prefix . 'smc_recovery_codes'; $factor_table = $wpdb->prefix . 'smc_mfa_factor_state'; $session_table = $wpdb->prefix . 'smc_auth_sessions'; $repair_table = $wpdb->prefix . 'smc_application_repairs';
		$meta_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})", $meta_keys ) );
		$recovery_rows = self::table_exists( $recovery_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$recovery_table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$factor_rows = self::table_exists( $factor_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$factor_table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return; }
		$ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})", $meta_keys ) );
		if ( $ok && self::table_exists( $recovery_table ) ) { $ok = false !== $wpdb->query( "DELETE FROM {$recovery_table}" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ok && self::table_exists( $factor_table ) ) { $ok = false !== $wpdb->query( "DELETE FROM {$factor_table}" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ok && self::table_exists( $session_table ) ) { $ok = false !== $wpdb->query( "UPDATE {$session_table} SET two_factor_at=NULL,last_totp_slice=NULL" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ok && self::table_exists( $repair_table ) ) { $ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$repair_table} SET status='cancelled',updated_at=%s WHERE repair_type=%s AND status IN ('requested','cooling','approved')", current_time( 'mysql', true ), 'lost_factor_recovery' ) ); }
		$details = array( 'policy_version' => self::POLICY_VERSION, 'release' => SMC_VERSION, 'legacy_user_meta_removed' => $meta_count, 'legacy_recovery_codes_removed' => $recovery_rows, 'legacy_factor_state_rows_removed' => $factor_rows, 'completed_at' => current_time( 'mysql', true ) );
		$audit_ok = $ok && class_exists( 'SMC_Security' ) && SMC_Security::audit( 'file00_mfa_system_retired', 0, $details );
		if ( ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return; }
		self::flush_factor_user_caches( $affected_user_ids );
		update_option( self::STATE_OPTION, $details, false );
	}
}

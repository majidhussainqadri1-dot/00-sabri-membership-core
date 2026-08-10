<?php
defined( 'ABSPATH' ) || exit;

/**
 * Founder-approved File 00 MFA retirement — 10 August 2026.
 *
 * File 00 no longer owns or requires an authenticator/TOTP, recovery codes,
 * per-session MFA challenge, authenticator replacement, or lost-factor
 * recovery. Password/login/account recovery remains an authentication-domain
 * responsibility (File 02). Historical audit rows are preserved; obsolete
 * factor secrets and recovery material are removed only after the current
 * File 00 database/audit migration is healthy.
 */
final class SMC_MFA_Retirement {
	const POLICY_VERSION = '2026-08-10-founder-mfa-retirement-v1';
	const STATE_OPTION   = 'smc_mfa_retirement_state_v1';

	private static $restricted_caps = array(
		'upload_files',
		'edit_posts',
		'publish_posts',
		'edit_published_posts',
		'delete_posts',
		'create_sabri_medical_content',
		'publish_sabri_medical_content',
		'smc_message_members',
		'smc_book_appointments',
		'smc_review_verification',
		'smc_view_private_documents',
		'smc_finalize_verification',
		'smc_manage_membership',
		'smc_manage_retention_holds',
	);

	public static function init() {
		self::remove_mfa_runtime_hooks();
		self::replace_authorization_boundary();

		remove_shortcode( 'smc_membership_security' );
		add_shortcode( 'smc_membership_security', array( __CLASS__, 'security_shortcode' ) );
		remove_shortcode( 'smc_membership_recovery' );

		add_filter( 'smc_assertions_v1', array( __CLASS__, 'retire_mfa_assertions' ), 999, 2 );
		add_action( 'admin_init', array( __CLASS__, 'retire_recovery_page' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'retire_legacy_factor_state' ), 100 );
	}

	private static function remove_mfa_runtime_hooks() {
		$mfa_actions = array(
			'start_2fa',
			'finish_2fa',
			'challenge_2fa',
			'rotate_recovery',
			'ack_recovery_receipt',
		);
		foreach ( $mfa_actions as $action ) {
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

	private static function hard_block_statuses() {
		if ( class_exists( 'SMC_Authorization' ) && is_callable( array( 'SMC_Authorization', 'hard_block_statuses' ) ) ) {
			return SMC_Authorization::hard_block_statuses();
		}
		return array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' );
	}

	private static function advanced_trust_allows_without_mfa( $user_id ) {
		if ( ! class_exists( 'SMC_Advanced_Trust_2026' ) ) {
			return true;
		}
		$user_id = absint( $user_id );
		if ( metadata_exists( 'user', $user_id, '_smc_trust_transition_hold_v1' ) ) {
			return false;
		}
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
			&& ! $reverification_required
			&& ! $reverification_stale
			&& ! $critical_pending
			&& ! $merge_finalizing;
	}

	private static function assertions_without_mfa( $user_id ) {
		$user_id = absint( $user_id );
		$a = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( $user_id ) : array();
		$a = is_array( $a ) ? $a : array();
		$status = sanitize_key( $a['status'] ?? '' );
		$hard_blocked = in_array( $status, self::hard_block_statuses(), true );
		$institutional = ! empty( $a['institutional_account'] );
		$contacts_current = $institutional || ( ! empty( $a['email_verified'] ) && ! empty( $a['phone_verified'] ) );
		$eligible = ! $hard_blocked
			&& ! empty( $a['approved'] )
			&& ! empty( $a['professional_verified'] )
			&& ! empty( $a['guardian_verified'] )
			&& $contacts_current
			&& ! empty( $a['identity_documents_current'] )
			&& self::advanced_trust_allows_without_mfa( $user_id );

		$a['hard_blocked'] = $hard_blocked;
		$a['eligible'] = (bool) $eligible;
		$a['mfa_required'] = false;
		$a['mfa_owner'] = 'none';
		$a['mfa_policy_version'] = self::POLICY_VERSION;
		$a['two_factor_ready'] = false;
		$a['session_two_factor'] = false;
		$a['can_message'] = (bool) $eligible;
		$a['can_comment'] = (bool) $eligible;
		$a['can_book_appointment'] = (bool) $eligible;
		$a['can_practice'] = (bool) ( $eligible && in_array( 'doctor', (array) ( $a['approved_membership_types'] ?? array() ), true ) );
		self::rewrite_publishing_assertions( $a, $user_id, $eligible );
		self::rewrite_transfer_assertions( $a, $eligible );
		return $a;
	}

	private static function rewrite_publishing_assertions( &$a, $user_id, $eligible ) {
		$approved_types = (array) ( $a['approved_membership_types'] ?? array() );
		$user = get_userdata( absint( $user_id ) );
		$is_founder = function_exists( 'smc_is_founder' ) && smc_is_founder( $user_id );
		$is_admin = $user && user_can( $user, 'manage_options' );
		$is_ai = function_exists( 'smc_is_institutional_ai' ) && smc_is_institutional_ai( $user_id );
		$is_doctor = in_array( 'doctor', $approved_types, true ) && ! empty( $a['professional_verified'] );
		$is_trusted = $user && user_can( $user, 'smc_trusted_publisher' );
		$trusted_direct = $is_trusted && user_can( $user, 'smc_direct_publish' );
		$doctor_direct = $is_doctor && $user && user_can( $user, 'smc_doctor_direct_publish' );
		$ai_policy = $is_ai && function_exists( 'smc_institutional_ai_policy' ) ? smc_institutional_ai_policy() : array();
		$can_submit = $eligible && ( $is_founder || $is_admin || $is_doctor || $is_trusted || $is_ai || array_intersect( array( 'teacher', 'researcher', 'publisher' ), $approved_types ) );
		$direct = $can_submit && ( $is_founder || $is_admin || $trusted_direct || $doctor_direct || ( $is_ai && ! empty( $ai_policy['low_risk_auto_publish'] ) ) );
		$publishing = is_array( $a['publishing'] ?? null ) ? $a['publishing'] : array();
		$publishing['can_open_composer'] = (bool) $can_submit;
		$publishing['can_submit_for_review'] = (bool) $can_submit;
		$publishing['can_direct_publish'] = (bool) $direct;
		$publishing['mfa_required'] = false;
		$a['publishing'] = $publishing;
		$a['can_publish'] = (bool) $can_submit;
		$a['can_direct_publish'] = (bool) $direct;
	}

	private static function rewrite_transfer_assertions( &$a, $eligible ) {
		$transfer = is_array( $a['transfer'] ?? null ) ? $a['transfer'] : array();
		$transfer['can_initiate'] = (bool) ( $eligible && empty( $a['suspended'] ) );
		$transfer['mfa_required'] = false;
		$a['transfer'] = $transfer;
		$a['can_transfer_files'] = (bool) $transfer['can_initiate'];
	}

	public static function retire_mfa_assertions( $assertions, $user_id ) {
		return array_merge( is_array( $assertions ) ? $assertions : array(), self::assertions_without_mfa( $user_id ) );
	}

	private static function restricted_capabilities() {
		$filtered = (array) apply_filters( 'smc_restricted_capabilities', self::$restricted_caps );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( self::$restricted_caps, $filtered ) ) ) ) );
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		unset( $args );
		$restricted = self::restricted_capabilities();
		if ( ! $user instanceof WP_User || ! array_intersect( (array) $caps, $restricted ) ) {
			return $allcaps;
		}
		$a = self::assertions_without_mfa( $user->ID );
		if ( empty( $a['eligible'] ) ) {
			foreach ( $restricted as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	private static function request_action() {
		return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function request_is_membership_surface() {
		if ( function_exists( 'smc_is_membership_page' ) && smc_is_membership_page() ) {
			return true;
		}
		return wp_doing_cron();
	}

	public static function enforce_frontend_state() {
		if ( ! is_user_logged_in() || is_admin() || self::request_is_membership_surface() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! apply_filters( 'smc_request_requires_membership', false, $user_id ) ) {
			return;
		}
		$a = self::assertions_without_mfa( $user_id );
		if ( ! empty( $a['hard_blocked'] ) || empty( $a['approved'] ) || empty( $a['eligible'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}
	}

	public static function enforce_admin_state() {
		if ( ! is_user_logged_in() || self::request_is_membership_surface() ) {
			return;
		}
		$user_id = get_current_user_id();
		$a = self::assertions_without_mfa( $user_id );
		if ( ! empty( $a['hard_blocked'] ) || empty( $a['eligible'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}
		if ( 'smc_save_founder' === self::request_action() ) {
			$current = function_exists( 'smc_founder_user_id' ) ? smc_founder_user_id() : 0;
			$requested = isset( $_POST['founder_user_id'] ) ? absint( $_POST['founder_user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $current && $current !== $requested ) {
				wp_die( esc_html__( 'Founder reassignment is locked. Use the explicit audited recovery process or SMC_FOUNDER_USER_ID.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
			}
		}
	}

	private static function rest_route() {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return '/' . ltrim( sanitize_text_field( (string) $GLOBALS['wp']->query_vars['rest_route'] ), '/' );
		}
		if ( isset( $_GET['rest_route'] ) ) {
			return '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$position = is_string( $path ) ? strpos( $path, $prefix ) : false;
		return false === $position ? '' : '/' . ltrim( substr( $path, $position + strlen( $prefix ) ), '/' );
	}

	private static function deny( $code, $message, $status = 403 ) {
		return new WP_Error( sanitize_key( $code ), $message, array( 'status' => absint( $status ) ) );
	}

	public static function enforce_rest_state( $result ) {
		if ( ! empty( $result ) || ! is_user_logged_in() ) {
			return $result;
		}
		$route = self::rest_route();
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		$default = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$requires = (bool) apply_filters( 'smc_rest_request_requires_membership', $default, get_current_user_id(), $route, $method );
		if ( ! $requires ) {
			return $result;
		}
		$a = self::assertions_without_mfa( get_current_user_id() );
		if ( ! empty( $a['hard_blocked'] ) ) {
			return self::deny( 'smc_membership_hard_block', __( 'This membership is under an explicit hard block.', 'sabri-membership-core' ) );
		}
		if ( empty( $a['eligible'] ) ) {
			return self::deny( 'smc_membership_restricted', __( 'Membership approval and current eligibility are required.', 'sabri-membership-core' ) );
		}
		return $result;
	}

	public static function security_shortcode() {
		if ( ! is_user_logged_in() ) {
			return smc_notice( __( 'Please sign in through Sabri Authentication to continue.', 'sabri-membership-core' ), 'warning' );
		}
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-security-title">
			<h1 id="smc-security-title"><?php esc_html_e( 'Membership Security', 'sabri-membership-core' ); ?></h1>
			<?php echo smc_notice( __( 'File 00 no longer uses two-factor authentication, authenticator codes or recovery codes. Your normal account sign-in and password recovery remain with Sabri Authentication (File 02).', 'sabri-membership-core' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'Membership eligibility, identity assurance, guardian consent, verification state, audit evidence and access restrictions remain active.', 'sabri-membership-core' ); ?></p>
		</main>
		<?php
		return ob_get_clean();
	}

	public static function retire_recovery_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$map = (array) get_option( 'smc_page_map', array() );
		$id = ! empty( $map['recovery'] ) ? absint( $map['recovery'] ) : 0;
		if ( $id && 'page' === get_post_type( $id ) && '1' === get_post_meta( $id, '_smc_managed_page', true ) && 'draft' !== get_post_status( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		}
		if ( isset( $map['recovery'] ) ) {
			unset( $map['recovery'] );
			update_option( 'smc_page_map', $map, false );
		}
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function retire_legacy_factor_state() {
		if ( ! current_user_can( 'manage_options' ) || SMC_DB_VERSION !== (string) get_option( 'smc_db_version', '' ) ) {
			return;
		}
		if ( class_exists( 'SMC_Installer' ) && is_callable( array( 'SMC_Installer', 'audit_infrastructure_ready' ) ) && ! SMC_Installer::audit_infrastructure_ready() ) {
			return;
		}
		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && SMC_VERSION === (string) ( $state['release'] ?? '' ) && ! empty( $state['completed_at'] ) ) {
			return;
		}

		global $wpdb;
		$meta_keys = array(
			'_smc_2fa_enabled',
			'_smc_totp_secret_enc',
			'_smc_totp_secret',
			'_smc_totp_pending_enc',
			'_smc_totp_pending_expires',
			'_smc_factor_replace_receipt',
			'_smc_recovery_receipt_v2',
			'_smc_recovery_receipt',
			'_smc_recovery_receipt_expires',
			'_smc_revalidation_required_at',
		);
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$meta_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})", $meta_keys ) );
		$deleted_meta = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})", $meta_keys ) );
		if ( false === $deleted_meta ) {
			return;
		}

		$recovery_table = $wpdb->prefix . 'smc_recovery_codes';
		$factor_table = $wpdb->prefix . 'smc_mfa_factor_state';
		$session_table = $wpdb->prefix . 'smc_auth_sessions';
		$repair_table = $wpdb->prefix . 'smc_application_repairs';
		$recovery_rows = self::table_exists( $recovery_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$recovery_table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$factor_rows = self::table_exists( $factor_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$factor_table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::table_exists( $recovery_table ) && false === $wpdb->query( "DELETE FROM {$recovery_table}" ) ) { return; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::table_exists( $factor_table ) && false === $wpdb->query( "DELETE FROM {$factor_table}" ) ) { return; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::table_exists( $session_table ) ) {
			$wpdb->query( "UPDATE {$session_table} SET two_factor_at=NULL,last_totp_slice=NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( self::table_exists( $repair_table ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$repair_table} SET status='cancelled',updated_at=%s WHERE repair_type=%s AND status IN ('requested','cooling','approved')", current_time( 'mysql', true ), 'lost_factor_recovery' ) );
		}

		$details = array(
			'policy_version' => self::POLICY_VERSION,
			'release' => SMC_VERSION,
			'legacy_user_meta_removed' => $meta_count,
			'legacy_recovery_codes_removed' => $recovery_rows,
			'legacy_factor_state_rows_removed' => $factor_rows,
			'completed_at' => current_time( 'mysql', true ),
		);
		if ( class_exists( 'SMC_Security' ) && ! SMC_Security::audit( 'file00_mfa_system_retired', 0, $details ) ) {
			return;
		}
		update_option( self::STATE_OPTION, $details, false );
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Canonical authorization boundary for File 00 and its protected consumers.
 *
 * This class replaces the broad legacy request exemptions with explicit
 * recovery allowlists, keeps public/safe reading available, and ensures that
 * Founder/Administrator institutional precedence never becomes a bypass for
 * an explicit hard block or a current two-factor challenge.
 */
final class SMC_Authorization {
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

	private static $recovery_actions = array(
		'smc_submit_application',
		'smc_request_contact_otp',
		'smc_verify_contact_otp',
		'smc_start_2fa',
		'smc_finish_2fa',
		'smc_challenge_2fa',
		'smc_rotate_recovery',
		'smc_revoke_session',
		'smc_resubmit',
		'smc_appeal',
		'smc_withdraw_guardian',
		'smc_verify_guardian',
	);

	public static function init() {
		// Replace the 1.2.3 boundary handlers after SMC_Contracts::init() has run.
		remove_action( 'template_redirect', array( 'SMC_Contracts', 'enforce_frontend_state' ), 1 );
		remove_action( 'admin_init', array( 'SMC_Contracts', 'enforce_admin_state' ), 1 );
		remove_filter( 'rest_authentication_errors', array( 'SMC_Contracts', 'enforce_rest_state' ), 90 );
		remove_filter( 'user_has_cap', array( 'SMC_Contracts', 'filter_capabilities' ), 90 );

		add_action( 'template_redirect', array( __CLASS__, 'enforce_frontend_state' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_admin_state' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enforce_rest_state' ), 90 );
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_capabilities' ), 90, 4 );
	}

	private static function restricted_capabilities() {
		$filtered = (array) apply_filters( 'smc_restricted_capabilities', self::$restricted_caps );
		$caps = array_merge( self::$restricted_caps, $filtered );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $caps ) ) ) );
	}

	public static function hard_block_statuses() {
		$baseline = array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' );
		$filtered = (array) apply_filters( 'smc_hard_block_statuses', $baseline );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $baseline, $filtered ) ) ) ) );
	}

	public static function is_hard_blocked( $user_id ) {
		$state = smc_membership_state( absint( $user_id ) );
		return in_array( sanitize_key( $state['status'] ?? '' ), self::hard_block_statuses(), true );
	}

	private static function assertions( $user_id ) {
		$assertions = SMC_Contracts::assertions( absint( $user_id ) );
		$hard_blocked = in_array( sanitize_key( $assertions['status'] ?? '' ), self::hard_block_statuses(), true );
		$guardian_ok = ! array_key_exists( 'guardian_verified', $assertions ) || ! empty( $assertions['guardian_verified'] );
		$institutional = ! empty( $assertions['institutional_account'] );
		$contact_ok = $institutional || (
			! empty( $assertions['email_verified'] ) &&
			! empty( $assertions['phone_verified'] )
		);
		$assertions['hard_blocked'] = $hard_blocked;
		$assertions['effective_eligible'] = ! $hard_blocked && ! empty( $assertions['eligible'] ) && $guardian_ok && $contact_ok;
		return $assertions;
	}

	private static function request_action() {
		return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function recovery_actions() {
		$filtered = (array) apply_filters( 'smc_membership_recovery_actions', self::$recovery_actions );
		$actions = array_merge( self::$recovery_actions, $filtered );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $actions ) ) ) );
	}

	private static function request_is_membership_recovery() {
		if ( smc_is_membership_page() || wp_doing_cron() ) {
			return true;
		}
		$action = self::request_action();
		return $action && in_array( $action, self::recovery_actions(), true );
	}

	private static function request_is_file00_admin() {
		$action = self::request_action();
		if ( $action && 0 === strpos( $action, 'smc_' ) ) {
			return true;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $page && 0 === strpos( $page, 'smc-' );
	}

	private static function user_is_administrator( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return $user && user_can( $user, 'manage_options' );
	}

	private static function deny( $code, $message, $status = 403 ) {
		return new WP_Error(
			sanitize_key( $code ),
			$message,
			array( 'status' => absint( $status ) )
		);
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		unset( $args );
		$restricted = self::restricted_capabilities();
		if ( ! $user instanceof WP_User || ! array_intersect( (array) $caps, $restricted ) ) {
			return $allcaps;
		}
		$assertions = self::assertions( $user->ID );
		if ( empty( $assertions['effective_eligible'] ) || empty( $assertions['session_two_factor'] ) ) {
			foreach ( $restricted as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	public static function enforce_frontend_state() {
		if ( ! is_user_logged_in() || is_admin() || self::request_is_membership_recovery() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! apply_filters( 'smc_request_requires_membership', false, $user_id ) ) {
			return;
		}
		$assertions = self::assertions( $user_id );
		if ( ! empty( $assertions['hard_blocked'] ) || empty( $assertions['approved'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}
		if ( empty( $assertions['effective_eligible'] ) || empty( $assertions['session_two_factor'] ) ) {
			wp_safe_redirect( smc_page_url( 'security', '/membership-security/' ) );
			exit;
		}
	}

	public static function enforce_admin_state() {
		if ( ! is_user_logged_in() || self::request_is_membership_recovery() ) {
			return;
		}
		$user_id = get_current_user_id();
		$assertions = self::assertions( $user_id );

		// Privileged WordPress administration is a sensitive surface. Founder or
		// Administrator identity never bypasses an explicit membership hard block,
		// stale eligibility, containment/reverification hold, or current MFA gate.
		// Explicit recovery actions remain available through the exact allowlist above.
		if ( ! empty( $assertions['hard_blocked'] ) || empty( $assertions['effective_eligible'] ) || empty( $assertions['session_two_factor'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}

		// Founder identity is immutable through the ordinary settings form once
		// configured. Recovery/reassignment must use an explicit audited process
		// or the preferred SMC_FOUNDER_USER_ID configuration constant.
		if ( 'smc_save_founder' === self::request_action() ) {
			$current = smc_founder_user_id();
			$requested = isset( $_POST['founder_user_id'] ) ? absint( $_POST['founder_user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $current && $current !== $requested ) {
				wp_die(
					esc_html__( 'Founder reassignment is locked. Use the explicit audited recovery process or SMC_FOUNDER_USER_ID.', 'sabri-membership-core' ),
					'',
					array( 'response' => 409 )
				);
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

	private static function rest_is_recovery_route( $route, $method ) {
		$route  = '/' . ltrim( (string) $route, '/' );
		$method = strtoupper( (string) $method );
		$rules  = (array) apply_filters( 'smc_membership_recovery_rest_routes', array() );
		foreach ( $rules as $key => $rule ) {
			if ( is_string( $rule ) ) {
				$candidate = $rule;
				$methods   = array( 'POST' );
			} elseif ( is_array( $rule ) ) {
				$candidate = isset( $rule['route'] ) ? $rule['route'] : ( is_string( $key ) ? $key : '' );
				$methods   = isset( $rule['methods'] ) ? (array) $rule['methods'] : array( 'POST' );
			} else {
				continue;
			}
			$candidate = '/' . ltrim( (string) $candidate, '/' );
			$methods   = array_values( array_unique( array_map( 'strtoupper', array_map( 'strval', $methods ) ) ) );
			if ( $candidate === $route && in_array( $method, $methods, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function enforce_rest_state( $result ) {
		if ( ! empty( $result ) || ! is_user_logged_in() ) {
			return $result;
		}
		$route = self::rest_route();
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		if ( self::rest_is_recovery_route( $route, $method ) ) {
			return $result;
		}
		$default = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$requires = (bool) apply_filters( 'smc_rest_request_requires_membership', $default, get_current_user_id(), $route, $method );
		if ( ! $requires ) {
			return $result;
		}
		$assertions = self::assertions( get_current_user_id() );
		if ( ! empty( $assertions['hard_blocked'] ) ) {
			return self::deny( 'smc_membership_hard_block', __( 'This membership is under an explicit hard block.', 'sabri-membership-core' ) );
		}
		if ( empty( $assertions['effective_eligible'] ) || empty( $assertions['session_two_factor'] ) ) {
			return self::deny( 'smc_membership_restricted', __( 'Membership approval, current eligibility, and a current two-factor challenge are required.', 'sabri-membership-core' ) );
		}
		return $result;
	}
}

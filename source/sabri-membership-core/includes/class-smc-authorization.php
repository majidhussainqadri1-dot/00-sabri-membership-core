<?php
defined( 'ABSPATH' ) || exit;

/**
 * Canonical authorization boundary for File 00 and its protected consumers.
 *
 * This class replaces the broad legacy request exemptions with explicit
 * recovery allowlists, keeps public/safe reading available, and ensures that
 * Founder/Administrator institutional precedence never becomes a bypass for
 * an explicit hard block or a current membership/trust restriction.
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
		'smc_revoke_session',
		'smc_revoke_all_sessions',
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
		// Cross-file consumers of the public assertion filter receive the same
		// action-time age fail-closed semantics as File 00 authorization itself.
		add_filter( 'smc_assertions_v1', array( __CLASS__, 'filter_current_age_assertion' ), 100, 2 );
		add_filter( 'spf_file00_authorization_claim', array( __CLASS__, 'file01_authorization_claim' ), 10, 2 );
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

	/**
	 * Recompute current age/jurisdiction eligibility synchronously.
	 *
	 * The daily lifecycle sweep persists durable state changes, but an approved
	 * account must not retain protected authority between a birthday/policy
	 * boundary and the next cron run. Institutional accounts keep their separate
	 * evidence-attention governance and are not blanket-suspended here.
	 */
	private static function current_age_eligible( $user_id, $assertions = array() ) {
		$user_id = absint( $user_id );
		$assertions = is_array( $assertions ) ? $assertions : array();
		if ( ! empty( $assertions['institutional_account'] ) || smc_is_institutional_account( $user_id ) ) {
			return true;
		}
		$app = smc_application( $user_id );
		if ( ! is_array( $app ) || empty( $app['date_of_birth_enc'] ) ) {
			return false;
		}
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
		$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		$minimum = smc_effective_minimum_age( $app['gender'] ?? '', $app['residence_country'] ?? '' );
		if ( false === $age || false === $minimum || $age < $minimum ) {
			return false;
		}
		$types = isset( $assertions['requested_membership_types'] )
			? (array) $assertions['requested_membership_types']
			: SMC_Contracts::requested_types( $user_id );
		if ( $age < 18 && array_intersect( array_map( 'sanitize_key', $types ), smc_professional_types() ) ) {
			return false;
		}
		return true;
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
		$age_ok = self::current_age_eligible( $user_id, $assertions );
		$assertions['hard_blocked'] = $hard_blocked;
		$assertions['age_eligible'] = $age_ok;
		$assertions['effective_eligible'] = ! $hard_blocked && ! empty( $assertions['eligible'] ) && $guardian_ok && $contact_ok && $age_ok;
		return $assertions;
	}

	/** Keep the versioned public assertion surface fail-closed at action time. */
	public static function filter_current_age_assertion( $assertions, $user_id ) {
		$assertions = is_array( $assertions ) ? $assertions : array();
		$age_ok = self::current_age_eligible( $user_id, $assertions );
		$assertions['age_eligible'] = $age_ok;
		if ( $age_ok ) {
			return $assertions;
		}
		$assertions['eligible'] = false;
		foreach ( array( 'can_message', 'can_comment', 'can_book_appointment', 'can_practice', 'can_publish', 'can_direct_publish', 'can_transfer_files' ) as $key ) {
			if ( array_key_exists( $key, $assertions ) ) { $assertions[ $key ] = false; }
		}
		if ( isset( $assertions['publishing'] ) && is_array( $assertions['publishing'] ) ) {
			$assertions['publishing']['can_open_composer'] = false;
			$assertions['publishing']['can_direct_publish'] = false;
		}
		if ( isset( $assertions['transfer'] ) && is_array( $assertions['transfer'] ) ) {
			$assertions['transfer']['can_initiate'] = false;
		}
		if ( isset( $assertions['clinical_commerce'] ) && is_array( $assertions['clinical_commerce'] ) ) {
			$assertions['clinical_commerce']['eligible'] = false;
			$assertions['clinical_commerce']['appointment_allowed'] = false;
			$assertions['clinical_commerce']['doctor_practice_allowed'] = false;
			$assertions['clinical_commerce']['marketplace_direct_deal_allowed'] = false;
			$assertions['clinical_commerce']['marketplace_seller_actions_allowed'] = false;
		}
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


	/**
	 * Produce the canonical File 00 authorization claim consumed by File 01.
	 *
	 * The claim is in-process evidence only: it is short-lived, actor/action/
	 * object/purpose bound, and never grants authority beyond the exact File 01
	 * contract understood by this release. Unsupported or malformed requests
	 * return null so the consumer remains fail-closed.
	 */
	public static function file01_authorization_claim( $claim, $request ) {
		if ( null !== $claim ) {
			return $claim;
		}
		if ( ! is_array( $request ) ) {
			return null;
		}

		$user_id      = absint( $request['user_id'] ?? 0 );
		$actor_id     = absint( $request['actor_id'] ?? 0 );
		$action       = (string) ( $request['action'] ?? '' );
		$capability   = (string) ( $request['capability'] ?? '' );
		$purpose      = (string) ( $request['purpose'] ?? '' );
		$plugin       = (string) ( $request['plugin'] ?? '' );
		$contract     = (string) ( $request['contract'] ?? '' );
		$object_hash  = strtolower( trim( (string) ( $request['object_hash'] ?? '' ) ) );
		$request_time = absint( $request['current_time'] ?? 0 );

		if ( ! $user_id || $user_id !== $actor_id || $user_id !== get_current_user_id() ) {
			return null;
		}
		if ( '' === $action || $action !== sanitize_key( $action ) || '' === $purpose || $purpose !== sanitize_key( $purpose ) ) {
			return null;
		}
		if ( 'file-01' !== $plugin || SMC_FILE01_FOUNDATION_CONTRACT_VERSION !== $contract ) {
			return null;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $object_hash ) ) {
			return null;
		}
		if ( ! $request_time || abs( time() - $request_time ) > 120 ) {
			return null;
		}

		$required = self::file01_required_capability( $action );
		if ( '' === $required || $required !== $capability || $capability !== sanitize_key( $capability ) ) {
			return null;
		}

		$assertions = self::assertions( $user_id );
		$is_founder = smc_is_founder( $user_id );
		$is_admin   = self::user_is_administrator( $user_id );
		$role       = $is_founder ? 'founder' : ( $is_admin ? 'administrator' : 'member' );
		$allowed    = ! empty( $assertions['effective_eligible'] ) && empty( $assertions['hard_blocked'] );
		if ( $allowed ) {
			if ( $is_founder ) {
				$allowed = true;
			} elseif ( $is_admin ) {
				$allowed = in_array( $action, array( 'view', 'system_check', 'run_system_check' ), true );
			} else {
				$allowed = false;
			}
		}

		$now = time();
		return array(
			'claim_version'      => SMC_FILE01_AUTH_CLAIM_VERSION,
			'allowed'            => (bool) $allowed,
			'user_id'            => (int) $user_id,
			'actor_id'           => (int) $user_id,
			'action'             => $action,
			'capability'         => $required,
			'issued_at'          => $now,
			'expires_at'         => $now + 60,
			'claim_id'           => 'smc-f01:' . strtolower( wp_generate_uuid4() ),
			'object_hash'        => $object_hash,
			'purpose'            => $purpose,
			'institutional_role' => $role,
			'plugin'             => 'file-01',
			'contract'           => SMC_FILE01_FOUNDATION_CONTRACT_VERSION,
			'suspended'          => ! empty( $assertions['hard_blocked'] ) || ! empty( $assertions['suspended'] ),
			'revoked'            => ! empty( $assertions['revoked'] ),
		);
	}

	private static function file01_required_capability( $action ) {
		$action = sanitize_key( $action );
		if ( in_array( $action, array( 'view', 'system_check' ), true ) ) {
			return 'view_sabri_foundation';
		}
		if ( 'run_system_check' === $action ) {
			return 'manage_sabri_foundation';
		}
		if ( in_array( $action, array( 'record_release', 'transition_release', 'run_reconciliation', 'run_schema_upgrade' ), true ) ) {
			return 'release_sabri_foundation';
		}
		if ( in_array( $action, array( 'approve_release', 'deploy_release', 'approve_amendment', 'production_cutover' ), true ) ) {
			return 'govern_sabri_foundation';
		}
		if ( 'purge' === $action ) {
			return 'purge_sabri_foundation';
		}
		return '';
	}

	/**
	 * Appeals must never return to the actor who imposed the latest rejection or
	 * suspension. Enforce this before both claim and decision handlers so a
	 * losing appellant receives an actually independent reviewer on either path.
	 */
	private static function enforce_appeal_reviewer_independence() {
		$action = self::request_action();
		if ( ! in_array( $action, array( 'smc_assign_review', 'smc_review_transition' ), true ) ) {
			return;
		}
		global $wpdb;
		$request = false;
		if ( 'smc_assign_review' === $action ) {
			$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $request_id ) {
				$request = $wpdb->get_row( $wpdb->prepare( "SELECT id,user_id,status,queue_type FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d LIMIT 1", $request_id ), ARRAY_A );
			}
		} else {
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $user_id ) {
				$request = $wpdb->get_row( $wpdb->prepare( "SELECT id,user_id,status,queue_type FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
			}
		}
		if ( ! is_array( $request ) || ( 'appeal_review' !== sanitize_key( $request['status'] ?? '' ) && 'appeal' !== sanitize_key( $request['queue_type'] ?? '' ) ) ) {
			return;
		}
		$previous_actor = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT actor_id FROM {$wpdb->prefix}smc_verification_events WHERE request_id=%d AND new_status IN ('rejected','suspended') ORDER BY id DESC LIMIT 1",
				(int) $request['id']
			)
		);
		if ( $previous_actor && $previous_actor === get_current_user_id() ) {
			wp_die(
				esc_html__( 'An appeal must be claimed and decided by a reviewer independent of the latest rejection or suspension actor.', 'sabri-membership-core' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		unset( $args );
		$restricted = self::restricted_capabilities();
		if ( ! $user instanceof WP_User || ! array_intersect( (array) $caps, $restricted ) ) {
			return $allcaps;
		}
		$assertions = self::assertions( $user->ID );
		if ( empty( $assertions['effective_eligible'] ) ) {
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
		if ( empty( $assertions['effective_eligible'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
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
		// stale eligibility or containment/reverification hold. File 00 MFA is retired.
		// Explicit membership recovery actions remain available through the exact allowlist above.
		if ( ! empty( $assertions['hard_blocked'] ) || empty( $assertions['effective_eligible'] ) ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}

		self::enforce_appeal_reviewer_independence();

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
		if ( empty( $assertions['effective_eligible'] ) ) {
			return self::deny( 'smc_membership_restricted', __( 'Membership approval and current eligibility are required.', 'sabri-membership-core' ) );
		}
		return $result;
	}
}

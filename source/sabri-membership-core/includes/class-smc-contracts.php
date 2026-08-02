<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Contracts {
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
	);

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'register_account' ), 30, 1 );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'record_session' ), 10, 6 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'revoke_current_session' ) );
		add_action( 'profile_update', array( __CLASS__, 'email_changed' ), 30, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'enforce_frontend_state' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_admin_state' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enforce_rest_state' ), 90 );
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_capabilities' ), 90, 4 );
		add_filter( 'spd_can_view_profile', array( __CLASS__, 'filter_profile_visibility' ), 10, 3 );
		add_filter( 'smc_assertions_v1', array( __CLASS__, 'filter_assertions' ), 10, 2 );
		add_action( 'updated_user_meta', array( __CLASS__, 'contact_changed' ), 10, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'contact_changed' ), 10, 4 );
	}

	public static function register_account( $user_id ) {
		global $wpdb;
		if ( smc_privacy_erasure_lock( $user_id ) || smc_application( $user_id ) ) {
			return;
		}
		$type = sanitize_key( get_user_meta( $user_id, '_sa_account_type', true ) );
		$type = isset( smc_account_types()[ $type ] ) ? $type : 'member';
		$now  = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$ok   = $wpdb->insert(
			$wpdb->prefix . 'smc_applications',
			array(
				'user_id'           => absint( $user_id ),
				'membership_type'   => $type,
				'status'            => 'draft',
				'profile_visibility'=> 'private',
				'policy_version'    => smc_policy()['version'],
				'row_version'       => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$role_ok = 1 === $ok && self::set_exact_role( $user_id, smc_role_for_type( $type, false ) );
		$audit_ok = $role_ok && SMC_Security::audit( 'account_imported', $user_id, array( 'source' => 'wordpress' ) );
		if ( 1 !== $ok || ! $role_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			SMC_Security::audit( 'account_import_failed', $user_id, array( 'db_error' => $wpdb->last_error ) );
			return;
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
	}

	public static function assertions( $user_id ) {
		$user_id = absint( $user_id );
		$state   = smc_membership_state( $user_id );
		$row     = $state['application_exists'] ? smc_application( $user_id ) : false;
		$status  = $state['status'];
		$type    = $state['membership_type'];
		$two_factor_ready = SMC_Security::two_factor_ready( $user_id );
		$session_verified = SMC_Security::session_is_verified( $user_id );
		$approved = (bool) $state['approved'];
		$professional_verified = ! smc_is_professional_type( $type ) || self::professional_verified( $user_id, $type );
		$phone_verified = self::contact_verified( $user_id, 'mobile' );
		$email_verified = self::contact_verified( $user_id, 'email' );
		$guardian_verified = ! $row || empty( $row['guardian_required'] ) || self::guardian_verified( $user_id );
		$contacts_verified = (bool) $state['institutional_account'] || ( $phone_verified && $email_verified );
		$eligible = $approved && $professional_verified && $two_factor_ready && $guardian_verified && $contacts_verified;
		$user = get_userdata( $user_id );
		$roles = $user ? (array) $user->roles : array();
		return array(
			'contract_version'       => SMC_CONTRACT_VERSION,
			'user_id'                => $user_id,
			'application_exists'     => (bool) $state['application_exists'],
			'institutional_account'  => (bool) $state['institutional_account'],
			'account_class'          => $state['account_class'],
			'membership_type'        => $type,
			'status'                 => $status,
			'approved'               => $approved,
			'suspended'              => in_array( $status, array( 'suspended', 'rejected', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' ), true ),
			'eligible'               => $eligible,
			'two_factor_ready'       => $two_factor_ready,
			'session_two_factor'     => $session_verified,
			'phone_verified'         => $phone_verified,
			'email_verified'         => $email_verified,
			'guardian_verified'      => $guardian_verified,
			'professional_verified'  => $professional_verified,
			'can_message'            => $eligible && $session_verified,
			'can_comment'            => $eligible && $session_verified,
			'can_book_appointment'   => $eligible && $session_verified,
			'can_publish'            => $eligible && $session_verified && ( smc_is_founder( $user_id ) || in_array( 'sabri_doctor_verified', $roles, true ) ),
			'can_practice'           => $eligible && 'doctor' === $type && in_array( 'sabri_doctor_verified', $roles, true ),
			'public_profile_allowed' => $eligible && $row && ( 'public' === $row['profile_visibility'] || (bool) apply_filters( 'smc_public_profile_opt_in', false, $user_id, $row ) ),
		);
	}

	public static function contact_verified( $user_id, $channel ) {
		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, array( 'email', 'mobile' ), true ) ) {
			return false;
		}
		$user = get_userdata( absint( $user_id ) );
		$app = smc_application( $user_id );
		if ( ! $user || ! $app ) {
			return false;
		}
		if ( 'email' === $channel ) {
			$target = $user->user_email;
		} else {
			$target = SMC_Security::decrypt( $app['phone_e164_enc'], 'membership-phone', array( 'user_id' => absint( $user_id ) ) );
			if ( is_wp_error( $target ) ) {
				return false;
			}
		}
		$hash = SMC_Security::blind_index( $target, 'contact-target' );
		if ( is_wp_error( $hash ) ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND verified_at IS NOT NULL LIMIT 1",
				absint( $user_id ),
				$channel,
				$hash
			)
		);
	}

	public static function communication_assertions( $user_id ) {
		$a = self::assertions( $user_id );
		return array(
			'contract_version' => $a['contract_version'],
			'user_id'          => $a['user_id'],
			'status'           => $a['status'],
			'eligible'         => $a['can_message'],
			'phone_verified'   => $a['phone_verified'],
			'can_message'      => $a['can_message'],
			'can_call'         => $a['can_message'],
			'suspended'        => $a['suspended'],
		);
	}

	public static function filter_assertions( $assertions, $user_id ) {
		return array_merge( is_array( $assertions ) ? $assertions : array(), self::assertions( $user_id ) );
	}

	public static function guardian_verified( $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND status='verified' AND policy_version=%s AND withdrawn_at IS NULL LIMIT 1",
				absint( $user_id ),
				(string) smc_policy()['version']
			)
		);
	}

	public static function professional_verified( $user_id, $type = '' ) {
		if ( ! smc_is_professional_type( $type ) ) {
			return true;
		}
		if ( 'doctor' === $type ) {
			if ( class_exists( 'SPD_Helpers' ) ) {
				return 'verified' === SPD_Helpers::verification_status( $user_id );
			}
			return 'verified' === get_user_meta( $user_id, '_spd_verification_status', true );
		}
		return (bool) apply_filters( 'smc_professional_verification_state', false, absint( $user_id ), sanitize_key( $type ) );
	}

	public static function set_exact_role( $user_id, $role ) {
		$user = new WP_User( absint( $user_id ) );
		if ( smc_privacy_erasure_lock( $user_id ) || ! $user->exists() || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		foreach ( smc_managed_roles() as $managed ) {
			$user->remove_role( $managed );
		}
		$user->add_role( sanitize_key( $role ) );
		return in_array( $role, (array) $user->roles, true );
	}

	public static function record_session( $logged_in_cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		SMC_Security::register_session( absint( $user_id ), (string) $token, (int) $expiration );
	}

	public static function revoke_current_session() {
		SMC_Security::revoke_session( get_current_user_id(), wp_get_session_token() );
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! empty( $allcaps['manage_options'] ) || ! array_intersect( (array) $caps, self::$restricted_caps ) ) {
			return $allcaps;
		}
		$a = self::assertions( $user->ID );
		if ( ! $a['eligible'] || ! $a['session_two_factor'] ) {
			foreach ( self::$restricted_caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	private static function request_is_membership_recovery() {
		if ( smc_is_membership_page() ) {
			return true;
		}
		if ( wp_doing_cron() ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $action, 'smc_' ) || 0 === strpos( $action, 'sa_' );
	}

	public static function enforce_frontend_state() {
		if ( ! is_user_logged_in() || is_admin() || self::request_is_membership_recovery() ) {
			return;
		}
		if ( ! apply_filters( 'smc_request_requires_membership', false, get_current_user_id() ) ) {
			return;
		}
		$a = self::assertions( get_current_user_id() );
		if ( ! $a['approved'] ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}
		if ( ! $a['session_two_factor'] ) {
			wp_safe_redirect( smc_page_url( 'security', '/membership-security/' ) );
			exit;
		}
	}

	public static function enforce_admin_state() {
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) || self::request_is_membership_recovery() ) {
			return;
		}
		$a = self::assertions( get_current_user_id() );
		if ( ! $a['eligible'] || ! $a['session_two_factor'] ) {
			wp_safe_redirect( smc_page_url( 'status', '/membership-status/' ) );
			exit;
		}
	}

	public static function enforce_rest_state( $result ) {
		if ( ! empty( $result ) || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return $result;
		}
		$a = self::assertions( get_current_user_id() );
		if ( ! $a['eligible'] || ! $a['session_two_factor'] ) {
			return new WP_Error( 'smc_membership_restricted', __( 'Membership approval and a current two-factor challenge are required.', 'sabri-membership-core' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	public static function filter_profile_visibility( $allowed, $profile_user_id, $viewer_user_id ) {
		$viewer = get_userdata( absint( $viewer_user_id ) );
		if ( absint( $profile_user_id ) === absint( $viewer_user_id ) || ( $viewer && ( ! empty( $viewer->allcaps['smc_review_verification'] ) || ! empty( $viewer->allcaps['manage_options'] ) ) ) ) {
			return true;
		}
		return self::assertions( $profile_user_id )['public_profile_allowed'];
	}

	private static function invalidate_contact_assertion( $user_id, $channel, $reason ) {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'smc_contact_otps',
			array( 'user_id' => absint( $user_id ), 'channel' => sanitize_key( $channel ) ),
			array( '%d', '%s' )
		);
		$sessions_ok = SMC_Security::revoke_all_sessions( $user_id, $reason );
		$audit_ok = SMC_Security::audit( 'contact_reverification_required', $user_id, array( 'channel' => sanitize_key( $channel ), 'reason' => sanitize_key( $reason ) ) );
		return false !== $deleted && $sessions_ok && $audit_ok;
	}

	public static function contact_changed( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( '_sa_phone' !== $meta_key ) {
			return;
		}
		$app = smc_application( $user_id );
		if ( ! $app ) {
			return;
		}
		$phone = smc_normalize_phone( $meta_value );
		$phone_enc = is_wp_error( $phone ) ? null : SMC_Security::encrypt( $phone, 'membership-phone', array( 'user_id' => absint( $user_id ) ) );
		$phone_hash = is_wp_error( $phone ) ? null : SMC_Security::blind_index( $phone, 'phone' );
		if ( is_wp_error( $phone_enc ) || is_wp_error( $phone_hash ) ) {
			$phone_enc = null;
			$phone_hash = null;
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->update(
			$wpdb->prefix . 'smc_applications',
			array( 'phone_e164_enc' => $phone_enc, 'phone_hash' => $phone_hash, 'row_version' => (int) $app['row_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => absint( $user_id ), 'row_version' => (int) $app['row_version'] ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
		$invalidated = 1 === $updated && self::invalidate_contact_assertion( $user_id, 'mobile', 'contact_changed' );
		if ( 1 !== $updated || ! $invalidated ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			self::invalidate_contact_assertion( $user_id, 'mobile', 'contact_sync_failed' );
			SMC_Security::audit( 'contact_sync_failed', $user_id, array( 'channel' => 'mobile' ) );
			return;
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
	}

	public static function email_changed( $user_id, $old_user_data ) {
		$current = get_userdata( absint( $user_id ) );
		if ( ! $current || ! $old_user_data instanceof WP_User || hash_equals( (string) $old_user_data->user_email, (string) $current->user_email ) ) {
			return;
		}
		self::invalidate_contact_assertion( $user_id, 'email', 'email_changed' );
	}
}

function smc_membership_assertions( $user_id ) {
	return SMC_Contracts::assertions( $user_id );
}

function smc_communication_assertions( $user_id ) {
	return SMC_Contracts::communication_assertions( $user_id );
}

<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Contracts {
	private const DOCTOR_APPROVED_ROLE = 'sabri_doctor_verified';
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
		$grant_ok = 1 === $ok && self::upsert_role_grant( $user_id, $type, 'pending', 1, 0 );
		$role_ok = $grant_ok && self::sync_wordpress_roles( $user_id );
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
		$requested_types = self::requested_types( $user_id );
		$approved_types  = self::approved_types( $user_id );
		$professional_verified = true;
		foreach ( $requested_types as $requested_type ) {
			if ( smc_is_professional_type( $requested_type ) && ! self::professional_verified( $user_id, $requested_type ) ) {
				$professional_verified = false;
				break;
			}
		}
		$phone_verified = self::contact_verified( $user_id, 'mobile' );
		$email_verified = self::contact_verified( $user_id, 'email' );
		$guardian_verified = ! $row || empty( $row['guardian_required'] ) || self::guardian_verified( $user_id );
		$institutional = (bool) $state['institutional_account'];
		$contacts_verified = $institutional || ( $phone_verified && $email_verified );
		$identity_documents_current = $institutional || self::identity_documents_current( $user_id );
		$eligible = $approved && $professional_verified && $two_factor_ready && $guardian_verified && $contacts_verified && $identity_documents_current;
		$suspended = in_array( $status, array( 'suspended', 'rejected', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' ), true );
		$base = array(
			'contract_version'       => SMC_CONTRACT_VERSION,
			'user_id'                => $user_id,
			'application_exists'     => (bool) $state['application_exists'],
			'institutional_account'  => $institutional,
			'institutional_ai'       => smc_is_institutional_ai( $user_id ),
			'account_class'          => $state['account_class'],
			'membership_type'        => $type,
			'requested_membership_types' => $requested_types,
			'approved_membership_types'  => $approved_types,
			'status'                 => $status,
			'approved'               => $approved,
			'suspended'              => $suspended,
			'eligible'               => $eligible,
			'two_factor_ready'       => $two_factor_ready,
			'session_two_factor'     => $session_verified,
			'phone_verified'         => $phone_verified,
			'email_verified'         => $email_verified,
			'guardian_verified'      => $guardian_verified,
			'professional_verified'  => $professional_verified,
			'identity_documents_current' => $identity_documents_current,
			'can_message'            => $eligible && $session_verified,
			'can_comment'            => $eligible && $session_verified,
			'can_book_appointment'   => $eligible && $session_verified,
			'can_practice'           => $eligible && in_array( 'doctor', $approved_types, true ),
			'public_profile_allowed' => $eligible && ( smc_is_institutional_ai( $user_id ) || ( $row && ( 'public' === $row['profile_visibility'] || (bool) apply_filters( 'smc_public_profile_opt_in', false, $user_id, $row ) ) ) ),
		);
		$base['entitlements'] = self::entitlement_assertions( $user_id, $base );
		$base['publishing']   = self::publishing_assertions( $user_id, $base );
		$base['transfer']     = self::transfer_assertions( $user_id, 0, array(), $base );
		$base['can_publish']  = (bool) $base['publishing']['can_open_composer'];
		$base['can_direct_publish'] = (bool) $base['publishing']['can_direct_publish'];
		$base['can_transfer_files'] = (bool) $base['transfer']['can_initiate'];
		if ( $base['institutional_ai'] ) {
			$base['ai_identity'] = smc_institutional_ai_policy();
		}
		/* File 00 advanced trust containment/continuity is authoritative for protected actions. */
		if ( class_exists( 'SMC_Advanced_Trust_2026' ) && ! SMC_Advanced_Trust_2026::protected_actions_allowed( $user_id ) ) {
			$base['eligible'] = false;
			$base['can_message'] = false;
			$base['can_comment'] = false;
			$base['can_book_appointment'] = false;
			$base['can_practice'] = false;
			$base['can_publish'] = false;
			$base['can_direct_publish'] = false;
			$base['can_transfer_files'] = false;
			if ( isset( $base['publishing'] ) && is_array( $base['publishing'] ) ) {
				$base['publishing']['can_open_composer'] = false;
				$base['publishing']['can_submit_for_review'] = false;
				$base['publishing']['can_direct_publish'] = false;
			}
			if ( isset( $base['transfer'] ) && is_array( $base['transfer'] ) ) {
				$base['transfer']['can_initiate'] = false;
			}
		}
		return $base;
	}

	public static function entitlement_assertions( $user_id, $base = null ) {
		$policy = smc_policy();
		return array(
			'policy_version'        => (string) $policy['version'],
			'financial_baseline'    => 'free',
			'single_free_tier'      => true,
			'paid_unlocks_enabled'  => false,
			'legacy_pricing_enabled'=> false,
			'base_services'         => array_fill_keys( (array) $policy['base_services'], true ),
			'donation_optional'     => true,
			'donation_affects_entitlement' => false,
			'donation_affects_capability'  => false,
			'donation_affects_visibility'  => false,
			'donation_affects_support'     => false,
			'commission_percent'    => 0,
		);
	}

	public static function publishing_assertions( $user_id, $base = null ) {
		$base = is_array( $base ) ? $base : self::assertions( $user_id );
		$approved_types = (array) ( $base['approved_membership_types'] ?? array() );
		$is_founder = smc_is_founder( $user_id );
		$user = get_userdata( absint( $user_id ) );
		$is_admin = $user && user_can( $user, 'manage_options' );
		$is_ai = smc_is_institutional_ai( $user_id );
		$is_doctor = in_array( 'doctor', $approved_types, true ) && ! empty( $base['professional_verified'] );
		/* Publishing authority is a File 00 capability fact, never an arbitrary filter-provided badge. */
		$is_trusted = $user && user_can( $user, 'smc_trusted_publisher' );
		$trusted_direct = $is_trusted && user_can( $user, 'smc_direct_publish' );
		$doctor_direct = $is_doctor && $user && user_can( $user, 'smc_doctor_direct_publish' );
		$ai_policy = $is_ai ? smc_institutional_ai_policy() : array();
		$can_submit = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && ( $is_founder || $is_admin || $is_doctor || $is_trusted || $is_ai || array_intersect( array( 'teacher', 'researcher', 'publisher' ), $approved_types ) );
		$direct = $can_submit && ( $is_founder || $is_admin || $trusted_direct || $doctor_direct || ( $is_ai && ! empty( $ai_policy['low_risk_auto_publish'] ) ) );
		$authority = $is_founder ? 'founder' : ( $is_admin ? 'administrator' : ( $is_ai ? 'institutional_ai_publisher' : ( $is_trusted ? 'trusted_publisher' : ( $is_doctor ? 'verified_doctor' : 'submission_only' ) ) ) );
		return array(
			'policy_version'       => 'CHAT-AI-001/RCD-020-v2.1',
			'authority_class'      => $authority,
			'can_open_composer'    => (bool) $can_submit,
			'can_submit_for_review'=> (bool) $can_submit,
			'can_direct_publish'   => (bool) $direct,
			'requires_human_review'=> (bool) ( $is_ai && empty( $ai_policy['low_risk_auto_publish'] ) ),
			'doctor_verification_claim' => $is_ai ? false : (bool) $is_doctor,
			'ai_generated_disclosure_required' => (bool) $is_ai,
			'donation_or_payment_advantage' => false,
		);
	}

	public static function transfer_assertions( $user_id, $recipient_id = 0, $context = array(), $base = null ) {
		$base = is_array( $base ) ? $base : self::assertions( $user_id );
		$recipient_id = absint( $recipient_id );
		$relationship = 0 === $recipient_id ? true : (bool) apply_filters( 'smc_transfer_relationship_authorized', false, absint( $user_id ), $recipient_id, $context );
		$consent = 0 === $recipient_id ? true : (bool) apply_filters( 'smc_transfer_consent_authorized', false, absint( $user_id ), $recipient_id, $context );
		$content_policy = (bool) apply_filters( 'smc_transfer_content_policy_authorized', true, absint( $user_id ), $recipient_id, $context );
		$can = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && empty( $base['suspended'] ) && $relationship && $consent && $content_policy;
		return array(
			'policy_version'       => 'CHAT-XFER-001-v2.1',
			'can_initiate'         => (bool) $can,
			'max_file_bytes'       => 1073741824,
			'recipient_authorization_required' => true,
			'relationship_authorized' => (bool) $relationship,
			'consent_authorized'   => (bool) $consent,
			'content_policy_authorized' => (bool) $content_policy,
			'copyright_recheck_required' => true,
			'clinical_confidentiality_recheck_required' => true,
			'abuse_fair_use_recheck_required' => true,
			'signed_expiring_delivery_required' => true,
			'public_url_allowed'   => false,
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
			'can_transfer_files'=> $a['can_transfer_files'],
			'max_file_bytes'   => $a['transfer']['max_file_bytes'],
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
			/* File 09 is canonical. Explicit claims must be typed, current and freshly asserted. */
			$claim = apply_filters( 'smc_file09_doctor_verification_claim_v1', null, absint( $user_id ) );
			if ( is_array( $claim ) ) {
				$status = sanitize_key( $claim['status'] ?? '' );
				$owner = sanitize_key( $claim['owner'] ?? '' );
				$contract = (string) ( $claim['contract_version'] ?? '' );
				$asserted_at = absint( $claim['asserted_at'] ?? 0 );
				$expires_at = absint( $claim['expires_at'] ?? 0 );
				$fresh = $asserted_at > 0 && $asserted_at <= time() + 60 && $asserted_at >= time() - 5 * MINUTE_IN_SECONDS;
				return 'file09' === $owner
					&& '1.0.0' === $contract
					&& array_key_exists( 'current', $claim ) && ! empty( $claim['current'] )
					&& $fresh
					&& ( 0 === $expires_at || $expires_at > time() )
					&& in_array( $status, array( 'verified', 'active' ), true );
			}
			/* SPD_Helpers is the installed canonical File 09 compatibility adapter. */
			if ( class_exists( 'SPD_Helpers' ) ) {
				return 'verified' === SPD_Helpers::verification_status( $user_id );
			}
			/* Never infer professional truth from stale display/user-meta when File 09 is absent. */
			return false;
		}
		return (bool) apply_filters( 'smc_professional_verification_state', false, absint( $user_id ), sanitize_key( $type ) );
	}

	public static function identity_documents_current( $user_id ) {
		global $wpdb;
		$required = array_keys( smc_required_identity_documents() );
		if ( ! $required ) {
			return true;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT document_key FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d AND scan_status='passed' AND status='approved' AND (expiry_date IS NULL OR expiry_date>=UTC_DATE())",
				absint( $user_id )
			),
			ARRAY_A
		);
		$approved = array_unique( array_map( 'sanitize_key', array_column( (array) $rows, 'document_key' ) ) );
		return ! array_diff( $required, $approved );
	}

	private static function role_type( $role ) {
		$role = sanitize_key( $role );
		foreach ( array_keys( smc_account_types() ) as $type ) {
			if ( $role === smc_role_for_type( $type, false ) ) {
				return array( $type, 'pending' );
			}
			if ( $role === smc_role_for_type( $type, true ) ) {
				return array( $type, 'approved' );
			}
		}
		return false;
	}

	private static function grants_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'smc_role_grants';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function role_grants( $user_id ) {
		global $wpdb;
		if ( ! self::grants_table_exists() ) {
			$app = smc_application( $user_id );
			return $app ? array( array( 'membership_type' => sanitize_key( $app['membership_type'] ), 'status' => 'approved' === $app['status'] ? 'approved' : 'pending' ) ) : array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d ORDER BY membership_type", absint( $user_id ) ), ARRAY_A );
	}

	public static function requested_types( $user_id ) {
		$types = array();
		foreach ( self::role_grants( $user_id ) as $grant ) {
			if ( in_array( $grant['status'], array( 'pending', 'approved', 'suspended' ), true ) ) {
				$types[] = sanitize_key( $grant['membership_type'] );
			}
		}
		return smc_sanitize_membership_types( $types );
	}

	public static function approved_types( $user_id ) {
		$types = array();
		foreach ( self::role_grants( $user_id ) as $grant ) {
			if ( 'approved' === $grant['status'] && ( empty( $grant['expires_at'] ) || strtotime( $grant['expires_at'] . ' UTC' ) >= time() ) ) {
				$types[] = sanitize_key( $grant['membership_type'] );
			}
		}
		return array_values( array_unique( array_intersect( array_keys( smc_account_types() ), $types ) ) );
	}

	public static function upsert_role_grant( $user_id, $type, $status, $application_version = 1, $actor_id = 0 ) {
		global $wpdb;
		$type = sanitize_key( $type );
		$status = sanitize_key( $status );
		if ( ! isset( smc_account_types()[ $type ] ) || ! in_array( $status, array( 'pending', 'approved', 'suspended', 'rejected' ), true ) || ! self::grants_table_exists() ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_role_grants (user_id,membership_type,status,source_application_version,approved_by,approved_at,created_at,updated_at)
			VALUES (%d,%s,%s,%d,%d,NULLIF(%s,''),%s,%s)
			ON DUPLICATE KEY UPDATE status=VALUES(status),source_application_version=VALUES(source_application_version),approved_by=VALUES(approved_by),approved_at=VALUES(approved_at),updated_at=VALUES(updated_at)",
			absint( $user_id ), $type, $status, max( 1, absint( $application_version ) ), absint( $actor_id ), 'approved' === $status ? $now : '', $now, $now
		);
		return false !== $wpdb->query( $sql );
	}

	public static function replace_requested_types( $user_id, $types, $application_version ) {
		global $wpdb;
		$types = smc_sanitize_membership_types( $types );
		if ( ! self::grants_table_exists() ) {
			return false;
		}
		$current = self::role_grants( $user_id );
		foreach ( $current as $grant ) {
			if ( ! in_array( $grant['membership_type'], $types, true ) ) {
				$wpdb->delete( $wpdb->prefix . 'smc_role_grants', array( 'id' => (int) $grant['id'] ), array( '%d' ) );
			}
		}
		foreach ( $types as $type ) {
			if ( ! self::upsert_role_grant( $user_id, $type, 'pending', $application_version, 0 ) ) {
				return false;
			}
		}
		return self::sync_wordpress_roles( $user_id );
	}

	public static function sync_wordpress_roles( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user || smc_privacy_erasure_lock( $user_id ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		$desired = array();
		foreach ( self::role_grants( $user_id ) as $grant ) {
			if ( ! in_array( $grant['status'], array( 'pending', 'approved' ), true ) ) {
				continue;
			}
			$desired[] = smc_role_for_type( $grant['membership_type'], 'approved' === $grant['status'] );
		}
		$desired = array_values( array_unique( array_filter( $desired ) ) );
		foreach ( smc_membership_roles() as $managed ) {
			if ( ! in_array( $managed, $desired, true ) ) {
				$user->remove_role( $managed );
			}
		}
		foreach ( $desired as $role ) {
			$user->add_role( $role );
		}
		$actual = (array) get_userdata( absint( $user_id ) )->roles;
		return ! array_diff( $desired, $actual ) && ! array_diff( array_intersect( $actual, smc_membership_roles() ), $desired );
	}

	public static function approve_requested_roles( $user_id, $application_version, $actor_id ) {
		$types = self::requested_types( $user_id );
		foreach ( $types as $type ) {
			if ( ! self::upsert_role_grant( $user_id, $type, 'approved', $application_version, $actor_id ) ) {
				return false;
			}
		}
		return self::sync_wordpress_roles( $user_id );
	}

	public static function set_all_roles_pending( $user_id, $application_version = 1 ) {
		$types = self::requested_types( $user_id );
		foreach ( $types as $type ) {
			if ( ! self::upsert_role_grant( $user_id, $type, 'pending', $application_version, 0 ) ) {
				return false;
			}
		}
		return self::sync_wordpress_roles( $user_id );
	}

	/** Backward-compatible single-role mutation that preserves all other grants. */
	public static function set_exact_role( $user_id, $role ) {
		$parsed = self::role_type( $role );
		if ( ! $parsed ) {
			return false;
		}
		$app = smc_application( $user_id );
		$version = $app ? (int) $app['row_version'] : 1;
		if ( ! self::upsert_role_grant( $user_id, $parsed[0], $parsed[1], $version, get_current_user_id() ) ) {
			return false;
		}
		return self::sync_wordpress_roles( $user_id );
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
		if ( smc_is_membership_page() || wp_doing_cron() ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$baseline = array(
			'smc_submit_application', 'smc_request_contact_otp', 'smc_verify_contact_otp',
			'smc_start_2fa', 'smc_finish_2fa', 'smc_challenge_2fa', 'smc_rotate_recovery', 'smc_revoke_session',
			'smc_verify_guardian', 'smc_resubmit', 'smc_appeal', 'smc_withdraw_guardian',
			'smc_save_application_draft', 'smc_clear_application_draft',
		);
		$filtered = array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters( 'smc_membership_recovery_actions', $baseline ) ) ) );
		$allowed = array_values( array_unique( array_merge( $baseline, $filtered ) ) );
		return '' !== $action && in_array( $action, $allowed, true );
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

function smc_entitlement_assertions( $user_id ) { return SMC_Contracts::entitlement_assertions( $user_id ); }
function smc_publishing_assertions( $user_id ) { return SMC_Contracts::publishing_assertions( $user_id ); }
function smc_transfer_assertions( $user_id, $recipient_id = 0, $context = array() ) { return SMC_Contracts::transfer_assertions( $user_id, $recipient_id, $context ); }

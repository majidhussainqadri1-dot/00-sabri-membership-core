<?php
defined( 'ABSPATH' ) || exit;

/**
 * Advanced Membership, Identity and Trust Extensions — 7 August 2026.
 *
 * File 00 remains the membership/identity-assurance authority. Authentication
 * ceremonies stay in File 02, professional credential truth stays in File 09,
 * and search/ranking stays in File 26. This class adds privacy-minimal trust
 * assertions and audited governance workflows without creating parallel owners.
 */
final class SMC_Advanced_Trust_2026 {
	const CONTRACT_VERSION = '1.0.0';
	const POLICY_VERSION = '2026-08-07-ext-v1.0';
	const REVOCATION_META = '_smc_trust_revocation_epoch';
	const REVERIFY_META = '_smc_reverification_v1';
	const GUARDIAN_SUCCESSION_META = '_smc_guardian_succession_v1';
	const MERGE_META = '_smc_account_merge_v1';
	const CONTAINMENT_META = '_smc_security_containment_v1';
	const DELEGATION_META = '_smc_delegated_authority_v1';
	const CONTINUITY_META = '_smc_continuity_state_v1';
	const SERVICE_IDENTITY_META = '_smc_service_identity_v1';
	const CRITICAL_IDENTITY_META = '_smc_critical_identity_change_v1';
	const REVERIFY_CURSOR_OPTION = 'smc_reverification_cursor_v1';
	const BREAK_GLASS_OPTION = 'smc_break_glass_requests_v1';
	const BREAK_GLASS_TTL = 900;
	const PROOF_MAX_TTL = 300;
	const REVOCATION_PROPAGATION_SLA = 60;

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
		'smc_finalize_verification',
		'smc_manage_membership',
		'smc_manage_retention_holds',
		'smc_restore_membership',
		'smc_manage_repairs',
	);

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_capabilities' ), 95, 4 );
		add_filter( 'smc_assertions_v1', array( __CLASS__, 'augment_assertions' ), 95, 2 );
		add_action( 'smc_lifecycle_daily', array( __CLASS__, 'daily_reverification_sweep' ), 30 );
	}

	/** F00-EXT-001 — Identity Assurance Levels. */
	public static function assurance_profile( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return self::hidden_profile();
		}
		$base = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( $user_id ) : array();
		$auth = self::authentication_assurance( $user_id );
		$identity = ! empty( $base['identity_documents_current'] );
		$contacts = ! empty( $base['phone_verified'] ) && ! empty( $base['email_verified'] );
		$guardian = ! empty( $base['guardian_verified'] );
		$professional = ! empty( $base['professional_verified'] );
		$membership = ! empty( $base['approved'] ) && empty( $base['suspended'] );
		$identity_level = 0;
		if ( $membership ) { $identity_level = 1; }
		if ( $membership && $contacts ) { $identity_level = 2; }
		if ( $membership && $contacts && $identity && $guardian ) { $identity_level = 3; }
		if ( $identity_level >= 3 && $professional ) { $identity_level = 4; }
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'subject' => self::subject_reference( $user_id ),
			'identity_assurance_level' => $identity_level,
			'authentication_assurance_level' => (int) $auth['level'],
			'authentication_contract_version' => (string) ( $auth['contract_version'] ?? '1.0.0' ),
			'authentication_owner' => (string) $auth['owner'],
			'authentication_method' => (string) $auth['method'],
			'authentication_verified_at' => absint( $auth['verified_at'] ?? 0 ),
			'authentication_risk' => sanitize_key( $auth['risk'] ?? 'unknown' ),
			'user_verified' => ! empty( $auth['user_verified'] ),
			'phishing_resistant' => ! empty( $auth['phishing_resistant'] ),
			'session_bound' => ! empty( $auth['session_bound'] ),
			'fingerprint_bound' => ! empty( $auth['fingerprint_bound'] ),
			'hardware_backed' => ! empty( $auth['hardware_backed'] ),
			'passkey_asserted' => ! empty( $auth['passkey_asserted'] ),
			'membership_current' => $membership,
			'contacts_current' => $contacts,
			'guardian_current' => $guardian,
			'identity_current' => $identity,
			'professional_current' => $professional,
			'reverification' => self::reverification_status( $user_id ),
			'containment' => self::containment_state( $user_id ),
			'continuity' => self::continuity_state( $user_id ),
			'revocation_epoch' => self::revocation_epoch( $user_id ),
		);
	}

	private static function hidden_profile() {
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'subject' => '',
			'identity_assurance_level' => 0,
			'authentication_assurance_level' => 0,
			'authentication_contract_version' => 'none',
			'authentication_owner' => 'none',
			'authentication_method' => 'none',
			'authentication_verified_at' => 0,
			'authentication_risk' => 'unknown',
			'user_verified' => false,
			'phishing_resistant' => false,
			'session_bound' => false,
			'fingerprint_bound' => false,
			'hardware_backed' => false,
			'passkey_asserted' => false,
			'membership_current' => false,
			'contacts_current' => false,
			'guardian_current' => false,
			'identity_current' => false,
			'professional_current' => false,
			'reverification' => array( 'current' => false, 'due_at' => 0 ),
			'containment' => array( 'state' => 'unknown' ),
			'continuity' => array( 'state' => 'unknown' ),
			'revocation_epoch' => 0,
		);
	}

	/** F00-EXT-002 — File 02 Passkey/WebAuthn assurance adapter. */
	public static function authentication_assurance( $user_id ) {
		$user_id = absint( $user_id );
		$session_mfa = class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $user_id );
		$session_verified_at = $session_mfa && method_exists( 'SMC_Security', 'session_verified_at' ) ? absint( SMC_Security::session_verified_at( $user_id ) ) : 0;
		if ( $session_mfa && $session_verified_at <= 0 ) { $session_mfa = false; }
		$baseline = array(
			'contract_version' => '1.0.0', 'owner' => 'file00', 'level' => $session_mfa ? 2 : 1,
			'method' => $session_mfa ? 'file00_totp_or_recovery' : 'primary_authentication_unasserted',
			'passkey_asserted' => false, 'hardware_backed' => false, 'user_verified' => false,
			'phishing_resistant' => false, 'risk' => 'unknown', 'session_bound' => false,
			'fingerprint_bound' => false, 'verified_at' => $session_verified_at,
		);
		$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );
		$v2 = apply_filters( 'smc_file02_authentication_assurance_v2', null, $user_id );
		if ( is_array( $v2 ) ) {
			$verified_at = absint( $v2['verified_at'] ?? 0 );
			$level = max( (int) $baseline['level'], min( 4, absint( $v2['level'] ?? 0 ) ) );
			$risk = sanitize_key( $v2['risk'] ?? 'unknown' );
			if ( ! in_array( $risk, array( 'unknown', 'low', 'normal', 'elevated', 'high' ), true ) ) { $risk = 'unknown'; }
			$owner_ok = 'file02' === sanitize_key( $v2['owner'] ?? '' );
			$contract_ok = '2.0.0' === (string) ( $v2['contract_version'] ?? '' );
			$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;
			$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;
			$session_bound = ! empty( $v2['session_bound'] );
			$fingerprint_bound = ! empty( $v2['fingerprint_bound'] );
			if ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation || ! $session_bound || ! $fingerprint_bound ) { return $baseline; }
			return array(
				'contract_version' => '2.0.0', 'owner' => 'file02', 'level' => $level,
				'method' => sanitize_key( $v2['method'] ?? 'file02_authentication_assurance_v2' ) ?: 'file02_authentication_assurance_v2',
				'passkey_asserted' => ! empty( $v2['passkey_asserted'] ), 'hardware_backed' => ! empty( $v2['hardware_backed'] ),
				'user_verified' => ! empty( $v2['user_verified'] ), 'phishing_resistant' => ! empty( $v2['phishing_resistant'] ),
				'risk' => $risk, 'session_bound' => true, 'fingerprint_bound' => true, 'verified_at' => $verified_at,
			);
		}
		$claim = apply_filters( 'smc_file02_authentication_assurance_v1', $baseline, $user_id );
		if ( ! is_array( $claim ) ) { return $baseline; }
		$level = max( 0, min( 4, absint( $claim['level'] ?? $baseline['level'] ) ) );
		$method = sanitize_key( $claim['method'] ?? $baseline['method'] );
		$verified_at = absint( $claim['verified_at'] ?? 0 );
		$owner = sanitize_key( $claim['owner'] ?? '' );
		$owner_ok = 'file02' === $owner;
		$contract_ok = '1.0.0' === (string) ( $claim['contract_version'] ?? '' );
		$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;
		$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;
		$elevated = $level > (int) $baseline['level'] || ! empty( $claim['passkey_asserted'] ) || ! empty( $claim['hardware_backed'] );
		if ( $verified_at > time() + 60 || ( $elevated && ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation ) ) ) { return $baseline; }
		if ( ! $elevated ) { return $baseline; }
		return array(
			'contract_version' => '1.0.0', 'owner' => 'file02', 'level' => $level,
			'method' => $method ?: 'file02_authentication_assurance', 'passkey_asserted' => ! empty( $claim['passkey_asserted'] ),
			'hardware_backed' => ! empty( $claim['hardware_backed'] ), 'user_verified' => false,
			'phishing_resistant' => false, 'risk' => 'unknown', 'session_bound' => false,
			'fingerprint_bound' => false, 'verified_at' => $verified_at,
		);
	}

	/** F00-EXT-003 — Adaptive step-up verification. */
	public static function step_up_requirement( $user_id, $action ) {
		$action = sanitize_key( $action );
		$profile = self::assurance_profile( $user_id );
		$requirements = array(
			'default' => array( 2, 2, false, false ),
			'profile_sensitive_change' => array( 3, 2, false, false ),
			'identity_change' => array( 3, 2, false, false ),
			'guardian_change' => array( 3, 2, false, false ),
			'delegation_grant' => array( 3, 2, false, false ),
			'account_merge' => array( 4, 2, false, false ),
			'professional_approval' => array( 4, 2, false, false ),
			'founder_recovery' => array( 4, 3, true, true ),
			'break_glass' => array( 4, 3, true, true ),
		);
		$required = isset( $requirements[ $action ] ) ? $requirements[ $action ] : $requirements['default'];
		$risk = (array) apply_filters( 'smc_file24_step_up_context_v1', array(), absint( $user_id ), $action );
		$authentication_risk = sanitize_key( $profile['authentication_risk'] ?? 'unknown' );
		$high_risk = ! empty( $risk['high_risk'] ) || in_array( $authentication_risk, array( 'elevated', 'high' ), true );
		if ( $high_risk ) {
			$required[0] = max( $required[0], 3 ); $required[1] = max( $required[1], 3 ); $required[3] = true;
		}
		$membership_operational = self::protected_actions_allowed( absint( $user_id ) );
		$satisfied = $membership_operational
			&& (int) $profile['identity_assurance_level'] >= $required[0]
			&& (int) $profile['authentication_assurance_level'] >= $required[1]
			&& ( ! $required[2] || ! empty( $profile['hardware_backed'] ) )
			&& ( ! $required[3] || ! empty( $profile['phishing_resistant'] ) );
		return array(
			'action' => $action, 'required_identity_level' => $required[0], 'required_authentication_level' => $required[1],
			'hardware_backed_required' => (bool) $required[2], 'phishing_resistant_required' => (bool) $required[3],
			'current_identity_level' => (int) $profile['identity_assurance_level'], 'current_authentication_level' => (int) $profile['authentication_assurance_level'],
			'current_phishing_resistant' => ! empty( $profile['phishing_resistant'] ), 'authentication_risk' => $authentication_risk, 'membership_operational' => (bool) $membership_operational,
			'satisfied' => (bool) $satisfied, 'risk_context' => array( 'high_risk' => (bool) $high_risk ),
		);
	}

	/** F00-EXT-004 — Periodic reverification. */
	public static function reverification_status( $user_id ) {
		$user_id = absint( $user_id );
		$state = get_user_meta( $user_id, self::REVERIFY_META, true );
		$state = is_array( $state ) ? $state : array();
		$interval = absint( apply_filters( 'smc_reverification_interval_seconds', YEAR_IN_SECONDS, $user_id ) );
		$interval = max( DAY_IN_SECONDS, $interval );
		$verified_at = absint( $state['verified_at'] ?? 0 );
		$applicable = $verified_at > 0;
		$source = sanitize_key( $state['source'] ?? '' );
		if ( $verified_at <= 0 ) {
			$baseline = self::initial_reverification_baseline( $user_id );
			$applicable = ! empty( $baseline['applicable'] );
			$verified_at = absint( $baseline['verified_at'] ?? 0 );
			$source = sanitize_key( $baseline['source'] ?? '' );
		}
		$due_at = absint( $state['due_at'] ?? ( $verified_at ? $verified_at + $interval : 0 ) );
		$current = ! $applicable || ( $verified_at > 0 && $due_at > time() );
		return array(
			'applicable' => (bool) $applicable,
			'current' => (bool) $current,
			'verified_at' => $verified_at,
			'due_at' => $due_at,
			'overdue' => $applicable && ( $due_at <= 0 || $due_at <= time() ),
			'interval_seconds' => $interval,
			'source' => $source,
		);
	}

	private static function initial_reverification_baseline( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return array( 'applicable' => false, 'verified_at' => 0, 'source' => 'none' );
		}
		$approved_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT approved_at FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d AND status='approved' AND approved_at IS NOT NULL ORDER BY approved_at DESC LIMIT 1",
				$user_id
			)
		);
		$source = 'role_grant_approval';
		if ( ! $approved_at ) {
			$approved_at = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(decided_at,updated_at,created_at) FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status='approved' ORDER BY id DESC LIMIT 1",
					$user_id
				)
			);
			$source = 'membership_approval';
		}
		if ( ! $approved_at ) {
			return array( 'applicable' => false, 'verified_at' => 0, 'source' => 'none' );
		}
		$timestamp = strtotime( (string) $approved_at . ' UTC' );
		return array(
			'applicable' => true,
			'verified_at' => $timestamp > 0 ? $timestamp : 0,
			'source' => $source,
		);
	}

	public static function mark_reverified( $user_id, $actor_id = 0, $source = 'manual_review' ) {
		$user_id = absint( $user_id );
		$actor_id = absint( $actor_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'smc_reverify_subject', __( 'A valid membership subject is required.', 'sabri-membership-core' ) );
		}
		$authorized = $actor_id > 0 ? self::actor_is_current( $actor_id, 'smc_finalize_verification' ) && SMC_Security::session_is_verified( $actor_id ) : self::system_reverification_authorized( $user_id, $source );
		if ( ! $authorized ) {
			return new WP_Error( 'smc_reverify_authorization', __( 'Reverification requires an authorized reviewer or explicitly authorized system adapter.', 'sabri-membership-core' ) );
		}
		$now = time();
		$interval = max( DAY_IN_SECONDS, absint( apply_filters( 'smc_reverification_interval_seconds', YEAR_IN_SECONDS, $user_id ) ) );
		$state = array( 'verified_at' => $now, 'due_at' => $now + $interval, 'source' => sanitize_key( $source ), 'actor_id' => $actor_id );
		if ( ! self::write_user_meta_verified( $user_id, self::REVERIFY_META, $state ) ) {
			return new WP_Error( 'smc_reverify_store', __( 'Reverification could not be committed safely.', 'sabri-membership-core' ) );
		}
		delete_user_meta( $user_id, '_smc_reverification_required' );
		if ( metadata_exists( 'user', $user_id, '_smc_reverification_required' ) ) {
			return new WP_Error( 'smc_reverify_marker', __( 'The reverification hold could not be cleared safely.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'membership_reverified', $user_id, array( 'source' => $state['source'] ) ) ) {
			update_user_meta( $user_id, '_smc_reverification_required', 1 );
			return new WP_Error( 'smc_reverify_audit', __( 'Reverification audit could not be committed safely.', 'sabri-membership-core' ) );
		}
		$revocation = self::bump_revocation_epoch( $user_id, 'membership_reverified' );
		if ( false === $revocation ) {
			update_user_meta( $user_id, '_smc_reverification_required', 1 );
			return new WP_Error( 'smc_reverify_revocation', __( 'Reverification could not be propagated safely.', 'sabri-membership-core' ) );
		}
		return $state;
	}

	public static function daily_reverification_sweep() {
		global $wpdb;
		$batch = 200;
		$last_id = max( 0, absint( get_option( self::REVERIFY_CURSOR_OPTION, 0 ) ) );
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->users ) ) { return; }
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d", $last_id, $batch ) );
		$cursor = $last_id;
		foreach ( (array) $ids as $user_id ) {
			$user_id = absint( $user_id );
			$status = self::reverification_status( $user_id );
			if ( ! empty( $status['overdue'] ) && ! get_user_meta( $user_id, '_smc_reverification_required', true ) ) {
				if ( ! self::write_user_meta_verified( $user_id, '_smc_reverification_required', 1 )
					|| ! SMC_Security::audit( 'membership_reverification_overdue', $user_id, array( 'due_at' => absint( $status['due_at'] ?? 0 ) ) )
					|| false === self::bump_revocation_epoch( $user_id, 'membership_reverification_overdue' ) ) {
					self::write_option_verified( self::REVERIFY_CURSOR_OPTION, $cursor );
					return;
				}
			}
			$cursor = $user_id;
		}
		$next = count( (array) $ids ) < $batch ? 0 : $cursor;
		self::write_option_verified( self::REVERIFY_CURSOR_OPTION, $next );
	}

	/** F00-EXT-005 — Critical identity change workflow. */
	public static function mark_critical_identity_change( $user_id, $field, $actor_id = 0, $reason = '' ) {
		$user_id = absint( $user_id );
		$field = sanitize_key( $field );
		$allowed = array( 'legal_name', 'date_of_birth', 'gender', 'country', 'email', 'mobile', 'guardian', 'government_identity' );
		if ( ! in_array( $field, $allowed, true ) ) {
			return new WP_Error( 'smc_identity_field', __( 'This field is not governed as a critical identity change.', 'sabri-membership-core' ) );
		}
		if ( ! self::actor_can_change_subject( absint( $actor_id ), $user_id ) || ! self::actor_meets_step_up( absint( $actor_id ), 'identity_change' ) ) {
			return new WP_Error( 'smc_identity_change_authorization', __( 'The identity change actor is not authorized with the required current step-up assurance.', 'sabri-membership-core' ) );
		}
		$stamp = time() + 1;
		$record = array( 'field' => $field, 'state' => 'reverification_required', 'changed_at' => time(), 'actor_id' => absint( $actor_id ), 'reason' => sanitize_text_field( $reason ) );
		if ( ! self::write_user_meta_verified( $user_id, self::CRITICAL_IDENTITY_META, $record )
			|| ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', $stamp )
			|| ! self::write_user_meta_verified( $user_id, '_smc_reverification_required', 1 ) ) {
			return new WP_Error( 'smc_identity_revalidation', __( 'Identity revalidation could not be persisted.', 'sabri-membership-core' ) );
		}
		if ( class_exists( 'SMC_Security' ) && ! SMC_Security::revoke_all_sessions( $user_id, 'critical_identity_change' ) ) {
			return new WP_Error( 'smc_identity_sessions', __( 'Existing sessions could not be invalidated safely.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'critical_identity_changed', $user_id, array( 'field' => $field, 'actor_id' => absint( $actor_id ), 'reason' => sanitize_text_field( $reason ) ) ) ) {
			return new WP_Error( 'smc_identity_audit', __( 'Critical identity change audit could not be committed.', 'sabri-membership-core' ) );
		}
		if ( false === self::bump_revocation_epoch( $user_id, 'critical_identity_changed' ) ) {
			return new WP_Error( 'smc_identity_revocation', __( 'Critical identity change could not be propagated safely.', 'sabri-membership-core' ) );
		}
		return true;
	}

	public static function resolve_critical_identity_change( $user_id, $actor_id, $source = 'identity_review' ) {
		$user_id = absint( $user_id );
		$actor_id = absint( $actor_id );
		$record = get_user_meta( $user_id, self::CRITICAL_IDENTITY_META, true );
		if ( ! is_array( $record ) || 'reverification_required' !== ( $record['state'] ?? '' ) || ! self::actor_meets_step_up( $actor_id, 'identity_change', 'smc_finalize_verification' ) ) {
			return new WP_Error( 'smc_identity_resolution', __( 'Independent authorized identity reverification is required.', 'sabri-membership-core' ) );
		}
		$verified = self::mark_reverified( $user_id, $actor_id, $source );
		if ( is_wp_error( $verified ) ) { return $verified; }
		$record['state'] = 'resolved';
		$record['resolved_at'] = time();
		$record['resolved_by'] = $actor_id;
		if ( ! self::write_user_meta_verified( $user_id, self::CRITICAL_IDENTITY_META, $record ) || ! SMC_Security::audit( 'critical_identity_reverified', $user_id, array( 'field' => sanitize_key( $record['field'] ?? '' ) ) ) ) {
			update_user_meta( $user_id, '_smc_reverification_required', 1 );
			return new WP_Error( 'smc_identity_resolution_store', __( 'Identity reverification resolution could not be committed safely.', 'sabri-membership-core' ) );
		}
		if ( false === self::bump_revocation_epoch( $user_id, 'critical_identity_reverified' ) ) {
			update_user_meta( $user_id, '_smc_reverification_required', 1 );
			return new WP_Error( 'smc_identity_resolution_revocation', __( 'Identity reverification resolution could not be propagated safely.', 'sabri-membership-core' ) );
		}
		return $record;
	}

	/** F00-EXT-006 — Claim provenance and freshness. */
	public static function claims_envelope( $user_id ) {
		$user_id = absint( $user_id );
		$profile = self::assurance_profile( $user_id );
		$auth = self::authentication_assurance( $user_id );
		$now = time();
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'policy_version' => self::POLICY_VERSION,
			'subject' => self::subject_reference( $user_id ),
			'claims' => array(
				'membership' => array( 'owner' => 'file00', 'current' => ! empty( $profile['membership_current'] ), 'issued_at' => $now, 'expires_at' => $now + 120 ),
				'identity' => array( 'owner' => 'file00', 'current' => ! empty( $profile['identity_current'] ), 'issued_at' => $now, 'expires_at' => $now + 120 ),
				'guardian' => array( 'owner' => 'file00', 'current' => ! empty( $profile['guardian_current'] ), 'issued_at' => $now, 'expires_at' => $now + 120 ),
				'professional' => array( 'owner' => 'file09', 'current' => ! empty( $profile['professional_current'] ), 'issued_at' => $now, 'expires_at' => $now + 120 ),
				'authentication' => array(
					'owner' => sanitize_key( $auth['owner'] ?? 'file00' ),
					'contract_version' => (string) ( $auth['contract_version'] ?? '1.0.0' ),
					'level' => (int) $profile['authentication_assurance_level'],
					'method' => sanitize_key( $auth['method'] ?? '' ),
					'verified_at' => absint( $auth['verified_at'] ?? 0 ),
					'user_verified' => ! empty( $auth['user_verified'] ),
					'phishing_resistant' => ! empty( $auth['phishing_resistant'] ),
					'risk' => sanitize_key( $auth['risk'] ?? 'unknown' ),
					'session_bound' => ! empty( $auth['session_bound'] ),
					'fingerprint_bound' => ! empty( $auth['fingerprint_bound'] ),
					'issued_at' => $now,
					'expires_at' => absint( $auth['verified_at'] ?? 0 ) > 0 ? min( $now + 120, absint( $auth['verified_at'] ) + 5 * MINUTE_IN_SECONDS ) : $now + 120,
				),
			),
			'revocation_epoch' => self::revocation_epoch( $user_id ),
			'issued_at' => $now,
			'expires_at' => $now + 120,
		);
	}

	/** F00-EXT-007 — Consent dependency graph. */
	public static function consent_dependency_graph() {
		$baseline = array(
			'membership' => array( 'membership_terms', 'identity_verification', 'ethical_use' ),
			'communication' => array( 'membership_terms', 'ethical_use', 'communication' ),
			'clinical' => array( 'membership_terms', 'ethical_use', 'clinical' ),
			'marketplace' => array( 'membership_terms', 'ethical_use', 'marketplace' ),
			'publication' => array( 'membership_terms', 'ethical_use', 'publication' ),
			'research' => array( 'membership_terms', 'ethical_use', 'research' ),
		);
		$extended = apply_filters( 'smc_consent_dependency_graph_v1', $baseline );
		if ( ! is_array( $extended ) ) { return $baseline; }
		foreach ( $baseline as $capability => $purposes ) {
			$extra = isset( $extended[ $capability ] ) && is_array( $extended[ $capability ] ) ? $extended[ $capability ] : array();
			$extended[ $capability ] = array_values( array_unique( array_map( 'sanitize_key', array_merge( $purposes, $extra ) ) ) );
		}
		return $extended;
	}

	public static function consent_dependencies_satisfied( $user_id, $capability ) {
		$user_id = absint( $user_id );
		$capability = sanitize_key( $capability );
		$graph = self::consent_dependency_graph();
		if ( empty( $graph[ $capability ] ) || ! is_array( $graph[ $capability ] ) ) {
			return false;
		}
		global $wpdb;
		$policy_version = function_exists( 'smc_policy' ) ? (string) ( smc_policy()['version'] ?? '' ) : '';
		if ( '' === $policy_version ) {
			return false;
		}
		foreach ( $graph[ $capability ] as $purpose ) {
			$purpose = sanitize_key( $purpose );
			$active = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose=%s AND policy_version=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1", $user_id, $purpose, $policy_version ) );
			if ( ! $active ) {
				return false;
			}
		}
		return true;
	}

	/** F00-EXT-008 — Guardian succession/replacement. */
	public static function begin_guardian_succession( $user_id, $actor_id, $reason = '' ) {
		$user_id = absint( $user_id );
		$actor_id = absint( $actor_id );
		if ( ! self::actor_can_change_subject( $actor_id, $user_id ) || ! self::actor_meets_step_up( $actor_id, 'guardian_change' ) ) {
			return new WP_Error( 'smc_guardian_succession_authorization', __( 'Guardian succession requires an authorized current actor and the required current step-up assurance.', 'sabri-membership-core' ) );
		}
		$request = array(
			'id' => wp_generate_uuid4(),
			'state' => 'pending',
			'previous_guardian_consent_id' => self::current_guardian_consent_id( $user_id ),
			'requested_at' => time(),
			'requested_by' => $actor_id,
			'reason' => sanitize_text_field( $reason ),
		);
		if ( ! self::write_user_meta_verified( $user_id, self::GUARDIAN_SUCCESSION_META, $request ) || ! SMC_Security::audit( 'guardian_succession_started', $user_id, array( 'request_id' => $request['id'] ) ) ) {
			return new WP_Error( 'smc_guardian_succession', __( 'Guardian succession could not be started safely.', 'sabri-membership-core' ) );
		}
		if ( false === self::bump_revocation_epoch( $user_id, 'guardian_succession_started' ) ) {
			return new WP_Error( 'smc_guardian_succession_revocation', __( 'Guardian succession could not be propagated safely.', 'sabri-membership-core' ) );
		}
		return $request;
	}

	public static function complete_guardian_succession( $user_id, $request_id, $actor_id ) {
		$user_id = absint( $user_id );
		$state = get_user_meta( $user_id, self::GUARDIAN_SUCCESSION_META, true );
		if ( ! is_array( $state ) || 'pending' !== ( $state['state'] ?? '' ) || ! hash_equals( (string) ( $state['id'] ?? '' ), (string) $request_id ) ) {
			return new WP_Error( 'smc_guardian_succession_state', __( 'The guardian succession request is not current.', 'sabri-membership-core' ) );
		}
		if ( ! self::actor_meets_step_up( absint( $actor_id ), 'guardian_change', 'smc_manage_membership' ) ) {
			return new WP_Error( 'smc_guardian_succession_stepup', __( 'Authorized membership review and the required current step-up assurance are required.', 'sabri-membership-core' ) );
		}
		$current_guardian_id = self::current_guardian_consent_id( $user_id );
		if ( $current_guardian_id <= 0 || $current_guardian_id === absint( $state['previous_guardian_consent_id'] ?? 0 ) || ! SMC_Contracts::guardian_verified( $user_id ) ) {
			return new WP_Error( 'smc_guardian_succession_unverified', __( 'A newly verified guardian consent must exist before succession can complete.', 'sabri-membership-core' ) );
		}
		$state['new_guardian_consent_id'] = $current_guardian_id;
		$state['state'] = 'completed';
		$state['completed_at'] = time();
		$state['completed_by'] = absint( $actor_id );
		$stamp = time() + 1;
		if ( ! self::write_user_meta_verified( $user_id, self::GUARDIAN_SUCCESSION_META, $state ) || ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', $stamp ) ) {
			return new WP_Error( 'smc_guardian_succession_store', __( 'Guardian succession could not be committed safely.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::revoke_all_sessions( $user_id, 'guardian_succession_completed' ) ) {
			return new WP_Error( 'smc_guardian_succession_sessions', __( 'Guardian succession could not invalidate existing sessions.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'guardian_succession_completed', $user_id, array( 'request_id' => $request_id ) ) ) {
			return new WP_Error( 'smc_guardian_succession_audit', __( 'Guardian succession could not be audited safely.', 'sabri-membership-core' ) );
		}
		if ( false === self::bump_revocation_epoch( $user_id, 'guardian_succession_completed' ) ) {
			return new WP_Error( 'smc_guardian_succession_revocation', __( 'Guardian succession could not be propagated safely.', 'sabri-membership-core' ) );
		}
		return true;
	}

	/** F00-EXT-009 — Duplicate-account merge resolution workflow. */
	public static function propose_account_merge( $primary_user_id, $duplicate_user_id, $actor_id, $reason = '' ) {
		$primary_user_id = absint( $primary_user_id );
		$duplicate_user_id = absint( $duplicate_user_id );
		$actor_id = absint( $actor_id );
		if ( $primary_user_id <= 0 || $duplicate_user_id <= 0 || $primary_user_id === $duplicate_user_id || ! get_userdata( $primary_user_id ) || ! get_userdata( $duplicate_user_id ) ) {
			return new WP_Error( 'smc_merge_subjects', __( 'Two different valid account subjects are required.', 'sabri-membership-core' ) );
		}
		if ( ! self::actor_meets_step_up( $actor_id, 'account_merge', 'smc_manage_membership' ) ) {
			return new WP_Error( 'smc_merge_authorization', __( 'Account merge review requires membership authority and the required current step-up assurance.', 'sabri-membership-core' ) );
		}
		$request = array(
			'id' => wp_generate_uuid4(),
			'primary_user_id' => $primary_user_id,
			'duplicate_user_id' => $duplicate_user_id,
			'state' => 'evidence_review',
			'reason' => sanitize_text_field( $reason ),
			'opened_by' => $actor_id,
			'opened_at' => time(),
		);
		$old_primary = self::meta_snapshot( $primary_user_id, self::MERGE_META );
		$old_duplicate = self::meta_snapshot( $duplicate_user_id, self::MERGE_META );
		if ( ! self::write_user_meta_verified( $primary_user_id, self::MERGE_META, $request ) || ! self::write_user_meta_verified( $duplicate_user_id, self::MERGE_META, $request ) ) {
			self::restore_meta_snapshot( $primary_user_id, self::MERGE_META, $old_primary );
			self::restore_meta_snapshot( $duplicate_user_id, self::MERGE_META, $old_duplicate );
			return new WP_Error( 'smc_merge_store', __( 'Account merge proposal could not be stored consistently.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'account_merge_proposed', $primary_user_id, array( 'request_id' => $request['id'], 'duplicate_subject' => self::subject_reference( $duplicate_user_id ) ) ) ) {
			self::restore_meta_snapshot( $primary_user_id, self::MERGE_META, $old_primary );
			self::restore_meta_snapshot( $duplicate_user_id, self::MERGE_META, $old_duplicate );
			return new WP_Error( 'smc_merge_audit', __( 'Account merge proposal could not be audited.', 'sabri-membership-core' ) );
		}
		return $request;
	}

	public static function approve_account_merge( $request_id, $primary_user_id, $reviewer_id ) {
		$primary_user_id = absint( $primary_user_id );
		$reviewer_id = absint( $reviewer_id );
		$request = get_user_meta( $primary_user_id, self::MERGE_META, true );
		if ( ! is_array( $request ) || ! hash_equals( (string) ( $request['id'] ?? '' ), (string) $request_id ) || 'evidence_review' !== ( $request['state'] ?? '' ) ) {
			return new WP_Error( 'smc_merge_state', __( 'The account merge request is not current.', 'sabri-membership-core' ) );
		}
		if ( absint( $request['opened_by'] ?? 0 ) === $reviewer_id || ! self::actor_meets_step_up( $reviewer_id, 'account_merge', 'smc_finalize_verification' ) ) {
			return new WP_Error( 'smc_merge_separation', __( 'Independent senior review with a fresh security challenge is required.', 'sabri-membership-core' ) );
		}
		$duplicate_user_id = absint( $request['duplicate_user_id'] ?? 0 );
		if ( $duplicate_user_id <= 0 || absint( $request['primary_user_id'] ?? 0 ) !== $primary_user_id ) {
			return new WP_Error( 'smc_merge_integrity', __( 'Account merge subjects are inconsistent.', 'sabri-membership-core' ) );
		}
		$request['state'] = 'finalizing';
		$request['approved_by'] = $reviewer_id;
		$request['approved_at'] = time();
		if ( ! self::write_user_meta_verified( $primary_user_id, self::MERGE_META, $request ) || ! self::write_user_meta_verified( $duplicate_user_id, self::MERGE_META, $request ) ) {
			return new WP_Error( 'smc_merge_finalizing_store', __( 'Account merge could not enter the fail-closed finalizing state.', 'sabri-membership-core' ) );
		}
		$inactive = self::set_continuity_state( $duplicate_user_id, 'permanently_inactive', $reviewer_id, 'duplicate_account_merge' );
		if ( is_wp_error( $inactive ) || false === $inactive ) {
			return new WP_Error( 'smc_merge_continuity', __( 'Duplicate account could not be made permanently inactive; merge remains fail-closed for repair.', 'sabri-membership-core' ) );
		}
		$request['state'] = 'approved_for_domain_transfer';
		if ( ! self::write_user_meta_verified( $primary_user_id, self::MERGE_META, $request ) || ! self::write_user_meta_verified( $duplicate_user_id, self::MERGE_META, $request ) ) {
			return new WP_Error( 'smc_merge_approval_store', __( 'Account merge approval could not be stored consistently; duplicate account remains inactive.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'account_merge_approved', $primary_user_id, array( 'request_id' => $request_id, 'duplicate_subject' => self::subject_reference( $duplicate_user_id ) ) ) ) {
			return new WP_Error( 'smc_merge_approval_audit', __( 'Account merge approval could not be audited; duplicate account remains inactive.', 'sabri-membership-core' ) );
		}
		$primary_revocation = self::bump_revocation_epoch( $primary_user_id, 'account_merge_approved' );
		$duplicate_revocation = self::bump_revocation_epoch( $duplicate_user_id, 'account_merge_approved' );
		if ( false === $primary_revocation || false === $duplicate_revocation ) {
			return new WP_Error( 'smc_merge_revocation', __( 'Account merge approval could not be propagated to all consumers.', 'sabri-membership-core' ) );
		}
		do_action( 'smc_account_merge_approved', $request );
		return $request;
	}

	/** F00-EXT-010 — Compromised-account containment state. */
	public static function containment_state( $user_id ) {
		$state = get_user_meta( absint( $user_id ), self::CONTAINMENT_META, true );
		return is_array( $state ) && ! empty( $state['state'] ) ? $state : array( 'state' => 'clear', 'updated_at' => 0 );
	}

	public static function set_containment_state( $user_id, $state, $actor_id = 0, $reason = '' ) {
		$user_id = absint( $user_id );
		$state = sanitize_key( $state );
		if ( ! in_array( $state, array( 'clear', 'security_recovery_required', 'contained' ), true ) ) {
			return new WP_Error( 'smc_containment_state', __( 'Unsupported security containment state.', 'sabri-membership-core' ) );
		}
		$actor_id = absint( $actor_id );
		$authorized = $actor_id > 0 ? self::actor_is_current( $actor_id, 'smc_manage_membership' ) && SMC_Security::session_is_verified( $actor_id ) : self::file24_containment_authorized( $user_id, $state, $reason );
		if ( ! $authorized ) { return new WP_Error( 'smc_containment_authorization', __( 'Security containment requires authorized membership/security governance.', 'sabri-membership-core' ) ); }
		if ( ! self::begin_transition_hold( $user_id, 'containment', $state, $actor_id ) ) { return new WP_Error( 'smc_containment_hold', __( 'Security containment could not enter a fail-closed transition hold.', 'sabri-membership-core' ) ); }
		$record = array( 'state' => $state, 'updated_at' => time(), 'actor_id' => $actor_id, 'reason' => sanitize_text_field( $reason ) );
		if ( ! self::write_user_meta_verified( $user_id, self::CONTAINMENT_META, $record ) ) { return new WP_Error( 'smc_containment_store', __( 'Security containment state could not be persisted safely.', 'sabri-membership-core' ) ); }
		if ( ! SMC_Security::revoke_all_sessions( $user_id, 'security_containment_' . $state ) ) { return new WP_Error( 'smc_containment_sessions', __( 'Security containment could not invalidate existing sessions.', 'sabri-membership-core' ) ); }
		if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return new WP_Error( 'smc_containment_revalidation', __( 'Security containment could not require a fresh session challenge.', 'sabri-membership-core' ) ); }
		if ( ! SMC_Security::audit( 'security_containment_changed', $user_id, array( 'state' => $state, 'reason' => $record['reason'] ) ) ) { return new WP_Error( 'smc_containment_audit', __( 'Security containment change could not be audited.', 'sabri-membership-core' ) ); }
		if ( false === self::bump_revocation_epoch( $user_id, 'security_containment_changed' ) ) { return new WP_Error( 'smc_containment_revocation', __( 'Security containment change could not be propagated safely.', 'sabri-membership-core' ) ); }
		if ( ! self::clear_transition_hold( $user_id ) ) { return new WP_Error( 'smc_containment_hold_clear', __( 'Security containment remains fail-closed because its transition hold could not be cleared.', 'sabri-membership-core' ) ); }
		return $record;
	}

	/** F00-EXT-011 — Revocation propagation SLA. */
	public static function revocation_epoch( $user_id ) {
		return absint( get_user_meta( absint( $user_id ), self::REVOCATION_META, true ) );
	}

	public static function bump_revocation_epoch( $user_id, $reason ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return false; }
		$lock = self::acquire_revocation_lock( $user_id );
		if ( false === $lock ) { return false; }
		$next = max( time(), self::revocation_epoch( $user_id ) + 1 );
		if ( ! self::write_user_meta_verified( $user_id, self::REVOCATION_META, $next ) ) {
			self::release_revocation_lock( $lock );
			return false;
		}
		$event = array(
			'subject' => self::subject_reference( $user_id ),
			'epoch' => $next,
			'reason' => sanitize_key( $reason ),
			'invalidated_at' => time(),
			'consumer_deadline' => time() + self::REVOCATION_PROPAGATION_SLA,
			'sla_seconds' => self::REVOCATION_PROPAGATION_SLA,
		);
		$propagated = true;
		try {
			do_action( 'smc_trust_revocation_invalidated', $event['subject'], $event );
		} catch ( Throwable $error ) {
			$propagated = false;
			if ( class_exists( 'SMC_Security' ) ) {
				SMC_Security::audit( 'trust_revocation_propagation_failed', $user_id, array( 'reason' => sanitize_key( $reason ) ) );
			}
		} finally {
			self::release_revocation_lock( $lock );
		}
		return $propagated ? $event : false;
	}

	private static function acquire_revocation_lock( $user_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return false; }
		$subject = class_exists( 'SMC_Security' ) ? SMC_Security::subject_hash( absint( $user_id ) ) : (string) absint( $user_id );
		$lock_name = 'smc_rev_' . substr( hash( 'sha256', (string) $subject ), 0, 40 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock_name, 2 ) );
		return '1' === (string) $locked ? $lock_name : false;
	}

	private static function release_revocation_lock( $lock_name ) {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock_name ) );
		}
	}

	/** F00-EXT-012 — Contract negotiation and anti-downgrade. */
	public static function negotiate_contract( $consumer_version ) {
		$consumer_version = trim( (string) $consumer_version );
		if ( '' === $consumer_version || ! preg_match( '/^\d+\.\d+\.\d+$/', $consumer_version ) ) {
			return array( 'compatible' => false, 'reason' => 'invalid_version', 'current' => self::CONTRACT_VERSION );
		}
		$current_major = (int) explode( '.', self::CONTRACT_VERSION )[0];
		$consumer_major = (int) explode( '.', $consumer_version )[0];
		$compatible = $current_major === $consumer_major && version_compare( $consumer_version, self::CONTRACT_VERSION, '<=' );
		return array(
			'compatible' => $compatible,
			'current' => self::CONTRACT_VERSION,
			'consumer' => $consumer_version,
			'minimum' => '1.0.0',
			'downgrade_allowed' => false,
			'reason' => $compatible ? 'compatible' : 'unsupported_or_future_contract',
		);
	}

	/** F00-EXT-013 — Privacy-preserving minimal assertions. */
	public static function minimal_assertions( $user_id, $audience = 'consumer' ) {
		$user_id = absint( $user_id );
		$audience = sanitize_key( $audience );
		$profile = self::assurance_profile( $user_id );
		$base = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( $user_id ) : array();
		$now = time();
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'audience' => $audience,
			'subject' => self::subject_reference( $user_id ),
			'membership_eligible' => ! empty( $base['eligible'] ) && self::protected_actions_allowed( $user_id ),
			'age_requirement_met' => self::age_requirement_met( $user_id, $base ),
			'guardian_requirement_met' => ! empty( $base['guardian_verified'] ),
			'identity_current' => ! empty( $profile['identity_current'] ),
			'professional_current' => ! empty( $profile['professional_current'] ),
			'session_step_up_current' => (int) $profile['authentication_assurance_level'] >= 2,
			'public_index_allowed' => ! empty( $base['public_profile_allowed'] ) && self::protected_actions_allowed( $user_id ),
			'revocation_epoch' => self::revocation_epoch( $user_id ),
			'issued_at' => $now,
			'expires_at' => $now + 120,
		);
	}

	/** F00-EXT-014 — Selective disclosure proofs (server-local, short-lived). */
	public static function selective_disclosure_proof( $user_id, $claims, $audience, $ttl = 120, $purpose = 'membership_assertion' ) {
		$user_id = absint( $user_id );
		$audience = sanitize_key( $audience );
		$purpose = sanitize_key( $purpose );
		$allowed = array( 'membership_eligible', 'age_requirement_met', 'guardian_requirement_met', 'identity_current', 'professional_current', 'session_step_up_current', 'public_index_allowed' );
		$requested = array_values( array_intersect( $allowed, array_map( 'sanitize_key', (array) $claims ) ) );
		if ( ! $requested || '' === $audience || '' === $purpose ) {
			return new WP_Error( 'smc_disclosure_claims', __( 'A valid audience, purpose and at least one approved claim are required.', 'sabri-membership-core' ) );
		}
		$ttl = max( 30, min( self::PROOF_MAX_TTL, absint( $ttl ) ) );
		$effective_ttl = min( $ttl, self::REVOCATION_PROPAGATION_SLA );
		$source = self::minimal_assertions( $user_id, $audience );
		$disclosed = array();
		foreach ( $requested as $claim ) {
			$disclosed[ $claim ] = (bool) $source[ $claim ];
		}
		$issued_at = time();
		$payload = array(
			'proof_version' => '1.1.0',
			'proof_id' => wp_generate_uuid4(),
			'subject' => $source['subject'],
			'audience' => $audience,
			'purpose' => $purpose,
			'claims' => $disclosed,
			'revocation_epoch' => $source['revocation_epoch'],
			'issued_at' => $issued_at,
			'revocation_deadline' => $issued_at + self::REVOCATION_PROPAGATION_SLA,
			'expires_at' => $issued_at + $effective_ttl,
		);
		$canonical = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$signature = class_exists( 'SMC_Security' ) ? SMC_Security::blind_index( $canonical, 'selective-disclosure-proof' ) : new WP_Error( 'smc_disclosure_key' );
		if ( is_wp_error( $signature ) ) { return $signature; }
		$payload['proof'] = $signature;
		return $payload;
	}

	public static function verify_selective_disclosure_proof( $proof, $audience, $purpose = 'membership_assertion' ) {
		$issued_at = is_array( $proof ) ? absint( $proof['issued_at'] ?? 0 ) : 0;
		$expires_at = is_array( $proof ) ? absint( $proof['expires_at'] ?? 0 ) : 0;
		$revocation_deadline = is_array( $proof ) ? absint( $proof['revocation_deadline'] ?? 0 ) : 0;
		if ( ! is_array( $proof ) || '1.1.0' !== (string) ( $proof['proof_version'] ?? '' ) || empty( $proof['proof'] ) || $issued_at <= 0 || $issued_at > time() + 60 || $expires_at < time() || $revocation_deadline < time() || $expires_at > $revocation_deadline || $revocation_deadline - $issued_at > self::REVOCATION_PROPAGATION_SLA || ! hash_equals( sanitize_key( $audience ), sanitize_key( $proof['audience'] ?? '' ) ) || ! hash_equals( sanitize_key( $purpose ), sanitize_key( $proof['purpose'] ?? '' ) ) ) {
			return false;
		}
		$signature = (string) $proof['proof'];
		$unsigned = $proof;
		unset( $unsigned['proof'] );
		$canonical = wp_json_encode( $unsigned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$expected = class_exists( 'SMC_Security' ) ? SMC_Security::blind_index( $canonical, 'selective-disclosure-proof' ) : new WP_Error( 'smc_disclosure_key' );
		return ! is_wp_error( $expected ) && hash_equals( (string) $expected, $signature );
	}

	/** F00-EXT-015 — Verifiable credential adapter. */
	public static function verifiable_credentials( $user_id ) {
		$user_id = absint( $user_id );
		$subject = self::subject_reference( $user_id );
		$claims = apply_filters( 'smc_external_verifiable_credentials_v1', array(), $user_id );
		$out = array();
		foreach ( (array) $claims as $claim ) {
			if ( ! is_array( $claim )
				|| '1.0.0' !== (string) ( $claim['contract_version'] ?? '' )
				|| 'credential_adapter' !== sanitize_key( $claim['owner'] ?? '' )
				|| ! hash_equals( $subject, (string) ( $claim['subject'] ?? '' ) )
				|| empty( $claim['issuer'] ) || empty( $claim['type'] ) || empty( $claim['verified'] ) || empty( $claim['proof_reference'] ) ) {
				continue;
			}
			$verified_at = absint( $claim['verified_at'] ?? 0 );
			$issued = absint( $claim['issued_at'] ?? 0 );
			$expires = absint( $claim['expires_at'] ?? 0 );
			if ( $verified_at <= 0 || $verified_at > time() + 60 || $verified_at < time() - 5 * MINUTE_IN_SECONDS || $issued <= 0 || $issued > time() + 60 || $issued > $verified_at || ( $expires && ( $expires <= time() || $expires <= $issued || $expires <= $verified_at ) ) ) {
				continue;
			}
			$out[] = array(
				'owner' => 'credential_adapter',
				'contract_version' => '1.0.0',
				'issuer' => sanitize_text_field( $claim['issuer'] ),
				'type' => sanitize_key( $claim['type'] ),
				'verified' => true,
				'verified_at' => $verified_at,
				'issued_at' => $issued,
				'expires_at' => $expires,
				'proof_reference' => sanitize_text_field( $claim['proof_reference'] ),
			);
		}
		return $out;
	}

	/** F00-EXT-016 — Scoped delegated authority. */
	public static function grant_delegated_authority( $principal_user_id, $grantor_user_id, $scopes, $expires_at ) {
		$principal_user_id = absint( $principal_user_id );
		$grantor_user_id = absint( $grantor_user_id );
		$scopes = array_values( array_unique( array_map( 'sanitize_key', (array) $scopes ) ) );
		$allowed = array( 'membership_support', 'membership_queue_view', 'membership_request_information', 'institution_membership_manage' );
		$scopes = array_values( array_intersect( $allowed, $scopes ) );
		$expires_at = absint( $expires_at );
		if ( ! $principal_user_id || ! get_userdata( $principal_user_id ) || ! $grantor_user_id || ! $scopes || $expires_at <= time() || $expires_at > time() + 90 * DAY_IN_SECONDS ) {
			return new WP_Error( 'smc_delegation_input', __( 'Delegated authority requires a valid principal, scope and bounded expiry.', 'sabri-membership-core' ) );
		}
		if ( ! self::actor_meets_step_up( $grantor_user_id, 'delegation_grant', 'smc_manage_membership' ) ) {
			return new WP_Error( 'smc_delegation_authority', __( 'Delegation requires membership authority and the required current step-up assurance.', 'sabri-membership-core' ) );
		}
		$old = self::meta_snapshot( $principal_user_id, self::DELEGATION_META );
		$grants = (array) get_user_meta( $principal_user_id, self::DELEGATION_META, true );
		$grant = array( 'id' => wp_generate_uuid4(), 'grantor_user_id' => $grantor_user_id, 'scopes' => $scopes, 'issued_at' => time(), 'expires_at' => $expires_at, 'revoked_at' => 0 );
		$grants[] = $grant;
		if ( ! self::write_user_meta_verified( $principal_user_id, self::DELEGATION_META, $grants ) ) {
			return new WP_Error( 'smc_delegation_store', __( 'Delegation could not be stored safely.', 'sabri-membership-core' ) );
		}
		if ( ! SMC_Security::audit( 'delegated_authority_granted', $principal_user_id, array( 'grant_id' => $grant['id'], 'scopes' => $scopes, 'expires_at' => $expires_at ) ) || false === self::bump_revocation_epoch( $principal_user_id, 'delegated_authority_granted' ) ) {
			self::restore_meta_snapshot( $principal_user_id, self::DELEGATION_META, $old );
			return new WP_Error( 'smc_delegation_commit', __( 'Delegation could not be committed with audit and revocation evidence.', 'sabri-membership-core' ) );
		}
		return $grant;
	}

	public static function delegated_authorities( $principal_user_id ) {
		$grants = (array) get_user_meta( absint( $principal_user_id ), self::DELEGATION_META, true );
		$active = array();
		foreach ( $grants as $grant ) {
			if ( is_array( $grant ) && empty( $grant['revoked_at'] ) && absint( $grant['expires_at'] ?? 0 ) > time() && self::delegation_grantor_current( absint( $grant['grantor_user_id'] ?? 0 ) ) ) {
				$active[] = $grant;
			}
		}
		return $active;
	}

	public static function revoke_delegated_authority( $principal_user_id, $grant_id, $actor_id ) {
		$principal_user_id = absint( $principal_user_id );
		$actor_id = absint( $actor_id );
		if ( ! self::actor_is_current( $actor_id, 'smc_manage_membership' ) || ! SMC_Security::session_is_verified( $actor_id ) ) {
			return false;
		}
		$grants = (array) get_user_meta( $principal_user_id, self::DELEGATION_META, true );
		$changed = false;
		foreach ( $grants as &$grant ) {
			if ( is_array( $grant ) && hash_equals( (string) ( $grant['id'] ?? '' ), (string) $grant_id ) && empty( $grant['revoked_at'] ) ) {
				$grant['revoked_at'] = time();
				$grant['revoked_by'] = $actor_id;
				$changed = true;
			}
		}
		unset( $grant );
		if ( ! $changed || ! self::write_user_meta_verified( $principal_user_id, self::DELEGATION_META, $grants ) ) { return false; }
		if ( ! SMC_Security::audit( 'delegated_authority_revoked', $principal_user_id, array( 'grant_id' => sanitize_text_field( $grant_id ) ) ) ) { return false; }
		return false !== self::bump_revocation_epoch( $principal_user_id, 'delegated_authority_revoked' );
	}

	public static function has_delegated_scope( $principal_user_id, $scope ) {
		$principal_user_id = absint( $principal_user_id );
		$scope = sanitize_key( $scope );
		if ( $principal_user_id <= 0 || ! self::protected_actions_allowed( $principal_user_id ) ) { return false; }
		$membership = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( $principal_user_id ) : array();
		if ( empty( $membership['approved'] ) || ! empty( $membership['suspended'] ) || empty( $membership['eligible'] ) ) { return false; }
		foreach ( self::delegated_authorities( $principal_user_id ) as $grant ) {
			if ( in_array( $scope, (array) ( $grant['scopes'] ?? array() ), true ) ) { return true; }
		}
		return false;
	}

	/** F00-EXT-017 — Founder/institutional break-glass governance. */
	public static function open_break_glass( $subject_user_id, $actor_id, $purpose ) {
		$subject_user_id = absint( $subject_user_id );
		$actor_id = absint( $actor_id );
		$purpose = sanitize_text_field( $purpose );
		if ( ! $subject_user_id || ! $actor_id || '' === $purpose || ! self::actor_meets_step_up( $actor_id, 'break_glass', 'manage_options', true ) ) {
			return new WP_Error( 'smc_break_glass_open', __( 'Break-glass initiation requires authorized institutional authority and a fresh security challenge.', 'sabri-membership-core' ) );
		}
		$lock = self::acquire_break_glass_lock();
		if ( false === $lock ) { return new WP_Error( 'smc_break_glass_busy', __( 'Break-glass governance is busy; retry safely.', 'sabri-membership-core' ) ); }
		$request = array(
			'id' => wp_generate_uuid4(), 'subject_user_id' => $subject_user_id, 'purpose' => $purpose,
			'opened_by' => $actor_id, 'opened_at' => time(), 'expires_at' => time() + self::BREAK_GLASS_TTL,
			'approvals' => array( $actor_id ), 'approval_times' => array( (string) $actor_id => time() ), 'consumed_at' => 0,
		);
		$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );
		if ( count( $all ) >= 200 ) { self::release_break_glass_lock( $lock ); return new WP_Error( 'smc_break_glass_capacity', __( 'Emergency governance request capacity is temporarily exhausted.', 'sabri-membership-core' ) ); }
		$all[ $request['id'] ] = $request;
		$stored = self::write_option_verified( self::BREAK_GLASS_OPTION, $all );
		if ( $stored && ! SMC_Security::audit( 'break_glass_opened', $subject_user_id, array( 'request_id' => $request['id'], 'purpose' => $request['purpose'] ) ) ) {
			unset( $all[ $request['id'] ] ); self::write_option_verified( self::BREAK_GLASS_OPTION, $all ); $stored = false;
		}
		self::release_break_glass_lock( $lock );
		return $stored ? $request : new WP_Error( 'smc_break_glass_store', __( 'Break-glass request could not be persisted and audited safely.', 'sabri-membership-core' ) );
	}

	public static function approve_break_glass( $request_id, $approver_id ) {
		$approver_id = absint( $approver_id );
		if ( ! self::actor_meets_step_up( $approver_id, 'break_glass', 'manage_options', true ) ) { return false; }
		$lock = self::acquire_break_glass_lock();
		if ( false === $lock ) { return false; }
		$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );
		$request = $all[ $request_id ] ?? null;
		if ( ! is_array( $request ) || absint( $request['expires_at'] ?? 0 ) <= time() || ! empty( $request['consumed_at'] ) || in_array( $approver_id, (array) ( $request['approvals'] ?? array() ), true ) ) {
			self::release_break_glass_lock( $lock ); return false;
		}
		$before = $request;
		$request['approvals'][] = $approver_id;
		$request['approval_times'] = is_array( $request['approval_times'] ?? null ) ? $request['approval_times'] : array();
		$request['approval_times'][ (string) $approver_id ] = time();
		$all[ $request_id ] = $request;
		$stored = self::write_option_verified( self::BREAK_GLASS_OPTION, $all );
		if ( $stored && ! SMC_Security::audit( 'break_glass_approved', absint( $request['subject_user_id'] ), array( 'request_id' => $request_id ) ) ) {
			$all[ $request_id ] = $before; self::write_option_verified( self::BREAK_GLASS_OPTION, $all ); $stored = false;
		}
		self::release_break_glass_lock( $lock );
		return $stored && count( array_unique( $request['approvals'] ) ) >= 2;
	}

	public static function consume_break_glass( $request_id, $actor_id ) {
		$actor_id = absint( $actor_id );
		if ( ! self::actor_meets_step_up( $actor_id, 'break_glass', 'manage_options', true ) ) { return false; }
		$lock = self::acquire_break_glass_lock();
		if ( false === $lock ) { return false; }
		$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );
		$request = $all[ $request_id ] ?? null;
		if ( ! is_array( $request ) || absint( $request['expires_at'] ?? 0 ) <= time() || ! empty( $request['consumed_at'] ) || count( array_unique( (array) ( $request['approvals'] ?? array() ) ) ) < 2 || ! in_array( $actor_id, (array) $request['approvals'], true ) || ! self::break_glass_approvals_current( $request ) ) {
			self::release_break_glass_lock( $lock ); return false;
		}
		$before = $request;
		$request['consumed_at'] = time(); $request['consumed_by'] = $actor_id; $all[ $request_id ] = $request;
		$stored = self::write_option_verified( self::BREAK_GLASS_OPTION, $all );
		if ( ! $stored ) { self::release_break_glass_lock( $lock ); return false; }
		$audit = SMC_Security::audit( 'break_glass_consumed', absint( $request['subject_user_id'] ), array( 'request_id' => $request_id ) );
		if ( ! $audit ) {
			$all[ $request_id ] = $before;
			self::write_option_verified( self::BREAK_GLASS_OPTION, $all );
			self::release_break_glass_lock( $lock );
			return false;
		}
		self::release_break_glass_lock( $lock );
		return array( 'authorized' => true, 'request_id' => $request_id, 'subject' => self::subject_reference( absint( $request['subject_user_id'] ) ), 'purpose' => sanitize_text_field( $request['purpose'] ?? '' ), 'expires_at' => min( absint( $request['expires_at'] ), time() + 300 ) );
	}

	/** F00-EXT-018 — Non-human/service identity classes. */
	public static function subject_kind( $user_id ) {
		$user_id = absint( $user_id );
		if ( function_exists( 'smc_is_institutional_ai' ) && smc_is_institutional_ai( $user_id ) ) {
			return array( 'kind' => 'institutional_ai', 'human' => false, 'doctor' => false, 'owner' => 'file00' );
		}
		$service = get_user_meta( $user_id, self::SERVICE_IDENTITY_META, true );
		if ( is_array( $service ) && 'service' === ( $service['kind'] ?? '' ) ) {
			return array( 'kind' => 'service', 'human' => false, 'doctor' => false, 'owner' => 'file00', 'purpose' => sanitize_key( $service['purpose'] ?? '' ), 'approved' => ! empty( $service['approved'] ) );
		}
		return array( 'kind' => 'human', 'human' => true, 'doctor' => false, 'owner' => 'file00' );
	}

	public static function set_service_identity( $user_id, $actor_id, $purpose, $approved = true ) {
		$user_id = absint( $user_id ); $actor_id = absint( $actor_id ); $purpose = sanitize_key( $purpose );
		if ( ! $user_id || ! get_userdata( $user_id ) || '' === $purpose || ! self::actor_is_current( $actor_id, 'manage_options', true ) || ! SMC_Security::session_is_verified( $actor_id ) ) { return false; }
		if ( function_exists( 'smc_is_founder' ) && smc_is_founder( $user_id ) ) { return false; }
		if ( function_exists( 'smc_is_institutional_ai' ) && smc_is_institutional_ai( $user_id ) ) { return false; }
		$existing = get_user_meta( $user_id, self::SERVICE_IDENTITY_META, true );
		if ( ! is_array( $existing ) || 'service' !== ( $existing['kind'] ?? '' ) ) {
			$base = class_exists( 'SMC_Contracts' ) ? SMC_Contracts::assertions( $user_id ) : array();
			$status = sanitize_key( $base['status'] ?? 'not_enrolled' );
			if ( ! empty( $base['approved'] ) || ! in_array( $status, array( 'not_enrolled', 'draft' ), true ) ) { return false; }
		}
		$state = array( 'kind' => 'service', 'purpose' => $purpose, 'approved' => (bool) $approved, 'actor_id' => $actor_id, 'updated_at' => time() );
		if ( ! self::write_user_meta_verified( $user_id, self::SERVICE_IDENTITY_META, $state ) || ! SMC_Security::audit( 'service_identity_changed', $user_id, array( 'purpose' => $state['purpose'], 'approved' => $state['approved'] ) ) || false === self::bump_revocation_epoch( $user_id, 'service_identity_changed' ) ) { return false; }
		return $state;
	}

	/** F00-EXT-019 — Dormant/deceased/permanently inactive lifecycle. */
	public static function continuity_state( $user_id ) {
		$state = get_user_meta( absint( $user_id ), self::CONTINUITY_META, true );
		return is_array( $state ) && ! empty( $state['state'] ) ? $state : array( 'state' => 'active', 'updated_at' => 0 );
	}

	public static function set_continuity_state( $user_id, $state, $actor_id = 0, $reason = '' ) {
		$user_id = absint( $user_id ); $state = sanitize_key( $state );
		if ( ! in_array( $state, array( 'active', 'dormant', 'deceased', 'permanently_inactive' ), true ) ) { return new WP_Error( 'smc_continuity_state', __( 'Unsupported continuity state.', 'sabri-membership-core' ) ); }
		$actor_id = absint( $actor_id );
		if ( ! self::actor_is_current( $actor_id, 'smc_manage_membership' ) || ! SMC_Security::session_is_verified( $actor_id ) ) { return new WP_Error( 'smc_continuity_authorization', __( 'Continuity-state changes require authorized membership governance and fresh security challenge.', 'sabri-membership-core' ) ); }
		if ( ! self::begin_transition_hold( $user_id, 'continuity', $state, $actor_id ) ) { return new WP_Error( 'smc_continuity_hold', __( 'Continuity change could not enter a fail-closed transition hold.', 'sabri-membership-core' ) ); }
		$record = array( 'state' => $state, 'updated_at' => time(), 'actor_id' => $actor_id, 'reason' => sanitize_text_field( $reason ), 'authorship_preserved' => true );
		if ( ! self::write_user_meta_verified( $user_id, self::CONTINUITY_META, $record ) ) { return new WP_Error( 'smc_continuity_store', __( 'Continuity state could not be persisted safely.', 'sabri-membership-core' ) ); }
		if ( ! SMC_Security::revoke_all_sessions( $user_id, 'continuity_state_' . $state ) ) { return new WP_Error( 'smc_continuity_sessions', __( 'Continuity change could not invalidate existing sessions.', 'sabri-membership-core' ) ); }
		if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return new WP_Error( 'smc_continuity_revalidation', __( 'Continuity change could not require a fresh challenge.', 'sabri-membership-core' ) ); }
		if ( ! SMC_Security::audit( 'continuity_state_changed', $user_id, array( 'state' => $state, 'reason' => $record['reason'] ) ) ) { return new WP_Error( 'smc_continuity_audit', __( 'Continuity change could not be audited.', 'sabri-membership-core' ) ); }
		if ( false === self::bump_revocation_epoch( $user_id, 'continuity_state_changed' ) ) { return new WP_Error( 'smc_continuity_revocation', __( 'Continuity change could not be propagated safely.', 'sabri-membership-core' ) ); }
		if ( ! self::clear_transition_hold( $user_id ) ) { return new WP_Error( 'smc_continuity_hold_clear', __( 'Continuity state remains fail-closed because its transition hold could not be cleared.', 'sabri-membership-core' ) ); }
		return $record;
	}

	/** F00-EXT-020 — User-facing trust/security timeline. */
	public static function trust_timeline( $user_id, $limit = 50 ) {
		$user_id = absint( $user_id ); $limit = max( 1, min( 100, absint( $limit ) ) );
		$current = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
		if ( $current <= 0 || ( $current !== $user_id && ! current_user_can( 'smc_manage_membership' ) ) ) { return array(); }
		if ( ! class_exists( 'SMC_Security' ) ) { return array(); }
		$subject_hash = SMC_Security::subject_hash( $user_id ); if ( '' === $subject_hash ) { return array(); }
		global $wpdb; if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,action,created_at FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s ORDER BY id DESC LIMIT %d", $subject_hash, $limit ), ARRAY_A );
		$allowed_actions = array(
			'contact_verified', 'membership_reverified', 'critical_identity_changed', 'critical_identity_reverified', 'guardian_succession_started',
			'guardian_succession_completed', 'security_containment_changed', 'membership_session_revoked', 'sessions_revoked', 'two_factor_verified',
			'recovery_code_used', 'delegated_authority_granted', 'delegated_authority_revoked', 'continuity_state_changed', 'service_identity_changed',
			'account_merge_proposed', 'account_merge_approved', 'break_glass_opened', 'break_glass_approved', 'break_glass_consumed',
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$action = sanitize_key( $row['action'] ?? '' ); if ( ! in_array( $action, $allowed_actions, true ) ) { continue; }
			$out[] = array( 'id' => absint( $row['id'] ), 'action' => $action, 'created_at' => (string) $row['created_at'] );
		}
		return $out;
	}

	public static function protected_actions_allowed( $user_id ) {
		$user_id = absint( $user_id );
		if ( metadata_exists( 'user', $user_id, '_smc_trust_transition_hold_v1' ) ) { return false; }
		$containment = self::containment_state( $user_id );
		$continuity = self::continuity_state( $user_id );
		$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );
		$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );
		$auth = self::authentication_assurance( $user_id );
		$revalidation_current = 0 === $required_after || ( (int) ( $auth['level'] ?? 0 ) >= 2 && absint( $auth['verified_at'] ?? 0 ) >= $required_after );
		$reverification = self::reverification_status( $user_id );
		$reverification_stale = ! empty( $reverification['applicable'] ) && empty( $reverification['current'] );
		$critical = get_user_meta( $user_id, self::CRITICAL_IDENTITY_META, true );
		$critical_pending = is_array( $critical ) && 'reverification_required' === ( $critical['state'] ?? '' );
		$merge = get_user_meta( $user_id, self::MERGE_META, true );
		$merge_finalizing = is_array( $merge ) && 'finalizing' === ( $merge['state'] ?? '' );
		return 'clear' === ( $containment['state'] ?? 'unknown' ) && 'active' === ( $continuity['state'] ?? 'unknown' ) && $revalidation_current && ! $reverification_required && ! $reverification_stale && ! $critical_pending && ! $merge_finalizing;
	}

	public static function filter_capabilities( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );
		if ( ! is_object( $user ) || empty( $user->ID ) || self::protected_actions_allowed( $user->ID ) ) {
			return $allcaps;
		}
		foreach ( self::$restricted_caps as $cap ) {
			$allcaps[ $cap ] = false;
		}
		return $allcaps;
	}

	public static function augment_assertions( $assertions, $user_id ) {
		if ( ! is_array( $assertions ) ) {
			$assertions = array();
		}
		$user_id = absint( $user_id );
		$assertions['advanced_trust'] = self::minimal_assertions( $user_id, 'membership-consumer' );
		if ( ! self::protected_actions_allowed( $user_id ) ) {
			$assertions['eligible'] = false;
			$assertions['can_message'] = false;
			$assertions['can_comment'] = false;
			$assertions['can_book_appointment'] = false;
			$assertions['can_publish'] = false;
			$assertions['can_direct_publish'] = false;
			$assertions['can_transfer_files'] = false;
		}
		return $assertions;
	}

	private static function actor_can_change_subject( $actor_id, $subject_user_id ) {
		$actor_id = absint( $actor_id );
		$subject_user_id = absint( $subject_user_id );
		if ( $actor_id <= 0 || $subject_user_id <= 0 || ! self::actor_is_current( $actor_id ) ) { return false; }
		return $actor_id === $subject_user_id || current_user_can( 'smc_manage_membership' );
	}

	private static function actor_is_current( $actor_id, $capability = '', $founder_or_admin = false ) {
		$actor_id = absint( $actor_id );
		if ( $actor_id <= 0 ) { return false; }
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 && get_current_user_id() !== $actor_id ) { return false; }
		$is_founder = function_exists( 'smc_is_founder' ) && smc_is_founder( $actor_id );
		$is_admin = current_user_can( 'manage_options' );
		if ( $founder_or_admin && ( $is_founder || $is_admin ) ) { return true; }
		if ( ! $is_founder && ! $is_admin && class_exists( 'SMC_Contracts' ) ) {
			$membership = SMC_Contracts::assertions( $actor_id );
			if ( empty( $membership['approved'] ) || ! empty( $membership['suspended'] ) || empty( $membership['eligible'] ) || ! self::protected_actions_allowed( $actor_id ) ) { return false; }
		}
		return '' === $capability || current_user_can( $capability );
	}

	private static function actor_meets_step_up( $actor_id, $action, $capability = '', $founder_or_admin = false ) {
		$actor_id = absint( $actor_id );
		if ( ! self::actor_is_current( $actor_id, $capability, $founder_or_admin ) ) { return false; }
		$requirement = self::step_up_requirement( $actor_id, sanitize_key( $action ) );
		return is_array( $requirement ) && ! empty( $requirement['satisfied'] );
	}

	private static function file24_containment_authorized( $user_id, $state, $reason ) {
		$claim = apply_filters( 'smc_file24_security_containment_authorization_v1', null, absint( $user_id ), sanitize_key( $state ), sanitize_text_field( $reason ) );
		if ( ! is_array( $claim ) ) { return false; }
		$asserted_at = absint( $claim['asserted_at'] ?? 0 );
		return 'file24' === sanitize_key( $claim['owner'] ?? '' )
			&& '1.0.0' === (string) ( $claim['contract_version'] ?? '' )
			&& ! empty( $claim['authorized'] )
			&& hash_equals( self::subject_reference( absint( $user_id ) ), (string) ( $claim['subject'] ?? '' ) )
			&& sanitize_key( $state ) === sanitize_key( $claim['state'] ?? '' )
			&& $asserted_at > 0 && $asserted_at <= time() + 60 && $asserted_at >= time() - 5 * MINUTE_IN_SECONDS;
	}

	private static function system_reverification_authorized( $user_id, $source ) {
		$user_id = absint( $user_id ); $source = sanitize_key( $source );
		$claim = apply_filters( 'smc_system_reverification_authorization_v1', null, $user_id, $source );
		if ( ! is_array( $claim ) ) { return false; }
		$asserted_at = absint( $claim['asserted_at'] ?? 0 );
		return 'file00' === sanitize_key( $claim['owner'] ?? '' ) && '1.0.0' === (string) ( $claim['contract_version'] ?? '' ) && ! empty( $claim['authorized'] )
			&& '' !== $source && hash_equals( $source, sanitize_key( $claim['source'] ?? '' ) )
			&& hash_equals( self::subject_reference( $user_id ), (string) ( $claim['subject'] ?? '' ) )
			&& $asserted_at > 0 && $asserted_at <= time() + 60 && $asserted_at >= time() - 60;
	}

	private static function delegation_grantor_current( $grantor_user_id ) {
		$grantor_user_id = absint( $grantor_user_id );
		if ( $grantor_user_id <= 0 || ! get_userdata( $grantor_user_id ) || ! self::protected_actions_allowed( $grantor_user_id ) ) { return false; }
		if ( function_exists( 'smc_is_founder' ) && smc_is_founder( $grantor_user_id ) ) { return true; }
		$user = get_userdata( $grantor_user_id );
		return $user && user_can( $user, 'smc_manage_membership' );
	}

	private static function prune_break_glass_requests( $all ) {
		$all = is_array( $all ) ? $all : array();
		$now = time();
		foreach ( $all as $request_id => $request ) {
			if ( ! is_array( $request ) ) { unset( $all[ $request_id ] ); continue; }
			$expired_at = absint( $request['expires_at'] ?? 0 );
			$consumed_at = absint( $request['consumed_at'] ?? 0 );
			if ( ( $consumed_at > 0 && $consumed_at < $now - DAY_IN_SECONDS ) || ( $consumed_at <= 0 && $expired_at > 0 && $expired_at < $now - DAY_IN_SECONDS ) ) { unset( $all[ $request_id ] ); }
		}
		return $all;
	}

	private static function break_glass_approver_current( $approver_id ) {
		$approver_id = absint( $approver_id );
		if ( $approver_id <= 0 || ! get_userdata( $approver_id ) || ! self::protected_actions_allowed( $approver_id ) ) { return false; }
		if ( function_exists( 'smc_is_founder' ) && smc_is_founder( $approver_id ) ) { return true; }
		$user = get_userdata( $approver_id );
		return $user && user_can( $user, 'manage_options' );
	}

	private static function break_glass_approvals_current( $request ) {
		$approvals = array_values( array_unique( array_map( 'absint', (array) ( $request['approvals'] ?? array() ) ) ) );
		$times = is_array( $request['approval_times'] ?? null ) ? $request['approval_times'] : array();
		$opened_at = absint( $request['opened_at'] ?? 0 );
		$expires_at = absint( $request['expires_at'] ?? 0 );
		if ( count( $approvals ) < 2 || $opened_at <= 0 || $expires_at <= time() ) { return false; }
		foreach ( $approvals as $approver_id ) {
			$approved_at = absint( $times[ (string) $approver_id ] ?? 0 );
			if ( $approved_at < $opened_at || $approved_at > $expires_at || $approved_at > time() || ! self::break_glass_approver_current( $approver_id ) ) { return false; }
		}
		return true;
	}

	private static function begin_transition_hold( $user_id, $kind, $target_state, $actor_id ) {
		$hold = array( 'kind' => sanitize_key( $kind ), 'target_state' => sanitize_key( $target_state ), 'actor_id' => absint( $actor_id ), 'started_at' => time() );
		return self::write_user_meta_verified( absint( $user_id ), '_smc_trust_transition_hold_v1', $hold );
	}

	private static function clear_transition_hold( $user_id ) {
		delete_user_meta( absint( $user_id ), '_smc_trust_transition_hold_v1' );
		return ! metadata_exists( 'user', absint( $user_id ), '_smc_trust_transition_hold_v1' );
	}

	private static function write_user_meta_verified( $user_id, $key, $value ) {
		update_user_meta( absint( $user_id ), (string) $key, $value );
		return get_user_meta( absint( $user_id ), (string) $key, true ) === $value;
	}

	private static function meta_snapshot( $user_id, $key ) {
		return array( 'exists' => metadata_exists( 'user', absint( $user_id ), (string) $key ), 'value' => get_user_meta( absint( $user_id ), (string) $key, true ) );
	}

	private static function restore_meta_snapshot( $user_id, $key, $snapshot ) {
		if ( ! empty( $snapshot['exists'] ) ) { return self::write_user_meta_verified( $user_id, $key, $snapshot['value'] ); }
		delete_user_meta( absint( $user_id ), (string) $key );
		return ! metadata_exists( 'user', absint( $user_id ), (string) $key );
	}

	private static function write_option_verified( $key, $value ) {
		update_option( (string) $key, $value, false );
		return get_option( (string) $key, null ) === $value;
	}

	private static function acquire_break_glass_lock() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return false; }
		$lock_name = 'smc_emergency_governance_v2';
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock_name, 3 ) );
		return '1' === (string) $locked ? $lock_name : false;
	}

	private static function release_break_glass_lock( $token ) {
		global $wpdb;
		if ( 'smc_emergency_governance_v2' !== (string) $token || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return; }
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $token ) );
	}

	private static function current_guardian_consent_id( $user_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) { return 0; }
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND status='verified' AND policy_version=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1", absint( $user_id ), (string) smc_policy()['version'] ) ) );
	}

	private static function age_requirement_met( $user_id, $base = array() ) {
		$user_id = absint( $user_id );
		if ( ! empty( $base['institutional_account'] ) ) { return true; }
		if ( empty( $base['approved'] ) ) { return false; }
		$critical = get_user_meta( $user_id, self::CRITICAL_IDENTITY_META, true );
		if ( is_array( $critical ) && 'reverification_required' === ( $critical['state'] ?? '' ) && in_array( sanitize_key( $critical['field'] ?? '' ), array( 'date_of_birth', 'gender', 'country' ), true ) ) { return false; }
		/* Complete runtime performs the age/jurisdiction check synchronously; isolated contract harnesses may omit these loaded functions. */
		if ( ! class_exists( 'SMC_Security' ) || ! function_exists( 'smc_application' ) || ! function_exists( 'smc_age_from_dob' ) || ! function_exists( 'smc_effective_minimum_age' ) ) { return true; }
		$app = smc_application( $user_id );
		if ( ! is_array( $app ) || empty( $app['date_of_birth_enc'] ) ) { return false; }
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
		if ( is_wp_error( $dob ) ) { return false; }
		$age = smc_age_from_dob( $dob );
		$minimum = smc_effective_minimum_age( sanitize_key( $app['gender'] ?? '' ), sanitize_text_field( $app['residence_country'] ?? '' ) );
		if ( false === $age || false === $minimum || $age < $minimum ) { return false; }
		if ( $age < 18 ) { return ! empty( $base['guardian_verified'] ); }
		return true;
	}

	private static function subject_reference( $user_id ) {
		$user_id = absint( $user_id );
		if ( class_exists( 'SMC_CF01_Contract' ) ) {
			$uuid = SMC_CF01_Contract::ensure_subject_uuid( $user_id );
			if ( is_string( $uuid ) && '' !== $uuid ) {
				return $uuid;
			}
		}
		return 'smc:' . substr( hash_hmac( 'sha256', (string) $user_id, wp_salt( 'auth' ) ), 0, 32 );
	}
}

function smc_advanced_trust_assertions( $user_id ) {
	return SMC_Advanced_Trust_2026::minimal_assertions( absint( $user_id ), 'consumer' );
}

function smc_identity_assurance_profile( $user_id ) {
	return SMC_Advanced_Trust_2026::assurance_profile( absint( $user_id ) );
}

function smc_step_up_requirement( $user_id, $action ) {
	return SMC_Advanced_Trust_2026::step_up_requirement( absint( $user_id ), sanitize_key( $action ) );
}

function smc_trust_timeline( $user_id, $limit = 50 ) {
	return SMC_Advanced_Trust_2026::trust_timeline( absint( $user_id ), absint( $limit ) );
}

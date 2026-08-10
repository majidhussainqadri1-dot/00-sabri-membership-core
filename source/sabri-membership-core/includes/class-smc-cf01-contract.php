<?php
defined( 'ABSPATH' ) || exit;

/**
 * Privacy-minimal, server-side membership-assurance provider contract for CF-01.
 *
 * Founder change-control dated 10 August 2026 retired File 00 MFA. This
 * contract therefore exposes membership/identity prerequisites only. It does
 * not verify TOTP, recovery codes, passkeys, passwords, or any other
 * authentication factor. Stronger authentication assurance belongs to File 02
 * or another explicitly approved authentication owner and must arrive through
 * a separate versioned contract.
 */
final class SMC_CF01_Contract {
	const CONTRACT_NAME    = 'smc.cf01.membership-assurance';
	const CONTRACT_VERSION = '1.1.0';
	const ASSERTION_TTL     = 60;

	private static $actions = array(
		'clinical_identity_link',
		'clinical_read',
		'clinical_write',
		'prescription_sign',
		'clinical_export',
		'break_glass',
		'guardian_sensitive',
		'key_recovery',
	);

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'ensure_subject_uuid' ), 5, 1 );
	}

	/**
	 * Return a stable, opaque platform subject UUID owned by File 00.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Empty on failure.
	 */
	public static function ensure_subject_uuid( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return '';
		}
		$stored = (string) get_user_meta( $user_id, '_smc_platform_uuid_v1', true );
		if ( self::valid_uuid( $stored ) ) {
			return strtolower( $stored );
		}
		$candidate = wp_generate_uuid4();
		$created   = add_user_meta( $user_id, '_smc_platform_uuid_v1', $candidate, true );
		if ( ! $created ) {
			$candidate = (string) get_user_meta( $user_id, '_smc_platform_uuid_v1', true );
		}
		if ( ! self::valid_uuid( $candidate ) ) {
			return '';
		}
		if ( $created && ! SMC_Security::audit( 'platform_subject_uuid_created', $user_id, array( 'contract' => self::CONTRACT_VERSION ) ) ) {
			delete_user_meta( $user_id, '_smc_platform_uuid_v1', $candidate );
			return '';
		}
		return strtolower( $candidate );
	}

	/**
	 * Return a bounded membership-prerequisite assertion for one requested
	 * clinical-domain action. A File 00 "allow" means only that the membership
	 * side of the prerequisite is satisfied. It is never clinical object,
	 * relationship, field, prescription, break-glass, export, or key authority.
	 *
	 * @param int   $user_id Subject WordPress user ID.
	 * @param array $context Requested action, purpose, jurisdiction and trace ID.
	 * @return array<string,mixed>
	 */
	public static function membership_assertion( $user_id, $context = array() ) {
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();
		$action  = sanitize_key( $context['action'] ?? '' );
		$purpose = sanitize_key( $context['purpose'] ?? '' );
		$now     = time();
		$trace   = self::trace_id( $context['trace_id'] ?? '' );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		$envelope = array(
			'contract'                    => self::CONTRACT_NAME,
			'contract_version'            => self::CONTRACT_VERSION,
			'producer_version'            => defined( 'SMC_VERSION' ) ? SMC_VERSION : '',
			'issued_at'                   => gmdate( 'c', $now ),
			'expires_at'                  => gmdate( 'c', $now + self::ASSERTION_TTL ),
			'trace_id'                    => $trace,
			'action'                      => $action,
			'purpose'                     => $purpose,
			'authorization_scope'         => 'membership_prerequisite_only',
			'authentication_assurance'    => 'not_owned_by_file00',
			'authentication_owner'        => 'file02_or_consumer',
			'file00_mfa_required'         => false,
			'result'                      => 'unknown',
			'reason_code'                 => 'subject_unavailable',
		);
		if ( ! $user ) {
			return $envelope;
		}

		$subject_uuid = self::ensure_subject_uuid( $user_id );
		if ( '' === $subject_uuid ) {
			$envelope['reason_code'] = 'subject_uuid_unavailable';
			return $envelope;
		}

		$state          = smc_membership_state( $user_id );
		$app            = ! empty( $state['application_exists'] ) ? smc_application( $user_id ) : false;
		$base           = SMC_Contracts::assertions( $user_id );
		$age            = self::age_context( $user_id, $app );
		$jurisdiction   = self::jurisdiction_context( $user_id, $context );
		$record_version = $app ? (int) ( $app['row_version'] ?? 0 ) : 0;
		$eligible       = ! empty( $base['eligible'] );
		$can_practice   = ! empty( $base['can_practice'] );
		$guardian_ok    = empty( $app['guardian_required'] ) || ! empty( $base['guardian_verified'] );

		$capabilities = array(
			'clinical_identity_link' => $eligible,
			'clinical_read'          => $eligible,
			'clinical_write'         => $eligible,
			'prescription_sign'      => $can_practice,
			'clinical_export'        => $eligible,
			'break_glass'            => $can_practice,
			'guardian_sensitive'     => $eligible && $guardian_ok,
			'key_recovery'           => false,
		);

		$envelope['subject'] = array(
			'platform_uuid'  => $subject_uuid,
			'source_owner'   => 'File 00',
			'record_version' => $record_version,
		);
		$envelope['membership'] = array(
			'account_class'      => (string) ( $base['account_class'] ?? 'member' ),
			'membership_type'    => (string) ( $base['membership_type'] ?? '' ),
			'status'             => (string) ( $base['status'] ?? 'unknown' ),
			'active'             => $eligible,
			'suspended'          => ! empty( $base['suspended'] ),
			'identity_assurance' => self::identity_assurance( $base ),
			'mfa_required'       => false,
			'mfa_owner'          => 'none',
			'guardian_required'  => ! empty( $app['guardian_required'] ),
			'guardian_verified'  => ! empty( $base['guardian_verified'] ),
			'policy_version'     => (string) ( $app['policy_version'] ?? smc_policy()['version'] ),
		);
		$envelope['age_context']          = $age;
		$envelope['jurisdiction_context'] = $jurisdiction;
		$envelope['capabilities']         = $capabilities;

		if ( ! in_array( $action, self::$actions, true ) ) {
			$envelope['reason_code'] = 'unsupported_action';
			return $envelope;
		}
		if ( ! empty( $jurisdiction['mismatch'] ) ) {
			$envelope['result']      = 'deny';
			$envelope['reason_code'] = 'jurisdiction_mismatch';
			return $envelope;
		}
		if ( empty( $capabilities[ $action ] ) ) {
			$envelope['result']      = 'deny';
			$envelope['reason_code'] = ! empty( $base['suspended'] ) ? 'membership_suspended' : 'membership_prerequisite_denied';
			return $envelope;
		}
		$envelope['result']      = 'allow';
		$envelope['reason_code'] = 'membership_prerequisite_satisfied';
		return $envelope;
	}

	/**
	 * Compatibility endpoint retained only so an older CF-01 consumer receives a
	 * deterministic, fail-safe answer instead of a fatal missing-method error.
	 *
	 * File 00 deliberately ignores the supplied code and does not inspect any
	 * authenticator/recovery-factor storage. Consumers needing stronger
	 * authentication must call File 02 (or another approved authentication
	 * owner) through a separately versioned contract.
	 *
	 * @param int    $user_id Subject user ID.
	 * @param string $code Retained compatibility parameter; never processed.
	 * @param array  $context Purpose, scope and trace context.
	 * @return array<string,mixed>
	 */
	public static function verify_step_up( $user_id, $code, $context = array() ) {
		unset( $code );
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();
		$purpose = sanitize_key( $context['purpose'] ?? '' );
		$scope   = trim( (string) ( $context['scope'] ?? '' ) );
		$trace   = self::trace_id( $context['trace_id'] ?? '' );
		$now     = time();
		$scope_hash = '' !== $scope ? SMC_Security::blind_index( $scope, 'cf01-auth-assurance-scope' ) : new WP_Error( 'smc_cf01_scope' );
		$result = array(
			'contract'          => self::CONTRACT_NAME . '.authentication-assurance',
			'contract_version'  => self::CONTRACT_VERSION,
			'producer_version'  => defined( 'SMC_VERSION' ) ? SMC_VERSION : '',
			'subject_uuid'      => '',
			'purpose'           => $purpose,
			'scope_hash'        => is_wp_error( $scope_hash ) ? '' : $scope_hash,
			'owner'             => 'file02_or_consumer',
			'method'            => 'not_owned_by_file00',
			'issued_at'         => gmdate( 'c', $now ),
			'expires_at'        => gmdate( 'c', $now + self::ASSERTION_TTL ),
			'verified_at'       => '',
			'trace_id'          => $trace,
			'result'            => 'unknown',
			'reason_code'       => 'authentication_assurance_not_owned_by_file00',
			'file00_mfa_active' => false,
		);
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			$result['reason_code'] = 'subject_unavailable';
			return $result;
		}
		$result['subject_uuid'] = self::ensure_subject_uuid( $user_id );
		if ( '' === $result['subject_uuid'] ) {
			$result['reason_code'] = 'subject_uuid_unavailable';
			return $result;
		}
		if ( '' === $purpose || is_wp_error( $scope_hash ) ) {
			$result['reason_code'] = 'unsupported_purpose_or_scope';
		}
		return $result;
	}

	private static function identity_assurance( $base ) {
		if ( empty( $base['eligible'] ) ) {
			return 'none';
		}
		if ( ! empty( $base['professional_verified'] ) && ! empty( $base['phone_verified'] ) && ! empty( $base['email_verified'] ) && ! empty( $base['identity_documents_current'] ) ) {
			return 'verified_membership_identity';
		}
		return 'basic_membership_identity';
	}

	private static function age_context( $user_id, $app ) {
		$out = array( 'known' => false, 'age_years' => null, 'guardian_required' => ! empty( $app['guardian_required'] ) );
		if ( ! $app || empty( $app['date_of_birth_enc'] ) ) {
			return $out;
		}
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => absint( $user_id ) ) );
		$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		if ( false !== $age ) {
			$out['known']     = true;
			$out['age_years'] = (int) $age;
		}
		return $out;
	}

	private static function jurisdiction_context( $user_id, $context ) {
		$requested = strtoupper( preg_replace( '/[^A-Z]/i', '', (string) ( $context['jurisdiction'] ?? '' ) ) );
		$requested = 2 === strlen( $requested ) ? $requested : '';
		global $wpdb;
		$canonical = (string) $wpdb->get_var( $wpdb->prepare( "SELECT issuing_country FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1", absint( $user_id ) ) );
		$canonical = strtoupper( preg_replace( '/[^A-Z]/i', '', $canonical ) );
		$canonical = 2 === strlen( $canonical ) ? $canonical : '';
		return array(
			'known'             => '' !== $canonical || '' !== $requested,
			'canonical_country' => $canonical,
			'requested_country' => $requested,
			'mismatch'          => '' !== $canonical && '' !== $requested && ! hash_equals( $canonical, $requested ),
		);
	}

	private static function trace_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return self::valid_uuid( $value ) ? $value : strtolower( wp_generate_uuid4() );
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}
}

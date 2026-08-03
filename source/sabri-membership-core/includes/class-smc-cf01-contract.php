<?php
defined( 'ABSPATH' ) || exit;

/**
 * Privacy-minimal, server-side membership and second-factor provider contract
 * for CF-01. This class exposes assertions, never File 00 storage internals.
 */
final class SMC_CF01_Contract {
	const CONTRACT_NAME    = 'smc.cf01.membership-assurance';
	const CONTRACT_VERSION = '1.0.0';
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

	private static $step_up_purposes = array(
		'clinical_sign_in',
		'prescription_sign',
		'clinical_export',
		'clinical_transfer',
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
		if ( ! add_user_meta( $user_id, '_smc_platform_uuid_v1', $candidate, true ) ) {
			$candidate = (string) get_user_meta( $user_id, '_smc_platform_uuid_v1', true );
		}
		if ( ! self::valid_uuid( $candidate ) ) {
			return '';
		}
		SMC_Security::audit( 'platform_subject_uuid_created', $user_id, array( 'contract' => self::CONTRACT_VERSION ) );
		return strtolower( $candidate );
	}

	/**
	 * Return a bounded, versioned membership assertion for one requested action.
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
			'contract'         => self::CONTRACT_NAME,
			'contract_version' => self::CONTRACT_VERSION,
			'producer_version' => defined( 'SMC_VERSION' ) ? SMC_VERSION : '',
			'issued_at'        => gmdate( 'c', $now ),
			'expires_at'       => gmdate( 'c', $now + self::ASSERTION_TTL ),
			'trace_id'         => $trace,
			'action'           => $action,
			'purpose'          => $purpose,
			'result'           => 'unknown',
			'reason_code'      => 'subject_unavailable',
		);
		if ( ! $user ) {
			return $envelope;
		}

		$subject_uuid = self::ensure_subject_uuid( $user_id );
		if ( '' === $subject_uuid ) {
			$envelope['reason_code'] = 'subject_uuid_unavailable';
			return $envelope;
		}

		$state = smc_membership_state( $user_id );
		$app   = ! empty( $state['application_exists'] ) ? smc_application( $user_id ) : false;
		$base  = SMC_Contracts::assertions( $user_id );
		$age   = self::age_context( $user_id, $app );
		$jurisdiction = self::jurisdiction_context( $user_id, $context );
		$record_version = $app ? (int) ( $app['row_version'] ?? 0 ) : 0;

		$capabilities = array(
			'clinical_identity_link' => ! empty( $base['eligible'] ),
			'clinical_read'          => ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ),
			'clinical_write'         => ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ),
			'prescription_sign'      => ! empty( $base['can_practice'] ) && ! empty( $base['session_two_factor'] ),
			'clinical_export'        => ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ),
			'break_glass'            => ! empty( $base['can_practice'] ) && ! empty( $base['session_two_factor'] ),
			'guardian_sensitive'     => ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && ( empty( $app['guardian_required'] ) || ! empty( $base['guardian_verified'] ) ),
			'key_recovery'           => false,
		);

		$envelope['subject'] = array(
			'platform_uuid' => $subject_uuid,
			'source_owner'  => 'File 00',
			'record_version'=> $record_version,
		);
		$envelope['membership'] = array(
			'account_class'         => (string) ( $base['account_class'] ?? 'member' ),
			'membership_type'       => (string) ( $base['membership_type'] ?? '' ),
			'status'                => (string) ( $base['status'] ?? 'unknown' ),
			'active'                => ! empty( $base['eligible'] ),
			'suspended'             => ! empty( $base['suspended'] ),
			'identity_assurance'    => self::identity_assurance( $base ),
			'two_factor_ready'      => ! empty( $base['two_factor_ready'] ),
			'session_two_factor'    => ! empty( $base['session_two_factor'] ),
			'guardian_required'     => ! empty( $app['guardian_required'] ),
			'guardian_verified'     => ! empty( $base['guardian_verified'] ),
			'policy_version'        => (string) ( $app['policy_version'] ?? smc_policy()['version'] ),
		);
		$envelope['age_context'] = $age;
		$envelope['jurisdiction_context'] = $jurisdiction;
		$envelope['capabilities'] = $capabilities;

		if ( ! in_array( $action, self::$actions, true ) ) {
			$envelope['reason_code'] = 'unsupported_action';
			return $envelope;
		}
		if ( empty( $capabilities[ $action ] ) ) {
			$envelope['result'] = 'deny';
			$envelope['reason_code'] = ! empty( $base['suspended'] ) ? 'membership_suspended' : 'capability_denied';
			return $envelope;
		}
		$envelope['result'] = 'allow';
		$envelope['reason_code'] = 'capability_allowed';
		return $envelope;
	}

	/**
	 * Verify a File 00-owned second factor without exposing its secret or storage.
	 * File 02 may consume only the structured result and must issue its own
	 * purpose/session-bound assurance receipt.
	 *
	 * @param int   $user_id Subject user ID.
	 * @param string $code TOTP or recovery code.
	 * @param array $context Purpose, scope and trace context.
	 * @return array<string,mixed>
	 */
	public static function verify_step_up( $user_id, $code, $context = array() ) {
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();
		$purpose = sanitize_key( $context['purpose'] ?? '' );
		$scope   = sanitize_key( $context['scope'] ?? '' );
		$trace   = self::trace_id( $context['trace_id'] ?? '' );
		$now     = time();
		$result  = array(
			'contract'         => self::CONTRACT_NAME . '.step-up',
			'contract_version' => self::CONTRACT_VERSION,
			'producer_version' => defined( 'SMC_VERSION' ) ? SMC_VERSION : '',
			'subject_uuid'     => '',
			'purpose'          => $purpose,
			'scope'            => $scope,
			'method'           => '',
			'verified_at'      => '',
			'trace_id'         => $trace,
			'result'           => 'unknown',
			'reason_code'      => 'subject_unavailable',
		);
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return $result;
		}
		$result['subject_uuid'] = self::ensure_subject_uuid( $user_id );
		if ( '' === $result['subject_uuid'] ) {
			$result['reason_code'] = 'subject_uuid_unavailable';
			return $result;
		}
		if ( ! in_array( $purpose, self::$step_up_purposes, true ) || '' === $scope ) {
			$result['reason_code'] = 'unsupported_purpose_or_scope';
			return $result;
		}
		$membership = self::membership_assertion( $user_id, array( 'action' => self::action_for_purpose( $purpose ), 'purpose' => $purpose, 'trace_id' => $trace ) );
		if ( 'allow' !== $membership['result'] && 'clinical_sign_in' !== $purpose ) {
			$result['result'] = 'deny';
			$result['reason_code'] = 'membership_not_eligible';
			return $result;
		}
		if ( ! SMC_Security::two_factor_ready( $user_id ) ) {
			$result['result'] = 'deny';
			$result['reason_code'] = 'second_factor_not_configured';
			return $result;
		}

		$code = trim( (string) $code );
		$verified = false;
		$method = '';
		if ( preg_match( '/^[0-9]{6}$/', $code ) ) {
			$encrypted = get_user_meta( $user_id, '_smc_totp_secret_enc', true );
			$secret = $encrypted ? SMC_Security::decrypt( $encrypted, 'totp-secret', array( 'user_id' => $user_id ) ) : new WP_Error( 'smc_totp_missing' );
			$verified = ! is_wp_error( $secret ) && SMC_Security::verify_setup_code( $secret, $code );
			$method = 'totp';
		} else {
			$verified = SMC_Security::consume_recovery_code( $user_id, $code );
			$method = 'recovery_code';
		}
		if ( ! $verified ) {
			SMC_Security::audit( 'cf01_step_up_failed', $user_id, array( 'purpose' => $purpose, 'scope' => $scope, 'method' => $method, 'trace_id' => $trace ) );
			$result['result'] = 'deny';
			$result['reason_code'] = 'second_factor_invalid';
			$result['method'] = $method;
			return $result;
		}
		if ( ! SMC_Security::audit( 'cf01_step_up_verified', $user_id, array( 'purpose' => $purpose, 'scope' => $scope, 'method' => $method, 'trace_id' => $trace ) ) ) {
			$result['result'] = 'deny';
			$result['reason_code'] = 'audit_commit_failed';
			$result['method'] = $method;
			return $result;
		}
		$result['result'] = 'allow';
		$result['reason_code'] = 'second_factor_verified';
		$result['method'] = $method;
		$result['verified_at'] = gmdate( 'c', $now );
		return $result;
	}

	private static function action_for_purpose( $purpose ) {
		$map = array(
			'clinical_sign_in'  => 'clinical_identity_link',
			'prescription_sign' => 'prescription_sign',
			'clinical_export'   => 'clinical_export',
			'clinical_transfer' => 'clinical_export',
			'break_glass'       => 'break_glass',
			'guardian_sensitive'=> 'guardian_sensitive',
			'key_recovery'      => 'key_recovery',
		);
		return $map[ $purpose ] ?? '';
	}

	private static function identity_assurance( $base ) {
		if ( empty( $base['eligible'] ) ) {
			return 'none';
		}
		if ( ! empty( $base['professional_verified'] ) && ! empty( $base['phone_verified'] ) && ! empty( $base['email_verified'] ) && ! empty( $base['two_factor_ready'] ) ) {
			return 'verified';
		}
		return 'basic';
	}

	private static function age_context( $user_id, $app ) {
		$out = array( 'known' => false, 'age_years' => null, 'guardian_required' => ! empty( $app['guardian_required'] ) );
		if ( ! $app || empty( $app['date_of_birth_enc'] ) ) {
			return $out;
		}
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => absint( $user_id ) ) );
		$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		if ( false !== $age ) {
			$out['known'] = true;
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
			'known'              => '' !== $canonical || '' !== $requested,
			'canonical_country'  => $canonical,
			'requested_country'  => $requested,
			'mismatch'           => '' !== $canonical && '' !== $requested && ! hash_equals( $canonical, $requested ),
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

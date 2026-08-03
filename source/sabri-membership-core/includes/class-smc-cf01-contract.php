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
	const STEP_UP_LIMIT     = 7;
	const STEP_UP_WINDOW    = 900;

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
		add_action( 'smc_cf01_clear_replay_marker', array( __CLASS__, 'clear_replay_marker' ), 10, 1 );
	}

	public static function clear_replay_marker( $option_name ) {
		$option_name = sanitize_key( $option_name );
		if ( 0 === strpos( $option_name, 'smc_cf01_replay_' ) ) {
			delete_option( $option_name );
		}
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
		$created = add_user_meta( $user_id, '_smc_platform_uuid_v1', $candidate, true );
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
			'platform_uuid'  => $subject_uuid,
			'source_owner'   => 'File 00',
			'record_version' => $record_version,
		);
		$envelope['membership'] = array(
			'account_class'      => (string) ( $base['account_class'] ?? 'member' ),
			'membership_type'    => (string) ( $base['membership_type'] ?? '' ),
			'status'             => (string) ( $base['status'] ?? 'unknown' ),
			'active'             => ! empty( $base['eligible'] ),
			'suspended'          => ! empty( $base['suspended'] ),
			'identity_assurance' => self::identity_assurance( $base ),
			'two_factor_ready'   => ! empty( $base['two_factor_ready'] ),
			'session_two_factor' => ! empty( $base['session_two_factor'] ),
			'guardian_required'  => ! empty( $app['guardian_required'] ),
			'guardian_verified'  => ! empty( $base['guardian_verified'] ),
			'policy_version'     => (string) ( $app['policy_version'] ?? smc_policy()['version'] ),
		);
		$envelope['age_context'] = $age;
		$envelope['jurisdiction_context'] = $jurisdiction;
		$envelope['capabilities'] = $capabilities;

		if ( ! in_array( $action, self::$actions, true ) ) {
			$envelope['reason_code'] = 'unsupported_action';
			return $envelope;
		}
		if ( ! empty( $jurisdiction['mismatch'] ) ) {
			$envelope['result'] = 'deny';
			$envelope['reason_code'] = 'jurisdiction_mismatch';
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
	 * This proves authentication only. It never grants a clinical permission.
	 *
	 * @param int    $user_id Subject user ID.
	 * @param string $code TOTP or recovery code.
	 * @param array  $context Purpose, opaque scope and trace context.
	 * @return array<string,mixed>
	 */
	public static function verify_step_up( $user_id, $code, $context = array() ) {
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();
		$purpose = sanitize_key( $context['purpose'] ?? '' );
		$scope   = trim( (string) ( $context['scope'] ?? '' ) );
		$trace   = self::trace_id( $context['trace_id'] ?? '' );
		$now     = time();
		$scope_hash = '' !== $scope ? SMC_Security::blind_index( $scope, 'cf01-step-up-scope' ) : new WP_Error( 'smc_cf01_scope' );
		$result  = array(
			'contract'         => self::CONTRACT_NAME . '.step-up',
			'contract_version' => self::CONTRACT_VERSION,
			'producer_version' => defined( 'SMC_VERSION' ) ? SMC_VERSION : '',
			'subject_uuid'     => '',
			'purpose'          => $purpose,
			'scope_hash'       => is_wp_error( $scope_hash ) ? '' : $scope_hash,
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
		if ( ! in_array( $purpose, self::$step_up_purposes, true ) || is_wp_error( $scope_hash ) ) {
			$result['reason_code'] = 'unsupported_purpose_or_scope';
			return $result;
		}
		if ( self::step_up_rate_limited( $user_id, $purpose ) ) {
			$result['result'] = 'deny';
			$result['reason_code'] = 'rate_limited';
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
		$replay_marker = '';
		if ( preg_match( '/^[0-9]{6}$/', $code ) ) {
			$encrypted = get_user_meta( $user_id, '_smc_totp_secret_enc', true );
			$secret = $encrypted ? SMC_Security::decrypt( $encrypted, 'totp-secret', array( 'user_id' => $user_id ) ) : new WP_Error( 'smc_totp_missing' );
			$verified = ! is_wp_error( $secret ) && SMC_Security::verify_setup_code( $secret, $code );
			$method = 'totp';
			if ( $verified ) {
				$replay_marker = self::claim_totp_code( $user_id, $code );
				$verified = '' !== $replay_marker;
			}
		} else {
			$verified = self::consume_recovery_code_atomic( $user_id, $code, $purpose, $trace );
			$method = 'recovery_code';
		}
		if ( ! $verified ) {
			SMC_Security::audit( 'cf01_step_up_failed', $user_id, array( 'purpose' => $purpose, 'scope_hash' => $result['scope_hash'], 'method' => $method, 'trace_id' => $trace ) );
			$result['result'] = 'deny';
			$result['reason_code'] = 'second_factor_invalid_or_replayed';
			$result['method'] = $method;
			return $result;
		}
		if ( ! SMC_Security::audit( 'cf01_step_up_verified', $user_id, array( 'purpose' => $purpose, 'scope_hash' => $result['scope_hash'], 'method' => $method, 'trace_id' => $trace ) ) ) {
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

	private static function consume_recovery_code_atomic( $user_id, $code, $purpose, $trace ) {
		global $wpdb;
		$code = strtoupper( trim( (string) $code ) );
		$lookup = SMC_Security::blind_index( $code, 'recovery-code' );
		if ( is_wp_error( $lookup ) ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d AND code_lookup_hash=%s AND consumed_at IS NULL LIMIT 1 FOR UPDATE",
				absint( $user_id ),
				$lookup
			),
			ARRAY_A
		);
		if ( ! $row || ! wp_check_password( $code, $row['code_hash'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL", $now, (int) $row['id'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'cf01_recovery_code_used', $user_id, array( 'purpose' => $purpose, 'trace_id' => $trace ) );
		if ( 1 !== $updated || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	private static function claim_totp_code( $user_id, $code ) {
		$hash = SMC_Security::blind_index( absint( $user_id ) . '|' . trim( (string) $code ), 'cf01-step-up-replay' );
		if ( is_wp_error( $hash ) ) {
			return '';
		}
		$name = 'smc_cf01_replay_' . substr( $hash, 0, 40 );
		if ( ! add_option( $name, time() + 120, '', false ) ) {
			$expires = (int) get_option( $name, 0 );
			if ( $expires >= time() ) {
				return '';
			}
			delete_option( $name );
			if ( ! add_option( $name, time() + 120, '', false ) ) {
				return '';
			}
		}
		if ( ! wp_next_scheduled( 'smc_cf01_clear_replay_marker', array( $name ) ) ) {
			wp_schedule_single_event( time() + 180, 'smc_cf01_clear_replay_marker', array( $name ) );
		}
		return $name;
	}

	private static function step_up_rate_limited( $user_id, $purpose ) {
		$key = 'smc_cf01_rate_' . substr( hash( 'sha256', absint( $user_id ) . '|' . sanitize_key( $purpose ) ), 0, 32 );
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array( 'count' => 0, 'started' => time() );
		if ( time() - (int) $state['started'] >= self::STEP_UP_WINDOW ) {
			$state = array( 'count' => 0, 'started' => time() );
		}
		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, self::STEP_UP_WINDOW );
		return $state['count'] > self::STEP_UP_LIMIT;
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

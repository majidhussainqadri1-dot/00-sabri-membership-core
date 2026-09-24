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
		add_filter( 'sabri_file00_platform_uuid_v1', array( __CLASS__, 'filter_platform_uuid' ), 10, 3 );
		add_filter( 'sabri_file00_legacy_author_placeholder_v1', array( __CLASS__, 'legacy_author_placeholder' ), 10, 2 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_placeholder_login' ), 99, 2 );
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
	 * File 04 authorship bridge: resolve only File 00-owned immutable UUID truth.
	 * An earlier valid provider wins; this bridge never guesses from display
	 * names, e-mail addresses, roles or legacy metadata.
	 */
	public static function filter_platform_uuid( $existing, $user_id, $context = array() ) {
		$existing = strtolower( trim( (string) $existing ) );
		if ( self::valid_uuid( $existing ) ) {
			return $existing;
		}
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return '';
		}
		$file_number = sanitize_key( (string) ( $context['file_number'] ?? '' ) );
		$purpose = sanitize_key( (string) ( $context['purpose'] ?? '' ) );
		if ( '04' !== $file_number || 'legacy_publication_migration' !== $purpose ) {
			return '';
		}
		return self::ensure_subject_uuid( $user_id );
	}

	/**
	 * Return a dedicated non-login File 00 placeholder for deleted/unknown
	 * legacy authors. The placeholder is a transparent attribution sentinel,
	 * never a real person's identity.
	 */
	public static function legacy_author_placeholder( $existing, $request = array() ) {
		if ( is_array( $existing ) && ! empty( $existing['verified'] ) ) {
			return $existing;
		}
		$request = is_array( $request ) ? $request : array();
		$legacy_id = absint( $request['legacy_id'] ?? 0 );
		$legacy_author_id = absint( $request['legacy_author_id'] ?? 0 );
		$digest = strtolower( trim( (string) ( $request['request_digest'] ?? '' ) ) );
		if ( '04' !== sanitize_key( (string) ( $request['file_number'] ?? '' ) )
			|| $legacy_id <= 0
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		if ( ! is_user_logged_in() || ! ( current_user_can( 'manage_options' ) || current_user_can( 'sabri_feed_run_migrations' ) ) ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		$user_id = self::ensure_legacy_author_placeholder_user();
		if ( is_wp_error( $user_id ) || $user_id <= 0 ) {
			return array(
				'verified' => false,
				'provider_id' => 'file00_legacy_author_placeholder_v1',
				'error_code' => is_wp_error( $user_id ) ? $user_id->get_error_code() : 'placeholder_unavailable',
			);
		}
		$uuid = self::ensure_subject_uuid( $user_id );
		if ( ! self::valid_uuid( $uuid ) ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		return array(
			'verified'         => true,
			'provider_id'      => 'file00_legacy_author_placeholder_v1',
			'user_id'          => $user_id,
			'platform_uuid'    => $uuid,
			'legacy_id'        => $legacy_id,
			'legacy_author_id' => $legacy_author_id,
			'request_digest'   => $digest,
			'attribution_class'=> 'unknown_or_deleted_legacy_author',
		);
	}

	private static function ensure_legacy_author_placeholder_user() {
		$login = 'sabri_legacy_unknown_author';
		$user = get_user_by( 'login', $login );
		if ( $user ) {
			$user_id = absint( $user->ID );
			return $user_id > 0 && '1' === (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_v1', true )
				? $user_id
				: new WP_Error( 'smc_legacy_author_placeholder_collision', 'The reserved legacy-author login is already bound to another identity.' );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => 'Legacy Author (Unknown/Deleted)',
				'user_nicename'=> 'legacy-author-unknown-deleted',
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = absint( $user_id );
		$marked = add_user_meta( $user_id, '_smc_legacy_author_placeholder_v1', '1', true );
		$uuid = $marked ? self::ensure_subject_uuid( $user_id ) : '';
		$audit = $marked && self::valid_uuid( $uuid ) && SMC_Security::audit(
			'legacy_author_placeholder_created',
			$user_id,
			array( 'contract' => self::CONTRACT_VERSION, 'purpose' => 'file04_legacy_publication_migration' )
		);
		if ( ! $marked || ! self::valid_uuid( $uuid ) || ! $audit ) {
			if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) ) {
				$admin_user_file = ABSPATH . 'wp-admin/includes/user.php';
				if ( is_readable( $admin_user_file ) ) { require_once $admin_user_file; }
			}
			if ( function_exists( 'wp_delete_user' ) ) { wp_delete_user( $user_id ); }
			return new WP_Error( 'smc_legacy_author_placeholder_create_failed', 'The governed legacy-author placeholder could not be created atomically.' );
		}
		return $user_id;
	}

	public static function block_placeholder_login( $user, $password ) {
		unset( $password );
		if ( $user instanceof WP_User && '1' === (string) get_user_meta( $user->ID, '_smc_legacy_author_placeholder_v1', true ) ) {
			return new WP_Error( 'smc_legacy_author_placeholder_login_denied', __( 'This system attribution identity cannot sign in.', 'sabri-membership-core' ) );
		}
		return $user;
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

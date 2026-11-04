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
		add_filter( 'sabri_file00_platform_uuid_v1', array( __CLASS__, 'file04_platform_uuid' ), 10, 3 );
		add_filter( 'sabri_file00_legacy_author_placeholder_v1', array( __CLASS__, 'file04_legacy_author_placeholder' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'block_placeholder_login' ), 99, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_placeholder_login' ), 99, 2 );
		add_filter( 'determine_current_user', array( __CLASS__, 'block_placeholder_session' ), 99, 1 );
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
	/**
	 * Internal File08 canonical subject lookup: read-only and exactly unique.
	 * No authorization, relationship, clinical permission or role is granted.
	 */
	public static function resolve_user_id_by_subject_uuid( $subject_uuid ) {
		if ( ! is_string( $subject_uuid ) || $subject_uuid !== trim( $subject_uuid ) ) { return 0; }
		$subject_uuid = strtolower( $subject_uuid );
		if ( ! self::valid_uuid( $subject_uuid ) ) { return 0; }
		// File00 owns this internal usermeta key. Read at most two matches to
		// detect collisions instead of arbitrarily choosing someone else's account.
		$matches = get_users( array(
			'fields'     => 'ids',
			'number'     => 2,
			'meta_key'   => '_smc_platform_uuid_v1',
			'meta_value' => $subject_uuid,
		) );
		if ( ! is_array( $matches ) || 1 !== count( $matches ) || ! is_scalar( $matches[0] ) ) { return 0; }
		$user_id = absint( $matches[0] );
		if ( ! $user_id || ! get_userdata( $user_id ) ) { return 0; }
		$stored = get_user_meta( $user_id, '_smc_platform_uuid_v1', true );
		if ( ! self::valid_uuid( $stored ) || ! hash_equals( $subject_uuid, strtolower( $stored ) ) ) { return 0; }
		return $user_id;
	}

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
	 * File 04 compatibility projection for the canonical immutable subject UUID.
	 * The filter never invents an identity outside File 00.
	 */
	public static function file04_platform_uuid( $existing, $user_id, $context = array() ) {
		// The filter's prior value is untrusted. A public or other-module
		// invocation cannot mint an unrelated member's canonical UUID.
		unset( $existing );
		if ( ! self::file04_migration_authorized( $context, false ) ) {
			return '';
		}
		return self::ensure_subject_uuid( absint( $user_id ) );
	}

	/**
	 * Governed deleted/unknown legacy-author placeholder for File 04 migration.
	 * A single non-login service identity is created and owned by File 00 so
	 * migration never misattributes unknown authors to a real member.
	 */
	private static function file04_migration_authorized( $context, $placeholder = false ) {
		$context = is_array( $context ) ? $context : array();
		if ( '04' !== (string) ( $context['file_number'] ?? '' )
			|| absint( $context['legacy_id'] ?? 0 ) <= 0
			|| ( ! $placeholder && 'legacy_publication_migration' !== (string) ( $context['purpose'] ?? '' ) )
			|| ! function_exists( 'get_current_user_id' )
			|| absint( get_current_user_id() ) <= 0
			|| ! function_exists( 'current_user_can' )
			|| ( ! current_user_can( 'sabri_file04_run_migrations' )
				&& ! current_user_can( 'sabri_feed_run_migrations' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Independently reproduce File04's SHA256 of a canonically sorted,
	 * JSON-encoded, three-field migration request. A 64-hex appearance alone
	 * is not a proof that the request fields were bound.
	 */
	private static function file04_request_digest( $legacy_id, $legacy_author_id ) {
		$payload = array(
			'file_number' => '04',
			'legacy_id' => absint( $legacy_id ),
			'legacy_author_id' => absint( $legacy_author_id ),
		);
		ksort( $payload, SORT_STRING );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	public static function file04_legacy_author_placeholder( $existing, $request = array() ) {
		// Untrusted upstream filter outputs never certify File00 authorship.
		unset( $existing );
		$request = is_array( $request ) ? $request : array();
		$legacy_id = absint( $request['legacy_id'] ?? 0 );
		$legacy_author_id = absint( $request['legacy_author_id'] ?? 0 );
		$request_digest = strtolower( trim( (string) ( $request['request_digest'] ?? '' ) ) );
		$expected_digest = self::file04_request_digest( $legacy_id, $legacy_author_id );
		if ( ! self::file04_migration_authorized( $request, true )
			|| ! is_string( $request['request_digest'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request_digest )
			|| '' === $expected_digest
			|| ! hash_equals( $expected_digest, $request_digest ) ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		$user_id = self::ensure_legacy_author_placeholder_user();
		if ( $user_id <= 0 ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		$uuid = self::ensure_subject_uuid( $user_id );
		if ( ! self::valid_uuid( $uuid ) ) {
			return array( 'verified' => false, 'provider_id' => 'file00_legacy_author_placeholder_v1' );
		}
		return array(
			'verified'         => true,
			'provider_id'      => 'file00_legacy_author_placeholder_v1',
			'legacy_id'        => $legacy_id,
			'legacy_author_id' => $legacy_author_id,
			'user_id'          => $user_id,
			'platform_uuid'    => $uuid,
			'request_digest'   => $request_digest,
			'placeholder'      => true,
			'owner'            => 'File 00',
			'contract_version' => defined( 'SMC_CF01_CONTRACT_VERSION' ) ? SMC_CF01_CONTRACT_VERSION : self::CONTRACT_VERSION,
		);
	}

	/**
	 * A legacy-author sentinel is attribution metadata, never a principal.
	 * Password authentication and session-cookie current-user resolution must
	 * both fail closed, even when earlier DB writes left the markers missing.
	 */
	private static function is_disabled_legacy_author_identity( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		$login = $user ? (string) ( $user->user_login ?? '' ) : '';
		return 0 === strcasecmp( $login, 'sabri_legacy_author_placeholder' )
			|| '1' === (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_v1', true )
			|| '1' === (string) get_user_meta( $user_id, '_smc_login_disabled_v1', true );
	}

	/**
	 * WordPress cookie auth / programmatic current-user resolution hard stop.
	 * Preserve unrelated existing principal IDs and false/zero values.
	 */
	public static function block_placeholder_session( $user_id ) {
		if ( is_numeric( $user_id ) && self::is_disabled_legacy_author_identity( absint( $user_id ) ) ) {
			return 0;
		}
		return $user_id;
	}

	public static function block_placeholder_login( $user, $username = '', $password = '' ) {
		unset( $username, $password );
		if ( $user instanceof WP_User
			&& ( self::is_disabled_legacy_author_identity( $user->ID )
				|| 0 === strcasecmp( (string) ( $user->user_login ?? '' ), 'sabri_legacy_author_placeholder' ) ) ) {
			return new WP_Error( 'smc_legacy_author_placeholder_login_denied',
				__( 'This system attribution identity cannot sign in.', 'sabri-membership-core' ) );
		}
		return $user;
	}

	private static function ensure_legacy_author_placeholder_user() {
		$login = 'sabri_legacy_author_placeholder';
		$user_id = function_exists( 'username_exists' ) ? absint( username_exists( $login ) ) : 0;
		if ( $user_id > 0 ) {
			// Reserved login collision is not authority to relabel a real
			// person as a non-login legacy attribution sentinel.
			if ( ! get_userdata( $user_id )
				|| '1' !== (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_v1', true )
				|| '1' !== (string) get_user_meta( $user_id, '_smc_login_disabled_v1', true ) ) {
				return 0;
			}
			// A failed create-audit followed by failed deletion must not be
			// laundered into a verified identity on a subsequent request.
			// Existing pre-upgrade sentinels need fresh recovery evidence.
			if ( '1' !== (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_audited_v1', true ) ) {
				if ( ! SMC_Security::audit( 'legacy_author_placeholder_recovered', $user_id, array( 'contract' => 'file04-authorship-v1' ) ) ) {
					return 0;
				}
				update_user_meta( $user_id, '_smc_legacy_author_placeholder_audited_v1', 1 );
				if ( '1' !== (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_audited_v1', true ) ) {
					return 0;
				}
			}
			return $user_id;
		}
		if ( ! function_exists( 'wp_insert_user' ) ) {
			return 0;
		}
		$password = function_exists( 'wp_generate_password' ) ? wp_generate_password( 64, true, true ) : wp_generate_uuid4() . wp_generate_uuid4();
		$created = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $password,
				'display_name' => 'Legacy Author (Unknown)',
				'nickname'     => 'Legacy Author',
				'role'         => 'subscriber',
				'description'  => 'File 00 governed placeholder used only to preserve unknown/deleted legacy publication attribution during migration.',
				// Install the no-login markers as early as WordPress allows,
				// not after exposing a newly created subscriber principal.
				'meta_input'   => array(
					'_smc_legacy_author_placeholder_v1' => 1,
					'_smc_login_disabled_v1'            => 1,
				),
			)
		);
		if ( is_wp_error( $created ) || absint( $created ) <= 0 ) {
			return 0;
		}
		$user_id = absint( $created );
		$record = get_userdata( $user_id );
		// Never compensate by deleting a different principal if a provider
		// returned a stale or ambiguous account ID.
		if ( ! $record || (string) ( $record->user_login ?? '' ) !== $login ) {
			return 0;
		}
		foreach ( array( '_smc_legacy_author_placeholder_v1', '_smc_login_disabled_v1' ) as $marker ) {
			if ( '1' !== (string) get_user_meta( $user_id, $marker, true ) ) {
				update_user_meta( $user_id, $marker, 1 );
			}
			// WordPress update_user_meta may report false for an unchanged
			// value; the persisted readback, not that return value, decides.
			if ( '1' !== (string) get_user_meta( $user_id, $marker, true ) ) {
				return self::rollback_unverified_legacy_author_placeholder( $user_id );
			}
		}
		if ( ! SMC_Security::audit( 'legacy_author_placeholder_created', $user_id, array( 'contract' => 'file04-authorship-v1' ) ) ) {
			return self::rollback_unverified_legacy_author_placeholder( $user_id );
		}
		// Completion must be durable and distinguishable from an unaudited
		// orphan left by a failed compensating delete.
		update_user_meta( $user_id, '_smc_legacy_author_placeholder_audited_v1', 1 );
		if ( '1' !== (string) get_user_meta( $user_id, '_smc_legacy_author_placeholder_audited_v1', true ) ) {
			return self::rollback_unverified_legacy_author_placeholder( $user_id );
		}
		return $user_id;
	}

	/**
	 * Best-effort compensation for a failed new-user creation saga. Never
	 * return a verified File04 identity when persistence or audit failed.
	 * Failure of wp_delete_user itself still needs an operator DB review.
	 */
	private static function rollback_unverified_legacy_author_placeholder( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id > 0 && ! function_exists( 'wp_delete_user' )
			&& defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( $user_id > 0 && function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id );
		}
		return 0;
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

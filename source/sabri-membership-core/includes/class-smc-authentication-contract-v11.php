<?php

defined( 'ABSPATH' ) || exit;

/**
 * Current File 00 provider v1.1.0 for File 02 account orchestration.
 *
 * The historical v1.0 transaction helper remains internal. This public
 * versioned adapter preserves the Founder-approved File 00 MFA retirement,
 * adds the complete File 02 registration fields, and binds every successful
 * registration result to File 00's canonical opaque platform subject UUID.
 */
final class SMC_Authentication_Contract_V11 {
	const CONTRACT_NAME        = 'smc.authentication-account';
	const CONTRACT_VERSION     = '1.1.0';
	const CITY_META            = '_smc_registration_city_v1';
	const ACCOUNT_TYPE_META    = '_smc_declared_account_type_v1';
	const AUTH_METHOD_META     = '_smc_registration_auth_method_v1';
	const PHOTO_REQUIRED_META  = '_smc_profile_photo_required_v1';
	const GOOGLE_PICTURE_META  = '_smc_google_picture_candidate_v1';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 25 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 25 );
		add_action( 'smc_authentication_contract_manifest_v11', array( __CLASS__, 'manifest' ) );
	}

	public static function register_account( $payload, $context = array() ) {
		$payload = is_array( $payload ) ? $payload : array();
		$context = is_array( $context ) ? $context : array();
		$extra   = self::validate_extra_fields( $payload );
		if ( is_wp_error( $extra ) ) {
			return self::response( 'deny', $extra->get_error_code() );
		}

		$delegated = $payload;
		$generated = '';
		if ( 'google' === $extra['authentication_method'] ) {
			try {
				$generated = bin2hex( random_bytes( 32 ) );
			} catch ( Exception $error ) {
				$generated = wp_generate_password( 64, true, true );
			}
			$delegated['password']         = $generated;
			$delegated['password_confirm'] = $generated;
		}

		$result = SMC_Authentication_Contract::register_account( $delegated, $context );
		$delegated['password'] = '';
		$delegated['password_confirm'] = '';
		$generated = '';
		if ( ! is_array( $result ) || 'allow' !== (string) ( $result['result'] ?? '' ) || empty( $result['user_id'] ) ) {
			return self::normalize( $result );
		}

		$user_id = absint( $result['user_id'] );
		$subject_uuid = self::subject_uuid( $user_id );
		if ( '' === $subject_uuid ) {
			self::quarantine_registration( $user_id, 'subject_uuid_unavailable' );
			return self::response( 'unknown', 'subject_uuid_unavailable', array( 'user_id' => $user_id ) );
		}

		$stored = self::store_extra_fields( $user_id, $extra );
		if ( is_wp_error( $stored ) ) {
			self::quarantine_registration( $user_id, $stored->get_error_code() );
			return self::response( 'unknown', 'registration_extra_initialization_failed', array( 'user_id' => $user_id ) );
		}

		delete_user_meta( $user_id, '_smc_registration_quarantine' );
		SMC_Security::audit(
			'authentication_account_registration_v11_completed',
			$user_id,
			array(
				'account_type'          => $extra['account_type'],
				'authentication_method' => $extra['authentication_method'],
				'contract_version'      => self::CONTRACT_VERSION,
				'subject_uuid_bound'    => true,
			)
		);

		$result['subject_uuid'] = $subject_uuid;
		return self::normalize( $result );
	}

	public static function mark_email_verified( $user_id, $email, $context = array() ) {
		return self::normalize( SMC_Authentication_Contract::mark_email_verified( $user_id, $email, $context ) );
	}

	public static function get_completion_state( $user_id, $context = array() ) {
		$user_id = absint( $user_id );
		$result  = SMC_Authentication_Contract::get_completion_state( $user_id, $context );
		if ( ! is_array( $result ) || 'allow' !== (string) ( $result['result'] ?? '' ) ) {
			return self::normalize( $result );
		}

		$missing = isset( $result['missing_steps'] ) && is_array( $result['missing_steps'] ) ? $result['missing_steps'] : array();
		/* Founder change-control: File 00 MFA is retired. Never expose the old v1 helper's two_factor completion step. */
		$missing = array_values( array_diff( $missing, array( 'two_factor' ) ) );

		$city = self::city_value( $user_id );
		if ( is_wp_error( $city ) || '' === trim( (string) $city ) ) {
			$missing[] = 'city';
		}
		if ( '' === trim( (string) get_user_meta( $user_id, self::ACCOUNT_TYPE_META, true ) ) ) {
			$missing[] = 'account_type';
		}
		if ( ! self::ethical_consent_exists( $user_id ) ) {
			$missing[] = 'ethical_conduct';
		}
		if ( ! (bool) apply_filters( 'smc_profile_photo_complete', false, $user_id ) ) {
			$missing[] = 'profile_photo';
		}
		$missing = array_values( array_unique( array_filter( array_map( 'sanitize_key', $missing ) ) ) );

		$next_route = (string) ( $result['next_route'] ?? '' );
		/* The historical helper could point only to retired File 00 MFA. Recompute ownership after removing that step. */
		if ( ! in_array( 'email', $missing, true )
			&& ! array_intersect( array( 'phone', 'guardian', 'identity', 'terms', 'privacy', 'profile' ), $missing )
			&& ! array_intersect( array( 'city', 'account_type', 'ethical_conduct', 'profile_photo' ), $missing ) ) {
			$next_route = '';
		} elseif ( in_array( 'email', $missing, true ) ) {
			$next_route = home_url( '/verify-email/' );
		} elseif ( array_intersect( array( 'phone', 'guardian', 'identity', 'terms', 'privacy', 'profile' ), $missing ) ) {
			$next_route = smc_page_url( 'application', '/membership-application/' );
		} else {
			$next_route = (string) apply_filters( 'smc_profile_completion_route', home_url( '/profile/edit/' ), $user_id, $missing );
		}
		$next_route = '' === $next_route ? '' : wp_validate_redirect( $next_route, '' );
		if ( ! empty( $missing ) && '' === $next_route ) {
			return self::response( 'unknown', 'completion_route_unavailable', array( 'user_id' => $user_id, 'missing_steps' => $missing, 'next_route' => '' ) );
		}

		return self::response(
			'allow',
			empty( $missing ) ? 'completion_satisfied' : 'completion_required',
			array( 'user_id' => $user_id, 'missing_steps' => $missing, 'next_route' => $next_route )
		);
	}

	public static function manifest() {
		return array(
			'contract'         => self::CONTRACT_NAME,
			'contract_version' => self::CONTRACT_VERSION,
			'provider'         => 'File 00 — Sabri Membership Core',
			'methods'          => array( 'register_account', 'mark_email_verified', 'get_completion_state' ),
			'response_fields'  => array( 'user_id', 'subject_uuid', 'missing_steps', 'next_route' ),
			'fail_closed'      => true,
			'identity_owner'   => 'File 00',
			'mfa_owner'        => 'none',
		);
	}

	private static function validate_extra_fields( array $payload ) {
		$city            = trim( sanitize_text_field( (string) ( $payload['city'] ?? '' ) ) );
		$account_type    = sanitize_key( (string) ( $payload['account_type'] ?? '' ) );
		$auth_method     = sanitize_key( (string) ( $payload['authentication_method'] ?? 'password' ) );
		$ethics_version  = sanitize_text_field( (string) ( $payload['ethical_conduct_version'] ?? '' ) );
		$photo_required  = ! empty( $payload['profile_photo_required'] );
		$google_subject  = trim( sanitize_text_field( (string) ( $payload['google_subject'] ?? '' ) ) );
		$google_verified = ! empty( $payload['google_email_verified'] );
		$picture         = esc_url_raw( (string) ( $payload['google_picture_candidate'] ?? '' ) );
		$allowed_types   = array_keys( smc_account_types() );
		$city_length     = function_exists( 'mb_strlen' ) ? mb_strlen( $city, 'UTF-8' ) : strlen( $city );

		if ( $city_length < 2 || $city_length > 120 ) {
			return new WP_Error( 'registration_city_invalid' );
		}
		if ( ! in_array( $account_type, $allowed_types, true ) ) {
			return new WP_Error( 'registration_account_type_invalid' );
		}
		if ( ! in_array( $auth_method, array( 'password', 'google' ), true ) ) {
			return new WP_Error( 'registration_authentication_method_invalid' );
		}
		if ( 'google' === $auth_method && ( ! $google_verified || strlen( $google_subject ) < 6 ) ) {
			return new WP_Error( 'registration_google_proof_invalid' );
		}
		if ( '' === $ethics_version ) {
			return new WP_Error( 'registration_ethical_consent_missing' );
		}
		if ( ! $photo_required ) {
			return new WP_Error( 'registration_profile_photo_requirement_missing' );
		}
		return array(
			'city'                     => $city,
			'account_type'             => $account_type,
			'authentication_method'    => $auth_method,
			'ethical_conduct_version'  => $ethics_version,
			'profile_photo_required'   => true,
			'google_subject'           => $google_subject,
			'google_email_verified'    => $google_verified,
			'google_picture_candidate' => $picture,
		);
	}

	private static function store_extra_fields( $user_id, array $extra ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'registration_subject_invalid' );
		}
		$city_encrypted = SMC_Security::encrypt( $extra['city'], 'registration-city', array( 'user_id' => $user_id ) );
		if ( is_wp_error( $city_encrypted ) ) {
			return $city_encrypted;
		}
		$ok = self::store_meta_exact( $user_id, self::CITY_META, $city_encrypted )
			&& self::store_meta_exact( $user_id, self::ACCOUNT_TYPE_META, $extra['account_type'] )
			&& self::store_meta_exact( $user_id, self::AUTH_METHOD_META, $extra['authentication_method'] )
			&& self::store_meta_exact( $user_id, self::PHOTO_REQUIRED_META, '1' );
		$city_encrypted = '';
		if ( $ok && '' !== $extra['google_picture_candidate'] ) {
			$encrypted = SMC_Security::encrypt( $extra['google_picture_candidate'], 'google-picture-candidate', array( 'user_id' => $user_id ) );
			$ok = ! is_wp_error( $encrypted ) && self::store_meta_exact( $user_id, self::GOOGLE_PICTURE_META, $encrypted );
			$encrypted = '';
		}
		$ok = $ok && self::record_ethical_consent( $user_id, $extra['ethical_conduct_version'] );
		return $ok ? true : new WP_Error( 'registration_extra_store_failed' );
	}

	private static function store_meta_exact( $user_id, $key, $value ) {
		$updated = update_user_meta( absint( $user_id ), (string) $key, $value );
		if ( false === $updated ) {
			$current = get_user_meta( absint( $user_id ), (string) $key, true );
			return hash_equals( (string) $current, (string) $value );
		}
		$current = get_user_meta( absint( $user_id ), (string) $key, true );
		return hash_equals( (string) $current, (string) $value );
	}

	private static function subject_uuid( $user_id ) {
		if ( ! class_exists( 'SMC_CF01_Contract' ) || ! is_callable( array( 'SMC_CF01_Contract', 'ensure_subject_uuid' ) ) ) {
			return '';
		}
		$uuid = (string) SMC_CF01_Contract::ensure_subject_uuid( absint( $user_id ) );
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ? strtolower( $uuid ) : '';
	}

	private static function quarantine_registration( $user_id, $reason ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		SMC_Security::audit( 'authentication_registration_extra_quarantined', $user_id, array( 'reason' => sanitize_key( $reason ) ) );
		SMC_Security::revoke_all_sessions( $user_id, 'registration_extra_initialization_failed' );
		update_user_meta( $user_id, '_smc_registration_quarantine', array( 'reason' => sanitize_key( $reason ), 'created_at' => current_time( 'mysql', true ) ) );
	}

	private static function city_value( $user_id ) {
		$envelope = (string) get_user_meta( absint( $user_id ), self::CITY_META, true );
		return '' === $envelope ? '' : SMC_Security::decrypt( $envelope, 'registration-city', array( 'user_id' => absint( $user_id ) ) );
	}

	private static function record_ethical_consent( $user_id, $version ) {
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose='ethical_conduct' AND policy_version=%s AND withdrawn_at IS NULL LIMIT 1",
				absint( $user_id ),
				(string) $version
			)
		);
		if ( $existing ) {
			return true;
		}
		$text = sprintf( 'Accepted Islamic, professional and institutional Ethical Conduct Charter through File 02 contract %s.', self::CONTRACT_VERSION );
		return 1 === $wpdb->insert(
			$wpdb->prefix . 'smc_consents',
			array(
				'user_id'        => absint( $user_id ),
				'actor_type'     => 'self',
				'purpose'        => 'ethical_conduct',
				'locale'         => function_exists( 'determine_locale' ) ? determine_locale() : 'en_US',
				'channel'        => 'file02_contract',
				'text_snapshot'  => $text,
				'text_hash'      => hash( 'sha256', $text ),
				'policy_version' => sanitize_text_field( (string) $version ),
				'accepted_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function ethical_consent_exists( $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose='ethical_conduct' AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1",
				absint( $user_id )
			)
		);
	}

	private static function normalize( $result ) {
		if ( ! is_array( $result ) ) {
			return self::response( 'unknown', 'provider_contract_invalid' );
		}
		$result['contract'] = self::CONTRACT_NAME;
		$result['contract_version'] = self::CONTRACT_VERSION;
		return $result;
	}

	private static function response( $result, $reason, array $extra = array() ) {
		return array_merge(
			array(
				'contract'         => self::CONTRACT_NAME,
				'contract_version' => self::CONTRACT_VERSION,
				'result'           => in_array( $result, array( 'allow', 'deny', 'unknown' ), true ) ? $result : 'unknown',
				'reason_code'      => sanitize_key( (string) $reason ),
			),
			$extra
		);
	}

	public static function register_exporter( $exporters ) {
		$exporters['sabri-membership-auth-contract-v11'] = array(
			'exporter_friendly_name' => __( 'Sabri Account Registration Completion Fields', 'sabri-membership-core' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$city = self::city_value( $user->ID );
		$city = is_wp_error( $city ) ? __( 'Encrypted value unavailable', 'sabri-membership-core' ) : (string) $city;
		return array(
			'data' => array(
				array(
					'group_id'    => 'smc-authentication-contract-v11',
					'group_label' => __( 'Account Registration Completion', 'sabri-membership-core' ),
					'item_id'     => 'smc-authentication-contract-v11-' . $user->ID,
					'data'        => array(
						array( 'name' => __( 'City', 'sabri-membership-core' ), 'value' => $city ),
						array( 'name' => __( 'Declared account type', 'sabri-membership-core' ), 'value' => (string) get_user_meta( $user->ID, self::ACCOUNT_TYPE_META, true ) ),
						array( 'name' => __( 'Authentication method', 'sabri-membership-core' ), 'value' => (string) get_user_meta( $user->ID, self::AUTH_METHOD_META, true ) ),
					),
				),
			),
			'done' => true,
		);
	}

	public static function register_eraser( $erasers ) {
		$erasers['sabri-membership-auth-contract-v11'] = array(
			'eraser_friendly_name' => __( 'Sabri Account Registration Completion Fields', 'sabri-membership-core' ),
			'callback'               => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$removed = false;
		foreach ( array( self::CITY_META, self::ACCOUNT_TYPE_META, self::AUTH_METHOD_META, self::PHOTO_REQUIRED_META, self::GOOGLE_PICTURE_META ) as $key ) {
			$removed = delete_user_meta( $user->ID, $key ) || $removed;
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}

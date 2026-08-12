<?php

defined( 'ABSPATH' ) || exit;

/**
 * Canonical File 00 provider for File 02 account orchestration.
 *
 * File 02 owns authentication surfaces. File 00 owns membership legitimacy,
 * identity assurance, guardian state, role grants, verification and completion
 * truth. This contract is intentionally fail-closed and versioned.
 */
final class SMC_Authentication_Contract {
	const CONTRACT_NAME    = 'smc.authentication-account';
	const CONTRACT_VERSION = '1.0.0';
	const RECEIPT_META     = '_smc_auth_registration_receipt_v1';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 20 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 20 );
		add_action( 'smc_authentication_contract_manifest', array( __CLASS__, 'manifest' ) );
	}

	/**
	 * Create the WordPress credential subject and initialize File 00 truth.
	 *
	 * @param array<string,mixed> $payload Validated File 02 registration fields.
	 * @param array<string,mixed> $context Purpose/idempotency metadata.
	 * @return array<string,mixed>
	 */
	public static function register_account( $payload, $context = array() ) {
		global $wpdb;

		$payload = is_array( $payload ) ? $payload : array();
		$context = is_array( $context ) ? $context : array();
		if ( SMC_Completion::safe_mode() ) {
			return self::response( 'unknown', 'provider_safe_mode' );
		}

		$validated = self::validate_registration( $payload );
		if ( is_wp_error( $validated ) ) {
			return self::response( 'deny', $validated->get_error_code() );
		}
		$data = $validated;

		$idempotency_key = trim( (string) ( $context['idempotency_key'] ?? '' ) );
		if ( strlen( $idempotency_key ) < 16 || strlen( $idempotency_key ) > 190 ) {
			return self::response( 'deny', 'idempotency_key_invalid' );
		}
		$receipt_hash = self::receipt_hash( $idempotency_key );

		$existing = get_user_by( 'email', $data['email'] );
		if ( $existing instanceof WP_User ) {
			$receipt = get_user_meta( $existing->ID, self::RECEIPT_META, true );
			if ( self::receipt_matches( $receipt, $receipt_hash, $data['email'] ) && smc_application( $existing->ID ) ) {
				return self::response( 'allow', 'idempotent_replay', array( 'user_id' => (int) $existing->ID ) );
			}
			return self::response( 'deny', 'email_collision' );
		}

		$phone_hash = SMC_Security::blind_index( $data['phone'], 'phone' );
		$id_hash    = SMC_Security::blind_index( $data['country'] . '|' . $data['identity_type'] . '|' . $data['identity_reference'], 'identity-number' );
		if ( is_wp_error( $phone_hash ) || is_wp_error( $id_hash ) ) {
			return self::response( 'unknown', 'identity_index_unavailable' );
		}

		$phone_owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_applications WHERE phone_hash=%s LIMIT 1", $phone_hash ) );
		$id_owner    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}smc_identity_records WHERE document_number_hash=%s LIMIT 1", $id_hash ) );
		if ( $phone_owner > 0 ) {
			return self::response( 'deny', 'phone_collision' );
		}
		if ( $id_owner > 0 ) {
			return self::response( 'deny', 'identity_collision' );
		}

		$username = self::unique_username( $data['email'], $data['name'] );
		if ( '' === $username ) {
			return self::response( 'unknown', 'username_unavailable' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $data['email'],
				'user_pass'    => $data['password'],
				'display_name' => $data['name'],
				'first_name'   => $data['name'],
				'role'         => 'subscriber',
			)
		);
		$data['password'] = '';
		unset( $payload['password'], $payload['password_confirm'] );
		if ( is_wp_error( $user_id ) ) {
			$reason = in_array( $user_id->get_error_code(), array( 'existing_user_email', 'existing_user_login' ), true ) ? 'account_collision' : 'wordpress_account_create_failed';
			return self::response( 'deny', $reason );
		}
		$user_id = absint( $user_id );

		$dob_enc     = SMC_Security::encrypt( $data['date_of_birth'], 'date-of-birth', array( 'user_id' => $user_id ) );
		$phone_enc   = SMC_Security::encrypt( $data['phone'], 'membership-phone', array( 'user_id' => $user_id ) );
		$address_enc = SMC_Security::encrypt( $data['address'], 'residential-address', array( 'user_id' => $user_id, 'country' => $data['country'] ) );
		$id_enc      = SMC_Security::encrypt(
			$data['identity_reference'],
			'identity-number',
			array( 'user_id' => $user_id, 'type' => $data['identity_type'], 'country' => $data['country'] )
		);
		if ( is_wp_error( $dob_enc ) || is_wp_error( $phone_enc ) || is_wp_error( $address_enc ) || is_wp_error( $id_enc ) ) {
			self::quarantine_account( $user_id, 'registration_encryption_failed' );
			return self::response( 'unknown', 'registration_encryption_failed' );
		}

		$application = smc_application( $user_id );
		if ( ! $application ) {
			SMC_Contracts::register_account( $user_id );
			$application = smc_application( $user_id );
		}
		if ( ! $application ) {
			self::quarantine_account( $user_id, 'membership_application_missing' );
			return self::response( 'unknown', 'membership_application_missing' );
		}

		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$app_ok = $wpdb->update(
			$wpdb->prefix . 'smc_applications',
			array(
				'legal_name'         => $data['name'],
				'date_of_birth_enc'  => $dob_enc,
				'gender'             => $data['sex'],
				'residence_country'  => $data['country'],
				'address_enc'        => $address_enc,
				'phone_e164_enc'     => $phone_enc,
				'phone_hash'         => $phone_hash,
				'guardian_required'  => $data['age'] < (int) smc_policy()['guardian_required_under'] ? 1 : 0,
				'policy_version'     => smc_policy()['version'],
				'row_version'        => max( 1, absint( $application['row_version'] ) + 1 ),
				'updated_at'         => $now,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		$identity_existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1", $user_id ) );
		if ( $identity_existing ) {
			$identity_ok = $wpdb->update(
				$wpdb->prefix . 'smc_identity_records',
				array(
					'document_type'        => $data['identity_type'],
					'document_number_enc'  => $id_enc,
					'document_number_hash' => $id_hash,
					'issuing_country'      => $data['country'],
					'name_match_status'    => 'unreviewed',
					'updated_at'           => $now,
				),
				array( 'user_id' => $user_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$identity_ok = $wpdb->insert(
				$wpdb->prefix . 'smc_identity_records',
				array(
					'user_id'              => $user_id,
					'document_type'        => $data['identity_type'],
					'document_number_enc'  => $id_enc,
					'document_number_hash' => $id_hash,
					'issuing_country'      => $data['country'],
					'name_match_status'    => 'unreviewed',
					'created_at'           => $now,
					'updated_at'           => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		$consent_ok = self::record_consent( $user_id, 'terms_of_use', $data['terms_version'], $now )
			&& self::record_consent( $user_id, 'privacy_notice', $data['privacy_version'], $now );

		$receipt = array(
			'version'          => 1,
			'receipt_hash'     => $receipt_hash,
			'email_hash'       => self::email_hash( $data['email'] ),
			'contract_version' => self::CONTRACT_VERSION,
			'created_at'       => $now,
		);
		$receipt_ok = update_user_meta( $user_id, self::RECEIPT_META, $receipt );
		$stored_receipt = get_user_meta( $user_id, self::RECEIPT_META, true );
		$receipt_ok = false !== $receipt_ok && self::receipt_matches( $stored_receipt, $receipt_hash, $data['email'] );

		$audit_ok = SMC_Security::audit(
			'authentication_account_registered',
			$user_id,
			array(
				'contract'          => self::CONTRACT_NAME,
				'contract_version'  => self::CONTRACT_VERSION,
				'guardian_required' => $data['age'] < (int) smc_policy()['guardian_required_under'],
			)
		);

		if ( false === $app_ok || false === $identity_ok || ! $consent_ok || ! $receipt_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			self::quarantine_account( $user_id, 'registration_initialization_failed' );
			return self::response( 'unknown', 'registration_initialization_failed' );
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );

		return self::response( 'allow', 'account_registered', array( 'user_id' => $user_id ) );
	}

	/**
	 * Record exact ownership of the account's canonical email.
	 *
	 * @return array<string,mixed>
	 */
	public static function mark_email_verified( $user_id, $email, $context = array() ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$email   = sanitize_email( (string) $email );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User || ! is_email( $email ) || ! hash_equals( strtolower( (string) $user->user_email ), strtolower( $email ) ) ) {
			return self::response( 'deny', 'email_subject_mismatch' );
		}
		if ( SMC_Completion::safe_mode() || smc_privacy_erasure_lock( $user_id ) ) {
			return self::response( 'unknown', 'provider_safe_mode' );
		}

		$target_hash = SMC_Security::blind_index( strtolower( $email ), 'contact-target' );
		if ( is_wp_error( $target_hash ) ) {
			return self::response( 'unknown', 'email_index_unavailable' );
		}
		if ( SMC_Contracts::contact_verified( $user_id, 'email' ) ) {
			return self::response( 'allow', 'already_verified', array( 'user_id' => $user_id ) );
		}

		$nonce             = wp_generate_password( 32, true, true );
		$code_lookup_hash  = SMC_Security::blind_index( $nonce, 'contact-code' );
		$code_hash         = wp_hash_password( $nonce );
		$nonce             = '';
		if ( is_wp_error( $code_lookup_hash ) || '' === (string) $code_hash ) {
			return self::response( 'unknown', 'email_receipt_unavailable' );
		}
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_contact_otps
			(user_id,channel,target_hash,code_lookup_hash,code_hash,attempts,expires_at,verified_at,created_at)
			VALUES (%d,'email',%s,%s,%s,0,%s,%s,%s)
			ON DUPLICATE KEY UPDATE target_hash=VALUES(target_hash),code_lookup_hash=VALUES(code_lookup_hash),code_hash=VALUES(code_hash),attempts=0,expires_at=VALUES(expires_at),verified_at=VALUES(verified_at)",
			$user_id,
			$target_hash,
			$code_lookup_hash,
			$code_hash,
			$now,
			$now,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) {
			return self::response( 'unknown', 'email_verification_store_failed' );
		}
		if ( ! SMC_Security::audit( 'authentication_email_verified', $user_id, array( 'contract_version' => self::CONTRACT_VERSION ) ) ) {
			return self::response( 'unknown', 'email_verification_audit_failed' );
		}
		return self::response( 'allow', 'email_verified', array( 'user_id' => $user_id ) );
	}

	/**
	 * Return File 00-owned completion truth and the next canonical owner route.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_completion_state( $user_id, $context = array() ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) {
			return self::response( 'deny', 'subject_not_found', array( 'missing_steps' => array(), 'next_route' => '' ) );
		}
		if ( smc_privacy_erasure_lock( $user_id ) ) {
			return self::response( 'deny', 'erasure_locked', array( 'missing_steps' => array(), 'next_route' => '' ) );
		}

		$app = smc_application( $user_id );
		if ( ! $app ) {
			return self::response(
				'allow',
				'application_required',
				array( 'missing_steps' => array( 'profile' ), 'next_route' => smc_page_url( 'application', '/membership-application/' ) )
			);
		}
		if ( in_array( (string) $app['status'], array( 'suspended', 'rejected', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' ), true ) ) {
			return self::response( 'deny', 'membership_restricted', array( 'missing_steps' => array(), 'next_route' => smc_page_url( 'status', '/membership-status/' ) ) );
		}

		$missing = array();
		if ( ! SMC_Contracts::contact_verified( $user_id, 'email' ) ) {
			$missing[] = 'email';
		}
		if ( ! SMC_Contracts::contact_verified( $user_id, 'mobile' ) ) {
			$missing[] = 'phone';
		}
		if ( ! empty( $app['guardian_required'] ) && ! SMC_Contracts::guardian_verified( $user_id ) ) {
			$missing[] = 'guardian';
		}
		$identity = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1", $user_id ) );
		if ( ! $identity || ! SMC_Contracts::identity_documents_current( $user_id ) ) {
			$missing[] = 'identity';
		}
		if ( ! SMC_Security::two_factor_ready( $user_id ) ) {
			$missing[] = 'two_factor';
		}
		foreach ( array( 'terms_of_use' => 'terms', 'privacy_notice' => 'privacy' ) as $purpose => $step ) {
			$consent = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1",
					$user_id,
					$purpose
				)
			);
			if ( ! $consent ) {
				$missing[] = $step;
			}
		}
		if ( in_array( (string) $app['status'], array( 'draft', 'more_information' ), true ) || '' === trim( (string) $app['legal_name'] ) ) {
			$missing[] = 'profile';
		}
		$missing = array_values( array_unique( $missing ) );

		$next_route = '';
		if ( in_array( 'email', $missing, true ) ) {
			$next_route = home_url( '/verify-email/' );
		} elseif ( array_intersect( array( 'phone', 'guardian', 'identity', 'terms', 'privacy', 'profile' ), $missing ) ) {
			$next_route = smc_page_url( 'application', '/membership-application/' );
		} elseif ( in_array( 'two_factor', $missing, true ) ) {
			$next_route = smc_page_url( 'security', '/membership-security/' );
		}

		return self::response(
			'allow',
			empty( $missing ) ? 'completion_satisfied' : 'completion_required',
			array( 'user_id' => $user_id, 'missing_steps' => $missing, 'next_route' => $next_route )
		);
	}

	/**
	 * Public contract manifest for diagnostics and consumer compatibility.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest() {
		return array(
			'contract'         => self::CONTRACT_NAME,
			'contract_version' => self::CONTRACT_VERSION,
			'provider'         => 'File 00 — Sabri Membership Core',
			'methods'          => array( 'register_account', 'mark_email_verified', 'get_completion_state' ),
			'fail_closed'      => true,
			'identity_owner'   => 'File 00',
		);
	}

	public static function register_exporter( $exporters ) {
		$exporters['sabri-membership-auth-contract'] = array(
			'exporter_friendly_name' => __( 'Sabri Account Registration Receipt', 'sabri-membership-core' ),
			'callback'               => array( __CLASS__, 'export_receipt' ),
		);
		return $exporters;
	}

	public static function export_receipt( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$receipt = get_user_meta( $user->ID, self::RECEIPT_META, true );
		if ( ! is_array( $receipt ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		return array(
			'data' => array(
				array(
					'group_id'    => 'smc-authentication-contract',
					'group_label' => __( 'Account Registration Contract', 'sabri-membership-core' ),
					'item_id'     => 'smc-authentication-contract-' . $user->ID,
					'data'        => array(
						array( 'name' => __( 'Contract version', 'sabri-membership-core' ), 'value' => (string) ( $receipt['contract_version'] ?? '' ) ),
						array( 'name' => __( 'Created at', 'sabri-membership-core' ), 'value' => (string) ( $receipt['created_at'] ?? '' ) ),
					),
				),
			),
			'done' => true,
		);
	}

	public static function register_eraser( $erasers ) {
		$erasers['sabri-membership-auth-contract'] = array(
			'eraser_friendly_name' => __( 'Sabri Account Registration Receipt', 'sabri-membership-core' ),
			'callback'             => array( __CLASS__, 'erase_receipt' ),
		);
		return $erasers;
	}

	public static function erase_receipt( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$removed = delete_user_meta( $user->ID, self::RECEIPT_META );
		return array(
			'items_removed'  => (bool) $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Validate and normalize File 02 input before any account is created.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_registration( array $payload ) {
		$name       = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		$email      = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$phone      = smc_normalize_phone( (string) ( $payload['phone'] ?? '' ) );
		$password   = (string) ( $payload['password'] ?? '' );
		$confirm    = (string) ( $payload['password_confirm'] ?? '' );
		$sex        = sanitize_key( (string) ( $payload['sex'] ?? '' ) );
		$dob        = sanitize_text_field( (string) ( $payload['date_of_birth'] ?? '' ) );
		$address    = sanitize_textarea_field( (string) ( $payload['address'] ?? '' ) );
		$country    = self::country_code( (string) ( $payload['country'] ?? '' ) );
		$identity   = trim( sanitize_text_field( (string) ( $payload['identity_reference'] ?? '' ) ) );
		$id_type    = sanitize_key( (string) ( $payload['identity_type'] ?? 'national_id' ) );
		$guardian   = trim( sanitize_text_field( (string) ( $payload['guardian_reference'] ?? '' ) ) );
		$terms      = sanitize_text_field( (string) ( $payload['terms_version'] ?? '' ) );
		$privacy    = sanitize_text_field( (string) ( $payload['privacy_version'] ?? '' ) );

		if ( strlen( $name ) < 2 || strlen( $name ) > 190 ) {
			return new WP_Error( 'registration_name_invalid', __( 'The complete name is invalid.', 'sabri-membership-core' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'registration_email_invalid', __( 'The account email is invalid.', 'sabri-membership-core' ) );
		}
		if ( is_wp_error( $phone ) ) {
			return new WP_Error( 'registration_phone_invalid', $phone->get_error_message() );
		}
		if ( strlen( $password ) < 12 || ! hash_equals( $password, $confirm ) ) {
			return new WP_Error( 'registration_password_invalid', __( 'Use matching passwords of at least twelve characters.', 'sabri-membership-core' ) );
		}
		if ( ! isset( smc_allowed_genders()[ $sex ] ) ) {
			return new WP_Error( 'registration_gender_invalid', __( 'The approved age-rule sex value is invalid.', 'sabri-membership-core' ) );
		}
		$age = smc_age_from_dob( $dob );
		if ( false === $age || '' === $country ) {
			return new WP_Error( 'registration_eligibility_invalid', __( 'The date of birth or country is invalid.', 'sabri-membership-core' ) );
		}
		$minimum = smc_effective_minimum_age( $sex, $country );
		if ( false === $minimum || $age < $minimum ) {
			return new WP_Error( 'registration_age_ineligible', __( 'The account does not meet the effective minimum-age rule.', 'sabri-membership-core' ) );
		}
		if ( $age < (int) smc_policy()['guardian_required_under'] && '' === $guardian ) {
			return new WP_Error( 'registration_guardian_required', __( 'A guardian reference is required for a minor account.', 'sabri-membership-core' ) );
		}
		if ( strlen( $address ) < 3 || strlen( $address ) > 500 || strlen( $identity ) < 5 || strlen( $identity ) > 64 ) {
			return new WP_Error( 'registration_identity_invalid', __( 'The address or identity reference is invalid.', 'sabri-membership-core' ) );
		}
		if ( ! in_array( $id_type, array( 'national_id', 'passport' ), true ) ) {
			return new WP_Error( 'registration_identity_type_invalid', __( 'The identity document type is invalid.', 'sabri-membership-core' ) );
		}
		if ( '' === $terms || '' === $privacy ) {
			return new WP_Error( 'registration_consent_missing', __( 'The current Terms and Privacy Notice must be accepted.', 'sabri-membership-core' ) );
		}

		return array(
			'name'               => $name,
			'email'              => strtolower( $email ),
			'phone'              => $phone,
			'password'           => $password,
			'sex'                => $sex,
			'date_of_birth'      => $dob,
			'age'                => (int) $age,
			'address'            => $address,
			'country'            => $country,
			'identity_reference' => $identity,
			'identity_type'      => $id_type,
			'guardian_reference' => $guardian,
			'terms_version'      => $terms,
			'privacy_version'    => $privacy,
		);
	}

	private static function record_consent( $user_id, $purpose, $version, $accepted_at ) {
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose=%s AND policy_version=%s AND withdrawn_at IS NULL LIMIT 1",
				absint( $user_id ),
				sanitize_key( $purpose ),
				(string) $version
			)
		);
		if ( $existing ) {
			return true;
		}
		$text = sprintf( 'Accepted through File 02 account registration contract %s.', self::CONTRACT_VERSION );
		return 1 === $wpdb->insert(
			$wpdb->prefix . 'smc_consents',
			array(
				'user_id'       => absint( $user_id ),
				'actor_type'   => 'self',
				'purpose'      => sanitize_key( $purpose ),
				'locale'       => function_exists( 'determine_locale' ) ? determine_locale() : 'en_US',
				'channel'      => 'file02_contract',
				'text_snapshot'=> $text,
				'text_hash'    => hash( 'sha256', $text ),
				'policy_version'=> sanitize_text_field( (string) $version ),
				'accepted_at'  => $accepted_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function unique_username( $email, $name ) {
		$base = sanitize_user( strtok( (string) $email, '@' ), true );
		if ( strlen( $base ) < 3 ) {
			$base = sanitize_user( str_replace( ' ', '.', strtolower( (string) $name ) ), true );
		}
		$base = substr( $base ?: 'member', 0, 40 );
		if ( ! username_exists( $base ) ) {
			return $base;
		}
		for ( $i = 0; $i < 50; $i++ ) {
			$candidate = substr( $base, 0, 32 ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
			$candidate = sanitize_user( $candidate, true );
			if ( '' !== $candidate && ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private static function country_code( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			return strtoupper( $value );
		}
		$key = strtolower( preg_replace( '/[^a-z]+/i', '', $value ) );
		$map = array(
			'pakistan' => 'PK', 'india' => 'IN', 'bangladesh' => 'BD', 'afghanistan' => 'AF',
			'unitedarabemirates' => 'AE', 'saudiarabia' => 'SA', 'qatar' => 'QA', 'oman' => 'OM',
			'unitedkingdom' => 'GB', 'greatbritain' => 'GB', 'unitedstates' => 'US', 'unitedstatesofamerica' => 'US',
			'canada' => 'CA', 'australia' => 'AU', 'newzealand' => 'NZ', 'germany' => 'DE', 'france' => 'FR',
			'italy' => 'IT', 'spain' => 'ES', 'turkey' => 'TR', 'malaysia' => 'MY', 'indonesia' => 'ID',
			'southafrica' => 'ZA', 'nigeria' => 'NG', 'kenya' => 'KE', 'egypt' => 'EG', 'iran' => 'IR', 'iraq' => 'IQ',
		);
		$code = $map[ $key ] ?? '';
		$code = strtoupper( (string) apply_filters( 'smc_authentication_country_code', $code, $value ) );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	private static function quarantine_account( $user_id, $reason ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}
		$wpdb->update(
			$wpdb->prefix . 'smc_applications',
			array( 'status' => 'invalid_application', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		SMC_Security::revoke_all_sessions( $user_id, sanitize_key( $reason ) );
		SMC_Security::audit( 'authentication_account_quarantined', $user_id, array( 'reason' => sanitize_key( $reason ) ) );
		clean_user_cache( $user_id );
	}

	private static function receipt_matches( $receipt, $receipt_hash, $email ) {
		return is_array( $receipt )
			&& ! empty( $receipt['receipt_hash'] )
			&& ! empty( $receipt['email_hash'] )
			&& hash_equals( (string) $receipt['receipt_hash'], (string) $receipt_hash )
			&& hash_equals( (string) $receipt['email_hash'], self::email_hash( $email ) );
	}

	private static function receipt_hash( $idempotency_key ) {
		return hash_hmac( 'sha256', (string) $idempotency_key, wp_salt( 'nonce' ) );
	}

	private static function email_hash( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'secure_auth' ) );
	}

	private static function response( $result, $reason_code, array $extra = array() ) {
		return array_merge(
			array(
				'contract'         => self::CONTRACT_NAME,
				'contract_version' => self::CONTRACT_VERSION,
				'result'           => sanitize_key( (string) $result ),
				'reason_code'      => sanitize_key( (string) $reason_code ),
			),
			$extra
		);
	}
}

<?php
/**
 * File 00 ↔ File 06 v1 — subject-authenticated, case-bound patient consent.
 * Membership consent and File 06 case-study consent are different authorities.
 *
 * Uses the existing smc_consents ledger. It never turns a profile, login,
 * clinic claim or research-author checkbox into patient consent.
 */
defined( 'ABSPATH' ) || exit;

final class SMC_File06_Case_Consent {
	const CHANNEL = 'file06-case';
	const PURPOSE = 'patient-story-research';
	const RECEIPT_PREFIX = 'smc-f06-r';
	const VERIFICATION_PREFIX = 'smc-file06-v1:';

	public static function init() {
		add_filter( 'he_v12_consent_receipt_verification_v1', array( __CLASS__, 'verify_for_file06' ), 10, 2 );
		add_filter( 'he_v12_consent_receipt_current_v1', array( __CLASS__, 'current_for_file06' ), 10, 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function problem( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	private static function reference_hash( $user_id, $uuid ) {
		return hash_hmac( 'sha256', 'file06-case-v1|' . absint( $user_id ) . '|' . $uuid, wp_salt( 'auth' ) );
	}

	private static function assurance_ok( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! class_exists( 'SMC_Contracts' )
			|| ! method_exists( 'SMC_Contracts', 'assertions' )
			|| ! class_exists( 'SMC_Advanced_Trust_2026' )
			|| ! method_exists( 'SMC_Advanced_Trust_2026', 'assurance_profile' ) ) { return false; }
		try {
			$claims = SMC_Contracts::assertions( $user_id );
			$profile = SMC_Advanced_Trust_2026::assurance_profile( $user_id );
			if ( ! is_array( $claims ) || ! is_array( $profile )
				|| empty( $claims['eligible'] ) || ! empty( $claims['suspended'] )
				|| ! empty( $claims['institutional_account'] )
				|| (int) ( $profile['identity_assurance_level'] ?? 0 ) < 3
				|| (int) ( $profile['authentication_assurance_level'] ?? 0 ) < 1
				|| ( class_exists( 'SMC_Authorization' ) && SMC_Authorization::is_hard_blocked( $user_id ) ) ) { return false; }
			// The first version does not infer a minor's separate case-study
			// consent from platform membership/guardian registration.
			$app = function_exists( 'smc_application' ) ? smc_application( $user_id ) : null;
			if ( ! is_array( $app ) || empty( $app['date_of_birth_enc'] )
				|| ! class_exists( 'SMC_Security' ) || ! function_exists( 'smc_age_from_dob' ) ) { return false; }
			$dob = SMC_Security::decrypt( (string) $app['date_of_birth_enc'], 'date-of-birth',
				array( 'user_id' => $user_id ) );
			if ( is_wp_error( $dob ) || ! is_string( $dob ) ) { return false; }
			$age = smc_age_from_dob( $dob );
			if ( false === $age || $age < 18 ) { return false; }
			return true;
		} catch ( Throwable $error ) { return false; }
	}

	private static function relation( $user_id, $research_uuid ) {
		try {
			$proof = apply_filters( 'he_v12_file06_patient_relation_v1', null, absint( $user_id ), $research_uuid );
			if ( ! is_array( $proof ) || true !== ( $proof['verified'] ?? false )
				|| 'file-06' !== ( $proof['owner_file'] ?? '' )
				|| (int) ( $proof['subject_user_id'] ?? 0 ) !== absint( $user_id )
				|| ! hash_equals( $research_uuid, (string) ( $proof['research_public_id'] ?? '' ) )
				|| (int) ( $proof['relation_version'] ?? 0 ) < 1
				|| (int) ( $proof['attested_by'] ?? 0 ) === (int) ( $proof['approved_by'] ?? 0 )
				|| (int) ( $proof['attested_by'] ?? 0 ) < 1
				|| (int) ( $proof['approved_by'] ?? 0 ) < 1 ) { return false; }
			return true;
		} catch ( Throwable $error ) { return false; }
	}

	private static function policy( $locale ) {
		try {
			$value = apply_filters( 'he_v12_file06_consent_policy_v1', null, $locale );
			if ( ! is_array( $value ) || true !== ( $value['approved'] ?? false )
				|| 'file-06' !== ( $value['owner_file'] ?? '' ) || self::PURPOSE !== ( $value['purpose'] ?? '' )
				|| ! hash_equals( $locale, (string) ( $value['locale'] ?? '' ) )
				|| ! preg_match( '/^[A-Za-z0-9._-]{6,32}$/', (string) ( $value['version'] ?? '' ) )
				|| ! hash_equals( hash( 'sha256', (string) ( $value['text'] ?? '' ) ),
					(string) ( $value['text_hash'] ?? '' ) ) ) { return null; }
			return $value;
		} catch ( Throwable $error ) { return null; }
	}

	private static function receipt_row( $reference ) {
		if ( ! preg_match( '/^' . self::RECEIPT_PREFIX . '([1-9][0-9]{0,18})$/', (string) $reference, $matches ) ) { return null; }
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id,user_id,actor_type,actor_reference_hash,purpose,locale,channel,text_hash,policy_version,accepted_at,withdrawn_at
			FROM {$wpdb->prefix}smc_consents WHERE id=%d LIMIT 1", absint( $matches[1] )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row )
			|| self::CHANNEL !== $row['channel'] || self::PURPOSE !== $row['purpose']
			|| 'self' !== $row['actor_type'] || ! empty( $row['withdrawn_at'] )
			|| empty( $row['accepted_at'] ) ) { return null; }
		return $row;
	}

	private static function evaluate( $reference, $research_uuid, $version, $locale, $purpose ) {
		$research_uuid = self::uuid( $research_uuid );
		$row = self::receipt_row( $reference );
		if ( ! $row || ! $research_uuid || self::PURPOSE !== $purpose
			|| ! hash_equals( (string) $row['policy_version'], (string) $version )
			|| ! hash_equals( (string) $row['locale'], (string) $locale )
			|| ! hash_equals( self::reference_hash( $row['user_id'], $research_uuid ),
				(string) $row['actor_reference_hash'] )
			|| ! self::assurance_ok( $row['user_id'] )
			|| ! self::relation( $row['user_id'], $research_uuid ) ) { return null; }
		$policy = self::policy( (string) $locale );
		if ( ! $policy || ! hash_equals( (string) $row['policy_version'], (string) $policy['version'] )
			|| ! hash_equals( (string) $row['text_hash'], (string) $policy['text_hash'] ) ) { return null; }
		return $row;
	}

	public static function verify_for_file06( $value, $data ) {
		if ( ! is_array( $data ) ) { return null; }
		$ref = (string) ( $data['evidence_ref'] ?? '' );
		$uuid = self::uuid( $data['research_public_id'] ?? '' );
		$row = self::evaluate( $ref, $uuid, (string) ( $data['policy_version'] ?? '' ),
			(string) ( $data['locale'] ?? '' ), (string) ( $data['purpose'] ?? '' ) );
		if ( ! $row ) { return null; }
		return array(
			'verified'=>true, 'authority'=>'file-00', 'evidence_ref'=>$ref,
			'policy_version'=>(string) $row['policy_version'], 'locale'=>(string) $row['locale'],
			'purpose'=>self::PURPOSE, 'research_public_id'=>$uuid,
			'verification_id'=>self::VERIFICATION_PREFIX . (int) $row['id'],
		);
	}

	public static function current_for_file06( $value, $verification_id, $evidence_ref, $research_public_id, $purpose ) {
		$number = 0 === strpos( (string) $verification_id, self::VERIFICATION_PREFIX )
			? substr( (string) $verification_id, strlen( self::VERIFICATION_PREFIX ) ) : '';
		if ( ! ctype_digit( $number )
			|| ! hash_equals( self::RECEIPT_PREFIX . $number, (string) $evidence_ref ) ) { return false; }
		$row = self::receipt_row( $evidence_ref );
		return $row && (int) $row['id'] === (int) $number
			&& (bool) self::evaluate( $evidence_ref, $research_public_id,
				(string) $row['policy_version'], (string) $row['locale'], $purpose );
	}

	public static function routes() {
		register_rest_route( 'smc/v1', '/file06/case-consent', array(
			'methods'=>'POST', 'callback'=>array(__CLASS__,'accept'),
			'permission_callback'=>static function(){ return is_user_logged_in(); },
		) );
		register_rest_route( 'smc/v1', '/file06/case-consent/withdraw', array(
			'methods'=>'POST', 'callback'=>array(__CLASS__,'withdraw'),
			'permission_callback'=>static function(){ return is_user_logged_in(); },
		) );
	}

	/**
	 * Serialize the entire current-subject/case consent lifecycle across workers.
	 * A database lock is mandatory: without it two simultaneous accept requests
	 * can both see no active receipt, and a withdrawal of one ID leaves another.
	 * Fail closed when the database cannot provide the lock.
	 */
	private static function case_lock( $user_id, $research_uuid ) {
		global $wpdb;
		$key = 'smc-f06:' . substr( hash( 'sha256',
			(string) ( $wpdb->dbname ?? '' ) . '|' . (string) $wpdb->prefix . '|' .
			absint( $user_id ) . '|' . $research_uuid ), 0, 52 );
		$wpdb->last_error = '';
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 3 ) );
		if ( '1' !== (string) $held || '' !== (string) $wpdb->last_error ) {
			return self::problem( 'smc_file06_consent_busy',
				'The case consent record is busy or cannot be safely locked. Retry later.', 503 );
		}
		return $key;
	}

	private static function release_case_lock( $key ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	private static function authorized_request( $request ) {
		return wp_verify_nonce( (string) $request->get_header('X-WP-Nonce'), 'wp_rest' )
			&& is_user_logged_in() && self::assurance_ok( get_current_user_id() );
	}

	public static function accept( $request ) {
		if ( ! self::authorized_request( $request ) ) {
			return self::problem( 'smc_file06_consent_authority', 'Verified adult patient identity and a current session are required.', 403 );
		}
		$data = (array) $request->get_json_params();
		$uuid = self::uuid( $data['research_public_id'] ?? '' );
		$version = sanitize_text_field( (string) ( $data['policy_version'] ?? '' ) );
		$locale = sanitize_text_field( (string) ( $data['locale'] ?? '' ) );
		$uid = get_current_user_id();
		$policy = self::policy( $locale );
		if ( ! $uuid || true !== ( $data['accepted'] ?? false ) || ! $policy
			|| ! hash_equals( $version, (string) $policy['version'] )
			|| ! hash_equals( (string) ( $data['text_hash'] ?? '' ), (string) $policy['text_hash'] )
			|| ! self::relation( $uid, $uuid ) ) {
			return self::problem( 'smc_file06_consent_unverified', 'Current File 06 subject match, approved policy and explicit acceptance are required.', 422 );
		}
		$lock_key = self::case_lock( $uid, $uuid );
		if ( is_wp_error( $lock_key ) ) { return $lock_key; }
		try {
			global $wpdb;
			$hash = self::reference_hash( $uid, $uuid );
			$wpdb->last_error = '';
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id,policy_version,locale,text_hash FROM {$wpdb->prefix}smc_consents
				WHERE user_id=%d AND purpose=%s AND channel=%s AND actor_type='self'
				AND actor_reference_hash=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1",
				$uid, self::PURPOSE, self::CHANNEL, $hash
			), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::problem( 'smc_file06_consent_lookup_failed', 'The existing consent ledger could not be verified.', 503 );
			}
			if ( $existing ) {
				if ( ! hash_equals( (string) $existing['policy_version'], $version )
					|| ! hash_equals( (string) $existing['locale'], $locale )
					|| ! hash_equals( (string) $existing['text_hash'], (string) $policy['text_hash'] ) ) {
					return self::problem( 'smc_file06_consent_revision_required', 'Existing case consent requires revocation before a different policy is accepted.', 409 );
				}
				return rest_ensure_response( array( 'evidence_ref'=>self::RECEIPT_PREFIX . (int)$existing['id'],
					'verification_id'=>self::VERIFICATION_PREFIX . (int)$existing['id'],
					'research_public_id'=>$uuid,'policy_version'=>$version,'locale'=>$locale,
					'purpose'=>self::PURPOSE, 'state'=>'accepted' ) );
			}
			// Pending rows are untrusted. Only a durable audit turns pending into self.
			$written = $wpdb->insert( $wpdb->prefix . 'smc_consents', array(
				'user_id'=>$uid,'actor_type'=>'pending','actor_reference_hash'=>$hash,
				'purpose'=>self::PURPOSE,'locale'=>$locale,'channel'=>self::CHANNEL,
				'text_snapshot'=>(string)$policy['text'],'text_hash'=>(string)$policy['text_hash'],
				'policy_version'=>$version,'accepted_at'=>current_time('mysql',true),'withdrawn_at'=>null,
			) );
			if ( 1 !== (int) $written || (int) $wpdb->insert_id < 1 ) {
				return self::problem( 'smc_file06_consent_store_failed', 'The patient consent receipt was not saved.', 503 );
			}
			$id = (int) $wpdb->insert_id;
			$audited = false;
			try { $audited = SMC_Security::audit( 'file06_case_consent_accepted', $uid,
				array( 'receipt_id'=>$id, 'case_hash'=>$hash, 'policy_version'=>$version ) ); }
			catch ( Throwable $error ) { $audited = false; }
			if ( ! $audited ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_consents SET withdrawn_at=%s
					WHERE id=%d AND actor_type='pending'", current_time('mysql',true),$id ) );
				return self::problem( 'smc_file06_consent_audit_failed', 'Consent could not be independently audited.', 503 );
			}
			$activated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_consents SET actor_type='self'
				WHERE id=%d AND user_id=%d AND actor_type='pending' AND withdrawn_at IS NULL", $id,$uid
			) );
			if ( 1 !== (int)$activated ) {
				return self::problem( 'smc_file06_consent_activation_failed', 'Consent acceptance was not durably activated.', 503 );
			}
			return rest_ensure_response( array( 'evidence_ref'=>self::RECEIPT_PREFIX . $id,
				'verification_id'=>self::VERIFICATION_PREFIX . $id,
				'research_public_id'=>$uuid,'policy_version'=>$version,'locale'=>$locale,
				'purpose'=>self::PURPOSE,'state'=>'accepted' ) );
		} finally {
			self::release_case_lock( $lock_key );
		}
	}

	public static function withdraw( $request ) {
		if ( ! wp_verify_nonce( (string) $request->get_header('X-WP-Nonce'), 'wp_rest' )
			|| ! is_user_logged_in() ) {
			return self::problem( 'smc_file06_consent_withdraw_auth', 'An authenticated subject session is required for withdrawal.', 403 );
		}
		$data = (array) $request->get_json_params();
		$uuid = self::uuid( $data['research_public_id'] ?? '' );
		$ref = (string) ( $data['evidence_ref'] ?? '' );
		$row = self::receipt_row( $ref );
		$user = get_current_user_id();
		if ( ! $row || ! $uuid || (int)$row['user_id'] !== $user
			|| ! hash_equals( self::reference_hash($user,$uuid), (string)$row['actor_reference_hash'] ) ) {
			return self::problem( 'smc_file06_consent_withdraw_invalid', 'The subject-owned case receipt is not available.', 404 );
		}
		$lock_key = self::case_lock( $user, $uuid );
		if ( is_wp_error( $lock_key ) ) { return $lock_key; }
		try {
			// Revalidate inside the lock: a competing request may have revoked
			// the originally selected receipt before this worker obtained it.
			$locked_row = self::receipt_row( $ref );
			if ( ! $locked_row || (int)$locked_row['user_id'] !== $user ||
				! hash_equals( self::reference_hash( $user, $uuid ),
					(string)$locked_row['actor_reference_hash'] ) ) {
				return self::problem( 'smc_file06_consent_withdraw_conflict',
					'The current case consent is no longer available for withdrawal.', 409 );
			}
			$row = $locked_row;
			global $wpdb;
			$withdrawn = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_consents SET withdrawn_at=%s
				WHERE user_id=%d AND purpose=%s AND channel=%s AND actor_reference_hash=%s
				AND actor_type='self' AND withdrawn_at IS NULL",
				current_time('mysql',true),$user,self::PURPOSE,self::CHANNEL,
				(string)$row['actor_reference_hash']
			) );
			if ( false === $withdrawn || (int)$withdrawn < 1 || '' !== (string)$wpdb->last_error ) {
				return self::problem( 'smc_file06_consent_withdraw_failed', 'The consent withdrawal was not committed.', 503 );
			}
			// A successful revocation must cover every active receipt of this case.
			// Verify the committed state before reporting success to File 06.
			$wpdb->last_error = '';
			$remaining = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}smc_consents
				WHERE user_id=%d AND purpose=%s AND channel=%s AND actor_reference_hash=%s
				AND actor_type='self' AND withdrawn_at IS NULL",
				$user,self::PURPOSE,self::CHANNEL,(string)$row['actor_reference_hash']
			) );
			if ( null === $remaining || '' !== (string)$wpdb->last_error || (int)$remaining !== 0 ) {
				return self::problem( 'smc_file06_consent_reconciliation_required',
					'Consent revocation could not be verified for all case receipts.', 503 );
			}
			$audited = false;
			try { $audited = SMC_Security::audit( 'file06_case_consent_withdrawn', $user, array(
				'receipt_id'=>(int)$row['id'], 'case_hash'=>$row['actor_reference_hash'],
				'revoked_receipts'=>(int)$withdrawn ) ); }
			catch ( Throwable $error ) { $audited = false; }
			if ( ! $audited ) {
				update_option( 'smc_file06_consent_reconciliation_required', 1, false );
			}
			// The File 06 public read guard already rejects this row immediately.
			do_action( 'smc_file06_patient_consent_withdrawn_v1', $uuid, $user, $ref );
			return rest_ensure_response( array( 'state'=>'withdrawn', 'research_public_id'=>$uuid,
				'file06_revalidation_required'=>true, 'audit_reconciliation_required'=>!$audited ) );
		} finally {
			self::release_case_lock( $lock_key );
		}
	}
}

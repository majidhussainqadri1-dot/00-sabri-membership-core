<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Installer {
	private static $table_suffixes = array(
		'smc_applications',
		'smc_identity_records',
		'smc_identity_documents',
		'smc_guardian_consents',
		'smc_verification_requests',
		'smc_approval_votes',
		'smc_verification_events',
		'smc_consents',
		'smc_contact_otps',
		'smc_auth_sessions',
		'smc_recovery_codes',
		'smc_rate_limits',
		'smc_file_jobs',
		'smc_retention_holds',
		'smc_audit_log',
		'smc_migrations',
	);

	public static function activate() {
		$lock = self::acquire_lock( 20 );
		try {
			self::create_tables();
			self::create_roles();
			$pages_changed = self::create_pages();
			self::start_upgrade();
			if ( $pages_changed ) {
				flush_rewrite_rules( false );
			}
			set_transient( 'smc_activation_notice', '1', 180 );
		} catch ( Throwable $error ) {
			self::record_failure( 'activation', $error );
			throw $error;
		} finally {
			self::release_lock( $lock );
		}
	}

	public static function maybe_upgrade() {
		if ( SMC_DB_VERSION === get_option( 'smc_db_version', '' ) ) {
			return;
		}
		$lock = self::acquire_lock( 1 );
		try {
			self::create_tables();
			self::create_roles();
			self::create_pages();
			self::run_legacy_batch();
		} catch ( Throwable $error ) {
			self::record_failure( 'upgrade', $error );
		} finally {
			self::release_lock( $lock );
		}
	}

	private static function lock_name() {
		return 'smc_schema_' . substr( hash( 'sha256', DB_NAME . '|' . $GLOBALS['wpdb']->prefix ), 0, 32 );
	}

	private static function acquire_lock( $timeout ) {
		global $wpdb;
		$token = wp_generate_uuid4();
		$now   = time();
		$state = get_option( 'smc_schema_owner_lock', array() );
		if ( is_array( $state ) && ! empty( $state['expires'] ) && (int) $state['expires'] > $now ) {
			throw new RuntimeException( 'Sabri Membership migration is already owned by another process.' );
		}
		$owner = array( 'token' => $token, 'expires' => $now + 120 );
		if ( ! update_option( 'smc_schema_owner_lock', $owner, false ) ) {
			$stored = get_option( 'smc_schema_owner_lock', array() );
			if ( ! is_array( $stored ) || ! hash_equals( (string) ( $stored['token'] ?? '' ), $token ) ) {
				throw new RuntimeException( 'Sabri Membership could not establish the schema owner token.' );
			}
		}
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', self::lock_name(), max( 0, (int) $timeout ) ) );
		if ( 1 !== $got ) {
			delete_option( 'smc_schema_owner_lock' );
			throw new RuntimeException( 'Sabri Membership could not acquire the database advisory lock.' );
		}
		return $owner;
	}

	private static function release_lock( $owner ) {
		global $wpdb;
		$stored = get_option( 'smc_schema_owner_lock', array() );
		if ( is_array( $stored ) && hash_equals( (string) ( $stored['token'] ?? '' ), (string) ( $owner['token'] ?? '' ) ) ) {
			if ( ! delete_option( 'smc_schema_owner_lock' ) ) {
				SMC_Security::audit( 'schema_owner_release_failed', 0 );
			}
		}
		$released = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		if ( 1 !== $released ) {
			SMC_Security::audit( 'schema_advisory_release_failed', 0 );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix;
		$c = $wpdb->get_charset_collate();
		$sql = array();

		$sql[] = "CREATE TABLE {$p}smc_applications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			legal_name varchar(190) NOT NULL DEFAULT '',
			date_of_birth_enc longtext NULL,
			gender varchar(12) NOT NULL DEFAULT '',
			phone_e164_enc longtext NULL,
			phone_hash char(64) NULL,
			membership_type varchar(32) NOT NULL DEFAULT 'member',
			status varchar(32) NOT NULL DEFAULT 'draft',
			guardian_required tinyint(1) NOT NULL DEFAULT 0,
			profile_visibility varchar(20) NOT NULL DEFAULT 'private',
			policy_version varchar(32) NOT NULL DEFAULT '',
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			submitted_at datetime NULL,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY phone_hash (phone_hash),
			KEY status_type (status,membership_type)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_identity_records (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			document_type varchar(40) NOT NULL,
			document_number_enc longtext NOT NULL,
			document_number_hash char(64) NOT NULL,
			issuing_country char(2) NOT NULL DEFAULT '',
			name_match_status varchar(20) NOT NULL DEFAULT 'unreviewed',
			name_match_note text NULL,
			verified_at datetime NULL,
			verified_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY document_number_hash (document_number_hash)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_identity_documents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			document_key varchar(80) NOT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			label varchar(190) NOT NULL,
			stored_name varchar(190) NOT NULL,
			original_name varchar(190) NOT NULL DEFAULT '',
			mime_type varchar(80) NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			plain_sha256 char(64) NOT NULL,
			scan_status varchar(20) NOT NULL DEFAULT 'passed',
			status varchar(24) NOT NULL DEFAULT 'submitted',
			issue_date date NULL,
			expiry_date date NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewed_at datetime NULL,
			reviewer_note text NULL,
			lease_id char(36) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_document (user_id,document_key),
			UNIQUE KEY stored_name (stored_name),
			KEY status_expiry (status,expiry_date)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_guardian_consents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			guardian_name_enc longtext NOT NULL,
			guardian_email_enc longtext NOT NULL,
			guardian_email_hash char(64) NOT NULL,
			guardian_phone_enc longtext NOT NULL,
			guardian_phone_hash char(64) NOT NULL,
			relationship varchar(40) NOT NULL,
			legal_authority_confirmed tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			consent_text longtext NOT NULL,
			consent_hash char(64) NOT NULL,
			policy_version varchar(32) NOT NULL,
			otp_hash varchar(255) NULL,
			otp_lookup_hash char(64) NULL,
			invitation_token_hash char(64) NULL,
			otp_attempts smallint unsigned NOT NULL DEFAULT 0,
			otp_expires_at datetime NULL,
			requested_at datetime NOT NULL,
			verified_at datetime NULL,
			withdrawn_at datetime NULL,
			ip_hash char(64) NULL,
			device_hash char(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY guardian_email_hash (guardian_email_hash),
			KEY status (status)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_verification_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'submitted',
			assigned_reviewer bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewer_note longtext NULL,
			applicant_version bigint(20) unsigned NOT NULL DEFAULT 1,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			submitted_at datetime NOT NULL,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY queue (status,assigned_reviewer)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_approval_votes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			reviewer_id bigint(20) unsigned NOT NULL,
			decision varchar(20) NOT NULL,
			reason text NOT NULL,
			evidence_snapshot longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_reviewer (request_id,reviewer_id),
			KEY decision (request_id,decision)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_verification_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_status varchar(32) NOT NULL,
			new_status varchar(32) NOT NULL,
			note longtext NULL,
			previous_hash char(64) NOT NULL,
			event_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY user_id (user_id)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_consents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			actor_type varchar(20) NOT NULL DEFAULT 'self',
			actor_reference_hash char(64) NULL,
			purpose varchar(80) NOT NULL,
			locale varchar(20) NOT NULL DEFAULT 'en_US',
			channel varchar(24) NOT NULL DEFAULT 'web',
			text_snapshot longtext NOT NULL,
			text_hash char(64) NOT NULL,
			policy_version varchar(32) NOT NULL,
			accepted_at datetime NOT NULL,
			withdrawn_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_purpose (user_id,purpose)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_contact_otps (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			channel varchar(12) NOT NULL,
			target_hash char(64) NOT NULL,
			code_lookup_hash char(64) NOT NULL,
			code_hash varchar(255) NOT NULL,
			attempts smallint unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			verified_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_channel (user_id,channel),
			KEY expires_at (expires_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_auth_sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			expires_at datetime NOT NULL,
			two_factor_at datetime NULL,
			last_totp_slice bigint(20) NULL,
			ip_hash char(64) NULL,
			device_hash char(64) NULL,
			revoked_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_active (user_id,revoked_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_recovery_codes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			code_lookup_hash char(64) NOT NULL,
			code_hash varchar(255) NOT NULL,
			created_at datetime NOT NULL,
			consumed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_lookup_hash (code_lookup_hash),
			KEY user_unused (user_id,consumed_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_rate_limits (
			bucket_hash char(64) NOT NULL,
			attempt_count bigint(20) unsigned NOT NULL DEFAULT 0,
			reset_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (bucket_hash),
			KEY reset_at (reset_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_file_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stored_name varchar(190) NOT NULL,
			path_hash char(64) NOT NULL,
			job_type varchar(32) NOT NULL,
			lease_id char(36) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_job (path_hash,job_type),
			KEY queue (status,next_attempt_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_retention_holds (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			hold_type varchar(40) NOT NULL,
			reason text NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			released_at datetime NULL,
			PRIMARY KEY  (id),
			KEY active_user (user_id,released_at,expires_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject_hash char(64) NULL,
			action varchar(80) NOT NULL,
			details longtext NULL,
			previous_hash char(64) NOT NULL,
			row_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action_date (action,created_at),
			KEY subject_hash (subject_hash)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_migrations (
			migration_key varchar(80) NOT NULL,
			status varchar(20) NOT NULL,
			cursor_value bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (migration_key)
		) {$c};";

		foreach ( $sql as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Schema migration failed: ' . $wpdb->last_error );
			}
		}
		foreach ( self::$table_suffixes as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				throw new RuntimeException( 'Required membership table is missing: ' . $suffix );
			}
		}
	}

	private static function create_roles() {
		$base_caps = array( 'read' => true );
		foreach ( array_keys( smc_account_types() ) as $type ) {
			$pending = smc_role_for_type( $type, false );
			$active  = smc_role_for_type( $type, true );
			self::reconcile_role( $pending, sprintf( __( '%s — Pending', 'sabri-membership-core' ), smc_account_types()[ $type ] ), $base_caps );
			$caps = $base_caps;
			$caps['smc_message_members']  = true;
			$caps['smc_book_appointments'] = true;
			if ( 'doctor' === $type ) {
				$caps['upload_files']                 = true;
				$caps['create_sabri_medical_content'] = true;
			}
			self::reconcile_role( $active, smc_account_types()[ $type ], $caps );
		}
		self::reconcile_role(
			'sabri_membership_reviewer',
			__( 'Membership Reviewer', 'sabri-membership-core' ),
			array( 'read' => true, 'smc_review_verification' => true, 'smc_view_private_documents' => true )
		);
		self::reconcile_role(
			'sabri_membership_senior_reviewer',
			__( 'Senior Membership Reviewer', 'sabri-membership-core' ),
			array( 'read' => true, 'smc_review_verification' => true, 'smc_view_private_documents' => true, 'smc_finalize_verification' => true )
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'smc_review_verification', 'smc_view_private_documents', 'smc_finalize_verification', 'smc_manage_membership', 'smc_manage_retention_holds' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	private static function reconcile_role( $slug, $label, $caps ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			$role = add_role( $slug, $label, $caps );
		}
		if ( ! $role ) {
			throw new RuntimeException( 'Could not create membership role: ' . $slug );
		}
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! isset( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( $caps as $cap => $grant ) {
			$grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
		}
	}

	private static function create_pages() {
		$definitions = array(
			'application' => array( 'Membership Application', 'membership-application', '[smc_membership_application]' ),
			'status'      => array( 'Membership Status', 'membership-status', '[smc_membership_status]' ),
			'security'    => array( 'Membership Security', 'membership-security', '[smc_membership_security]' ),
			'guardian'    => array( 'Guardian Consent', 'guardian-consent', '[smc_guardian_consent]' ),
		);
		$map = (array) get_option( 'smc_page_map', array() );
		$changed = false;
		foreach ( $definitions as $key => $definition ) {
			$id = ! empty( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
			if ( $id && 'page' === get_post_type( $id ) && '1' === get_post_meta( $id, '_smc_managed_page', true ) ) {
				$result = wp_update_post(
					array(
						'ID'           => $id,
						'post_title'   => $definition[0],
						'post_name'    => $definition[1],
						'post_content' => $definition[2],
						'post_status'  => 'publish',
					),
					true
				);
			} else {
				$result = wp_insert_post(
					array(
						'post_title'   => $definition[0],
						'post_name'    => $definition[1],
						'post_content' => $definition[2],
						'post_status'  => 'publish',
						'post_type'    => 'page',
					),
					true
				);
				$changed = true;
			}
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( 'Could not create the managed membership page: ' . $definition[0] );
			}
			$id = absint( $result );
			update_post_meta( $id, '_smc_managed_page', '1' );
			$map[ $key ] = $id;
		}
		if ( ! update_option( 'smc_page_map', $map, false ) && get_option( 'smc_page_map' ) !== $map ) {
			throw new RuntimeException( 'Could not persist the membership page map.' );
		}
		return $changed;
	}

	private static function start_upgrade() {
		if ( ! get_option( 'smc_db_version', '' ) ) {
			update_option( 'smc_db_version', SMC_DB_VERSION, false );
			update_option( 'smc_release_version', SMC_VERSION, false );
			return;
		}
		self::run_legacy_batch();
	}

	private static function run_legacy_batch() {
		global $wpdb;
		$key = 'legacy-users-to-1.2.0';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_migrations WHERE migration_key=%s", $key ), ARRAY_A );
		$cursor = $row ? absint( $row['cursor_value'] ) : 0;
		$legacy_profiles = $wpdb->prefix . 'smc_member_profiles';
		$legacy_exists = $legacy_profiles === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_profiles ) ) );
		if ( $legacy_exists ) {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT u.ID FROM {$wpdb->users} u
					LEFT JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key IN ('_sa_account_type','_smc_requested_role')
					LEFT JOIN {$legacy_profiles} p ON p.user_id=u.ID
					WHERE u.ID>%d AND (m.umeta_id IS NOT NULL OR p.user_id IS NOT NULL)
					ORDER BY u.ID ASC LIMIT 50",
					$cursor
				)
			);
		} else {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT u.ID FROM {$wpdb->users} u
					INNER JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key IN ('_sa_account_type','_smc_requested_role')
					WHERE u.ID>%d ORDER BY u.ID ASC LIMIT 50",
					$cursor
				)
			);
		}
		$user_ids = array_map( 'absint', $user_ids );
		foreach ( $user_ids as $user_id ) {
			self::migrate_user( $user_id );
			$cursor = $user_id;
		}
		$status = count( $user_ids ) < 50 ? 'complete' : 'running';
		$now    = current_time( 'mysql', true );
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_migrations (migration_key,status,cursor_value,last_error,updated_at)
				VALUES (%s,%s,%d,NULL,%s)
				ON DUPLICATE KEY UPDATE status=VALUES(status),cursor_value=VALUES(cursor_value),last_error=NULL,updated_at=VALUES(updated_at)",
				$key,
				$status,
				$cursor,
				$now
			)
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'Could not checkpoint the membership migration.' );
		}
		if ( 'complete' === $status ) {
			update_option( 'smc_db_version', SMC_DB_VERSION, false );
			update_option( 'smc_release_version', SMC_VERSION, false );
		} elseif ( ! wp_next_scheduled( 'smc_continue_migration' ) ) {
			wp_schedule_single_event( time() + 30, 'smc_continue_migration' );
		}
	}

	public static function continue_migration() {
		self::maybe_upgrade();
	}

	private static function migrate_user( $user_id ) {
		global $wpdb;
		$type = sanitize_key( get_user_meta( $user_id, '_sa_account_type', true ) ?: get_user_meta( $user_id, '_smc_requested_role', true ) );
		$type = str_replace( array( 'sabri_', '_pending', '_verified' ), '', $type );
		$type = isset( smc_account_types()[ $type ] ) ? $type : 'member';
		if ( ! smc_application( $user_id ) ) {
			SMC_Contracts::register_account( $user_id );
		}
		$legacy_profile_table = $wpdb->prefix . 'smc_member_profiles';
		$legacy_profile_exists = $legacy_profile_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_profile_table ) ) );
		$legacy_profile = $legacy_profile_exists ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$legacy_profile_table} WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A ) : null;
		$app_data = array( 'membership_type' => $type, 'status' => 'draft', 'updated_at' => current_time( 'mysql', true ) );
		if ( $legacy_profile ) {
			$app_data['legal_name'] = sanitize_text_field( $legacy_profile['legal_name'] );
			$app_data['gender'] = in_array( $legacy_profile['gender'], array( 'male', 'female' ), true ) ? $legacy_profile['gender'] : '';
			if ( ! empty( $legacy_profile['date_of_birth_enc'] ) ) {
				$dob = SMC_Security::decrypt_legacy_value( $legacy_profile['date_of_birth_enc'], 'identity' );
				if ( ! is_wp_error( $dob ) && false !== smc_age_from_dob( $dob ) ) {
					$app_data['date_of_birth_enc'] = SMC_Security::encrypt( $dob, 'date-of-birth', array( 'user_id' => $user_id ) );
					$app_data['guardian_required'] = smc_age_from_dob( $dob ) < 18 ? 1 : 0;
				}
			}
			$phone = smc_normalize_phone( $legacy_profile['phone'] );
			if ( ! is_wp_error( $phone ) ) {
				$app_data['phone_e164_enc'] = SMC_Security::encrypt( $phone, 'membership-phone', array( 'user_id' => $user_id ) );
				$app_data['phone_hash'] = SMC_Security::blind_index( $phone, 'phone' );
			}
		}
		foreach ( $app_data as $value ) {
			if ( is_wp_error( $value ) ) {
				throw new RuntimeException( 'Legacy membership profile could not be authenticated and migrated.' );
			}
		}
		$updated = $wpdb->update(
			$wpdb->prefix . 'smc_applications',
			$app_data,
			array( 'user_id' => $user_id ),
			null,
			array( '%d' )
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Legacy membership profile database migration failed.' );
		}
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
		if ( $identity && 0 !== strpos( (string) $identity['document_number_enc'], SMC_Security::ENVELOPE . '.' ) ) {
			$number = SMC_Security::decrypt_legacy_value( $identity['document_number_enc'], 'identity' );
			if ( is_wp_error( $number ) ) {
				throw new RuntimeException( 'Legacy identity number authentication failed.' );
			}
			$country = preg_match( '/^[A-Z]{2}$/', (string) $identity['issuing_country'] ) ? $identity['issuing_country'] : 'ZZ';
			$enc = SMC_Security::encrypt( $number, 'identity-number', array( 'user_id' => $user_id, 'type' => $identity['document_type'], 'country' => $country ) );
			$hash = SMC_Security::blind_index( $country . '|' . $identity['document_type'] . '|' . $number, 'identity-number' );
			if ( is_wp_error( $enc ) || is_wp_error( $hash ) || false === $wpdb->update( $wpdb->prefix . 'smc_identity_records', array( 'document_number_enc' => $enc, 'document_number_hash' => $hash, 'issuing_country' => $country, 'name_match_status' => 'unreviewed', 'verified_at' => null, 'verified_by' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $identity['id'] ) ) ) {
				throw new RuntimeException( 'Legacy identity number migration failed.' );
			}
		}
		$documents = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d ORDER BY id", $user_id ), ARRAY_A );
		foreach ( $documents as $document ) {
			$migrated = SMC_Security::migrate_legacy_document( $document );
			if ( is_wp_error( $migrated ) ) {
				throw new RuntimeException( $migrated->get_error_message() );
			}
		}
		SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( $type, false ) );
		SMC_Security::revoke_all_sessions( $user_id, 'legacy_reverification_required' );
		SMC_Security::audit( 'legacy_membership_migrated_for_reverification', $user_id, array( 'type' => $type ) );
	}

	private static function record_failure( $scope, Throwable $error ) {
		update_option(
			'smc_last_migration_failure',
			array(
				'scope'   => sanitize_key( $scope ),
				'message' => sanitize_text_field( $error->getMessage() ),
				'time'    => current_time( 'mysql', true ),
			),
			false
		);
		SMC_Security::audit( 'migration_failed', 0, array( 'scope' => $scope, 'message' => $error->getMessage() ) );
	}
}

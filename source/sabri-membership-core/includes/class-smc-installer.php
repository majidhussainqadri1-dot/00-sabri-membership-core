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
		'smc_mfa_factor_state',
		'smc_recovery_codes',
		'smc_rate_limits',
		'smc_file_jobs',
		'smc_retention_holds',
		'smc_audit_log',
		'smc_audit_tail',
		'smc_migrations',
		'smc_role_grants',
		'smc_event_outbox',
		'smc_event_inbox',
		'smc_application_repairs',
	);

	/**
	 * Read-only audit readiness gate for callers that already own a transaction.
	 *
	 * MySQL DDL implicitly commits.  An audit invoked inside a membership/privacy
	 * transaction must therefore never run dbDelta, CREATE TABLE, or ALTER TABLE.
	 * The normal non-transactional bootstrap performs those repairs first; this
	 * gate only proves that the completed schema and full chain are ready.
	 */
	public static function audit_infrastructure_ready() {
		global $wpdb;
		$audit_table = $wpdb->prefix . 'smc_audit_log';
		$tail_table = $wpdb->prefix . 'smc_audit_tail';
		foreach ( array( $audit_table, $tail_table ) as $table ) {
			$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $exists ) { return new WP_Error( 'smc_audit_schema_not_ready', __( 'File 00 audit infrastructure is not initialized yet.', 'sabri-membership-core' ) ); }
			$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) ); }
		}
		$columns = array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
					$audit_table
				)
			)
		);
		if ( array_diff( array( 'id', 'actor_id', 'subject_hash', 'action', 'details', 'previous_hash', 'row_hash', 'audit_key_id', 'created_at' ), $columns ) ) {
			return new WP_Error( 'smc_audit_schema_upgrade_required', __( 'File 00 audit schema requires a non-transactional upgrade before this action can commit.', 'sabri-membership-core' ) );
		}
		if ( '1' !== (string) get_option( 'smc_audit_schema_initialized_v1', '' ) ) {
			return new WP_Error( 'smc_audit_schema_marker_missing', __( 'File 00 audit initialization has not completed.', 'sabri-membership-core' ) );
		}
		$tail_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tail_table} WHERE id=1" );
		if ( 1 !== $tail_count ) { return new WP_Error( 'smc_audit_tail_not_ready', __( 'File 00 audit serializer is not initialized yet.', 'sabri-membership-core' ) ); }
		if ( ! class_exists( 'SMC_Security' ) ) { return new WP_Error( 'smc_audit_security_unavailable', __( 'File 00 security runtime is unavailable.', 'sabri-membership-core' ) ); }
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
		$validation = SMC_Security::verify_audit_chain();
		if ( ! is_array( $validation ) || empty( $validation['valid'] ) || (int) ( $validation['checked'] ?? -1 ) !== $count ) {
			$reason = sanitize_key( (string) ( $validation['reason'] ?? 'unknown' ) );
			return new WP_Error( 'smc_audit_row_chain_invalid', __( 'File 00 audit integrity must be restored before this transaction can commit.', 'sabri-membership-core' ), array( 'reason' => $reason, 'failed_id' => absint( $validation['failed_id'] ?? 0 ) ) );
		}
		return true;
	}


	public static function ensure_audit_infrastructure() {
		global $wpdb;
		$audit_table = $wpdb->prefix . 'smc_audit_log';
		$tail_table  = $wpdb->prefix . 'smc_audit_tail';
		$audit_exists = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
		$tail_exists  = $tail_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tail_table ) ) );
		$initialized  = '1' === (string) get_option( 'smc_audit_schema_initialized_v1', '' );
		$bootstrap    = get_option( 'smc_audit_schema_bootstrap_v2', array() );
		$c            = $wpdb->get_charset_collate() . ' ENGINE=InnoDB';

		$create_audit = static function () use ( $wpdb, $audit_table, $c ) {
			$sql = "CREATE TABLE IF NOT EXISTS {$audit_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subject_hash char(64) NULL,
				action varchar(80) NOT NULL,
				details longtext NULL,
				previous_hash char(64) NOT NULL,
				row_hash char(64) NOT NULL,
				audit_key_id varchar(64) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY action_date (action,created_at),
				KEY subject_hash (subject_hash),
				KEY audit_key_id (audit_key_id)
			) {$c}";
			$wpdb->last_error = '';
			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				return new WP_Error( 'smc_audit_log_create_failed', sanitize_text_field( $wpdb->last_error ) );
			}
			$exists = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
			return $exists ? true : new WP_Error( 'smc_audit_log_create_incomplete', __( 'File 00 could not create the audit-log table.', 'sabri-membership-core' ) );
		};

		$create_tail = static function () use ( $wpdb, $tail_table, $c ) {
			$sql = "CREATE TABLE IF NOT EXISTS {$tail_table} (
				id tinyint(1) unsigned NOT NULL,
				row_hash char(64) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$c}";
			$wpdb->last_error = '';
			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				return new WP_Error( 'smc_audit_tail_create_failed', sanitize_text_field( $wpdb->last_error ) );
			}
			$exists = $tail_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tail_table ) ) );
			return $exists ? true : new WP_Error( 'smc_audit_tail_create_incomplete', __( 'File 00 could not create the audit-tail table.', 'sabri-membership-core' ) );
		};

		/*
		 * A 1.0.1 audit table can predate the HMAC columns. Add only the four
		 * non-destructive current columns needed by new writes; never rewrite or
		 * delete a historical row. Existing incompatible hash columns remain a
		 * fail-closed schema error rather than being silently coerced.
		 */
		$ensure_audit_columns = static function () use ( $wpdb, $audit_table ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
					$audit_table
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				return new WP_Error( 'smc_audit_schema_query_failed', sanitize_text_field( $wpdb->last_error ) );
			}
			$columns = array();
			foreach ( (array) $rows as $row ) {
				$columns[ (string) ( $row['COLUMN_NAME'] ?? '' ) ] = $row;
			}
			foreach ( array( 'id', 'actor_id', 'action', 'details', 'created_at' ) as $required ) {
				if ( ! isset( $columns[ $required ] ) ) {
					return new WP_Error( 'smc_audit_legacy_schema_unrecognized', __( 'The surviving audit table does not match a supported File 00 schema.', 'sabri-membership-core' ) );
				}
			}
			foreach ( array( 'subject_hash', 'previous_hash', 'row_hash' ) as $hash_column ) {
				if ( isset( $columns[ $hash_column ] ) && ( 'char' !== strtolower( (string) ( $columns[ $hash_column ]['DATA_TYPE'] ?? '' ) ) || 64 !== (int) ( $columns[ $hash_column ]['CHARACTER_MAXIMUM_LENGTH'] ?? 0 ) ) ) {
					return new WP_Error( 'smc_audit_hash_column_incompatible', sprintf( __( 'The surviving audit column %s has an incompatible definition.', 'sabri-membership-core' ), $hash_column ) );
				}
			}
			if ( isset( $columns['audit_key_id'] ) && ( 'varchar' !== strtolower( (string) ( $columns['audit_key_id']['DATA_TYPE'] ?? '' ) ) || 64 !== (int) ( $columns['audit_key_id']['CHARACTER_MAXIMUM_LENGTH'] ?? 0 ) ) ) {
				return new WP_Error( 'smc_audit_key_column_incompatible', __( 'The surviving audit key-generation column has an incompatible definition.', 'sabri-membership-core' ) );
			}
			$additions = array(
				'subject_hash'  => 'ADD COLUMN subject_hash char(64) NULL',
				'previous_hash' => "ADD COLUMN previous_hash char(64) NOT NULL DEFAULT ''",
				'row_hash'      => "ADD COLUMN row_hash char(64) NOT NULL DEFAULT ''",
				'audit_key_id'  => 'ADD COLUMN audit_key_id varchar(64) NULL',
			);
			foreach ( $additions as $column => $definition ) {
				if ( isset( $columns[ $column ] ) ) {
					continue;
				}
				$wpdb->last_error = '';
				if ( false === $wpdb->query( "ALTER TABLE {$audit_table} {$definition}" ) ) {
					return new WP_Error( 'smc_audit_legacy_column_add_failed', sanitize_text_field( $wpdb->last_error ?: $column ) );
				}
			}
			$audit_key_index = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s LIMIT 1',
					$audit_table,
					'audit_key_id'
				)
			);
			if ( 'audit_key_id' !== (string) $audit_key_index && false === $wpdb->query( "ALTER TABLE {$audit_table} ADD KEY audit_key_id (audit_key_id)" ) ) {
				return new WP_Error( 'smc_audit_key_index_failed', sanitize_text_field( $wpdb->last_error ?: 'audit_key_id' ) );
			}
			$actual = array_map(
				'strval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
						$audit_table
					)
				)
			);
			return array_diff( array_keys( $additions ), $actual )
				? new WP_Error( 'smc_audit_legacy_columns_incomplete', __( 'File 00 could not complete the non-destructive legacy audit schema bridge.', 'sabri-membership-core' ) )
				: true;
		};

		$verify_engine = static function ( $table ) use ( $wpdb ) {
			$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			return 'InnoDB' === $engine;
		};

		$persist_epoch = static function ( $reason ) {
			$epoch = get_option( 'smc_audit_chain_epoch_v1', array() );
			if ( ! is_array( $epoch ) || empty( $epoch['epoch_id'] ) ) {
				$epoch = array(
					'epoch_id'   => wp_generate_uuid4(),
					'started_at' => current_time( 'mysql', true ),
					'reason'     => sanitize_key( (string) $reason ),
				);
				if ( ! update_option( 'smc_audit_chain_epoch_v1', $epoch, false ) && get_option( 'smc_audit_chain_epoch_v1' ) !== $epoch ) {
					return new WP_Error( 'smc_audit_epoch_persist_failed', __( 'File 00 could not persist the audit-chain epoch marker.', 'sabri-membership-core' ) );
				}
			}
			if ( ! update_option( 'smc_audit_schema_initialized_v1', '1', false ) && '1' !== (string) get_option( 'smc_audit_schema_initialized_v1', '' ) ) {
				return new WP_Error( 'smc_audit_schema_marker_failed', __( 'File 00 could not persist the audit schema marker.', 'sabri-membership-core' ) );
			}
			delete_option( 'smc_audit_schema_bootstrap_v2' );
			return true;
		};

		// v1.2.32: distinguish genuine HMAC-chain damage from the exact 1.0.1
		// unchained schema. The latter is preserved byte-for-byte and sealed as a
		// lower-assurance legacy snapshot before a new cryptographic epoch starts.
		// Previously initialized partial schemas still fail closed as tampering.
		if ( $audit_exists xor $tail_exists ) {
			if ( $initialized ) {
				return new WP_Error( 'smc_audit_partial_schema_initialized', __( 'A previously initialized File 00 audit schema is only partially present. Manual integrity review is required.', 'sabri-membership-core' ) );
			}

			if ( $audit_exists && ! $tail_exists ) {
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
				if ( ! class_exists( 'SMC_Security' ) ) {
					return new WP_Error( 'smc_audit_security_unavailable', __( 'File 00 security runtime is unavailable during audit bootstrap recovery.', 'sabri-membership-core' ) );
				}
				if ( ! $verify_engine( $audit_table ) ) {
					return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) );
				}
				$inspection = SMC_Security::inspect_audit_rows_for_recovery( max( 1, $count ) );
				if ( ! is_array( $inspection ) || empty( $inspection['valid'] ) || (int) ( $inspection['checked'] ?? -1 ) !== $count ) {
					$reason = sanitize_key( (string) ( $inspection['reason'] ?? 'unknown' ) );
					return new WP_Error(
						'smc_audit_partial_rows_invalid',
						sprintf( __( 'The surviving File 00 audit rows failed integrity inspection (%s). Automatic serializer recovery is refused.', 'sabri-membership-core' ), $reason ?: 'unknown' ),
						array( 'reason' => $reason, 'failed_id' => absint( $inspection['failed_id'] ?? 0 ) )
					);
				}
				$anchor = null;
				if ( ! empty( $inspection['legacy_rows'] ) ) {
					$anchor = SMC_Security::establish_legacy_audit_anchor( $inspection );
					if ( is_wp_error( $anchor ) ) { return $anchor; }
				}

				$columns_ready = $ensure_audit_columns();
				if ( is_wp_error( $columns_ready ) ) { return $columns_ready; }
				$normalized = SMC_Security::inspect_audit_rows_for_recovery( max( 1, $count ) );
				foreach ( array( 'checked', 'verified_rows', 'legacy_rows', 'legacy_cutoff_id', 'legacy_snapshot_hash', 'first_modern_id', 'first_modern_hash', 'first_modern_key_id', 'audit_key_epoch_digest', 'last_id', 'last_hash' ) as $field ) {
					if ( ( $inspection[ $field ] ?? null ) !== ( $normalized[ $field ] ?? null ) ) {
						return new WP_Error( 'smc_audit_partial_rows_changed', __( 'The audit log changed while File 00 prepared the legacy-safe serializer recovery.', 'sabri-membership-core' ) );
					}
				}
				if ( ! empty( $normalized['legacy_rows'] ) ) {
					$anchor_check = SMC_Security::verify_legacy_audit_anchor( $normalized, $anchor );
					if ( empty( $anchor_check['valid'] ) ) {
						return new WP_Error( 'smc_audit_legacy_anchor_invalid', __( 'The legacy audit snapshot could not be bound to its cryptographic migration anchor.', 'sabri-membership-core' ) );
					}
				}

				$last = (string) ( $normalized['last_hash'] ?? '' );
				$made = $create_tail();
				if ( is_wp_error( $made ) ) { return $made; }
				if ( ! $verify_engine( $audit_table ) || ! $verify_engine( $tail_table ) ) {
					return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) );
				}

				$now = current_time( 'mysql', true );
				$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$tail_table} (id,row_hash,updated_at) VALUES (1,%s,%s) ON DUPLICATE KEY UPDATE id=id", $last, $now ) );
				if ( false === $inserted ) {
					return new WP_Error( 'smc_audit_tail_init_failed', sanitize_text_field( $wpdb->last_error ?: __( 'File 00 could not initialize the audit serializer.', 'sabri-membership-core' ) ) );
				}
				$current_tail = (string) $wpdb->get_var( "SELECT row_hash FROM {$tail_table} WHERE id=1 LIMIT 1" );
				$current_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
				$current_inspection = SMC_Security::inspect_audit_rows_for_recovery( max( 1, $current_count ) );
				$current_last = (string) ( $current_inspection['last_hash'] ?? '' );
				$current_validation = SMC_Security::verify_audit_chain( max( 1, $current_count ) );
				if ( ! is_array( $current_validation ) || empty( $current_validation['valid'] ) || (int) ( $current_validation['checked'] ?? -1 ) !== $current_count ) {
					return new WP_Error( 'smc_audit_partial_rows_changed', __( 'The audit log changed during serializer recovery and could not be revalidated. Retry from a quiet administrator request.', 'sabri-membership-core' ) );
				}
				if ( ! hash_equals( $current_last, $current_tail ) ) {
					return new WP_Error( 'smc_audit_tail_recovery_race', __( 'The audit serializer could not be bound to the verified final audit row. Automatic recovery is refused.', 'sabri-membership-core' ) );
				}

				$reason = ! empty( $current_inspection['legacy_rows'] ) ? 'resume_anchored_legacy_audit_log' : ( $count > 0 ? 'resume_verified_nonempty_partial_audit_log' : 'resume_empty_partial_audit_log' );
				$marked = $persist_epoch( $reason );
				return is_wp_error( $marked ) ? $marked : array(
					'ready'                => true,
					'bootstrapped'         => true,
					'repaired_partial'     => 'tail',
					'verified_rows'        => absint( $current_inspection['verified_rows'] ?? 0 ),
					'legacy_rows_anchored' => absint( $current_inspection['legacy_rows'] ?? 0 ),
				);
			}

			$tail_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tail_table}" );
			$tail_hash  = (string) $wpdb->get_var( "SELECT row_hash FROM {$tail_table} WHERE id=1 LIMIT 1" );
			if ( $tail_count > 1 || '' !== $tail_hash ) {
				return new WP_Error( 'smc_audit_partial_schema_nonempty', __( 'The audit serializer contains state while the audit log is missing. Automatic repair is unsafe.', 'sabri-membership-core' ) );
			}
			$made = $create_audit();
			if ( is_wp_error( $made ) ) { return $made; }
			if ( ! $verify_engine( $audit_table ) || ! $verify_engine( $tail_table ) ) {
				return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) );
			}
			if ( 0 === $tail_count ) {
				$now = current_time( 'mysql', true );
				if ( 1 !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$tail_table} (id,row_hash,updated_at) VALUES (1,'',%s)", $now ) ) ) {
					return new WP_Error( 'smc_audit_tail_init_failed', __( 'File 00 could not initialize the audit serializer.', 'sabri-membership-core' ) );
				}
			}
			$marked = $persist_epoch( 'resume_empty_partial_audit_tail' );
			return is_wp_error( $marked ) ? $marked : array( 'ready' => true, 'bootstrapped' => true, 'repaired_partial' => 'log' );
		}

		if ( ! $audit_exists && ! $tail_exists ) {
			if ( $initialized ) {
				return new WP_Error( 'smc_audit_schema_missing_after_initialization', __( 'Previously initialized File 00 audit tables are missing. Manual integrity review is required.', 'sabri-membership-core' ) );
			}
			$marker = array( 'release' => SMC_VERSION, 'started_at' => current_time( 'mysql', true ), 'stage' => 'creating' );
			update_option( 'smc_audit_schema_bootstrap_v2', $marker, false );
			$made = $create_audit();
			if ( is_wp_error( $made ) ) { return $made; }
			$made = $create_tail();
			if ( is_wp_error( $made ) ) { return $made; }
			if ( ! $verify_engine( $audit_table ) || ! $verify_engine( $tail_table ) ) {
				return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) );
			}
			$now = current_time( 'mysql', true );
			if ( 1 !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$tail_table} (id,row_hash,updated_at) VALUES (1,'',%s)", $now ) ) ) {
				return new WP_Error( 'smc_audit_tail_init_failed', __( 'File 00 could not initialize the audit serializer.', 'sabri-membership-core' ) );
			}
			$marked = $persist_epoch( 'schema_bootstrap_both_missing' );
			return is_wp_error( $marked ) ? $marked : array( 'ready' => true, 'bootstrapped' => true );
		}

		if ( ! class_exists( 'SMC_Security' ) ) {
			return new WP_Error( 'smc_audit_security_unavailable', __( 'File 00 security runtime is unavailable during audit repair.', 'sabri-membership-core' ) );
		}
		if ( ! $verify_engine( $audit_table ) || ! $verify_engine( $tail_table ) ) {
			return new WP_Error( 'smc_audit_schema_engine', __( 'File 00 audit tables require InnoDB.', 'sabri-membership-core' ) );
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
		$inspection = SMC_Security::inspect_audit_rows_for_recovery( max( 1, $count ) );
		if ( ! is_array( $inspection ) || empty( $inspection['valid'] ) || (int) ( $inspection['checked'] ?? -1 ) !== $count ) {
			$reason = sanitize_key( (string) ( $inspection['reason'] ?? 'unknown' ) );
			return new WP_Error( 'smc_audit_row_chain_invalid', sprintf( __( 'File 00 audit rows failed integrity inspection (%s).', 'sabri-membership-core' ), $reason ?: 'unknown' ), array( 'reason' => $reason, 'failed_id' => absint( $inspection['failed_id'] ?? 0 ) ) );
		}
		if ( ! empty( $inspection['legacy_rows'] ) ) {
			$anchor_check = SMC_Security::verify_legacy_audit_anchor( $inspection );
			if ( empty( $anchor_check['valid'] ) ) {
				if ( $initialized ) {
					return new WP_Error( 'smc_audit_legacy_anchor_missing_after_initialization', __( 'An initialized audit schema contains legacy rows without its required migration anchor.', 'sabri-membership-core' ) );
				}
				$anchor = SMC_Security::establish_legacy_audit_anchor( $inspection );
				if ( is_wp_error( $anchor ) ) { return $anchor; }
			}
		}
		$columns_ready = $ensure_audit_columns();
		if ( is_wp_error( $columns_ready ) ) { return $columns_ready; }
		$normalized = SMC_Security::inspect_audit_rows_for_recovery( max( 1, $count ) );
		foreach ( array( 'checked', 'verified_rows', 'legacy_rows', 'legacy_cutoff_id', 'legacy_snapshot_hash', 'first_modern_id', 'first_modern_hash', 'first_modern_key_id', 'audit_key_epoch_digest', 'last_id', 'last_hash' ) as $field ) {
			if ( ( $inspection[ $field ] ?? null ) !== ( $normalized[ $field ] ?? null ) ) {
				return new WP_Error( 'smc_audit_rows_changed_during_schema_bridge', __( 'The audit history changed during the non-destructive schema bridge.', 'sabri-membership-core' ) );
			}
		}
		if ( ! empty( $normalized['legacy_rows'] ) ) {
			$anchor_check = SMC_Security::verify_legacy_audit_anchor( $normalized );
			if ( empty( $anchor_check['valid'] ) ) {
				return new WP_Error( 'smc_audit_legacy_anchor_invalid', __( 'The legacy audit snapshot no longer matches its pre-HMAC migration anchor.', 'sabri-membership-core' ) );
			}
		}
		$validation = SMC_Security::verify_audit_chain( max( 1, $count ) );
		if ( ! is_array( $validation ) || empty( $validation['valid'] ) || (int) ( $validation['checked'] ?? -1 ) !== $count ) {
			$reason = sanitize_key( (string) ( $validation['reason'] ?? 'unknown' ) );
			return new WP_Error( 'smc_audit_row_chain_invalid', sprintf( __( 'File 00 audit rows or their legacy anchor failed verification (%s).', 'sabri-membership-core' ), $reason ?: 'unknown' ), array( 'reason' => $reason, 'failed_id' => absint( $validation['failed_id'] ?? 0 ) ) );
		}

		$tail = $wpdb->get_row( "SELECT id,row_hash FROM {$tail_table} WHERE id=1 LIMIT 1", ARRAY_A );
		if ( ! $tail ) {
			$last = (string) ( $normalized['last_hash'] ?? '' );
			$now  = current_time( 'mysql', true );
			if ( 1 !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$tail_table} (id,row_hash,updated_at) VALUES (1,%s,%s)", $last, $now ) ) ) {
				return new WP_Error( 'smc_audit_tail_init_failed', __( 'File 00 could not restore the audit serializer pointer.', 'sabri-membership-core' ) );
			}
		}

		if ( ! $initialized ) {
			$marked = $persist_epoch( 'existing_complete_audit_schema' );
			if ( is_wp_error( $marked ) ) { return $marked; }
		}
		return array(
			'ready'                => true,
			'bootstrapped'         => false,
			'verified_rows'        => absint( $normalized['verified_rows'] ?? 0 ),
			'legacy_rows_anchored' => absint( $normalized['legacy_rows'] ?? 0 ),
		);
	}

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

	public static function deactivate() {
		foreach ( array( 'smc_lifecycle_daily', 'smc_process_file_jobs', 'smc_process_event_outbox', 'smc_reconcile_applications', 'smc_continue_migration' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		delete_transient( 'smc_activation_notice' );
		delete_transient( 'smc_institutional_repair_notice' );
	}

	public static function maybe_upgrade() {
		if ( SMC_DB_VERSION === get_option( 'smc_db_version', '' ) ) {
			return;
		}
		$lock = null;
		try {
			$lock = self::acquire_lock( 1 );
			self::create_tables();
			self::create_roles();
			self::create_pages();
			if ( ! self::backfill_role_grants() ) {
				return;
			}
			self::run_legacy_batch();
		} catch ( Throwable $error ) {
			self::record_failure( 'upgrade', $error );
		} finally {
			if ( is_array( $lock ) ) {
				self::release_lock( $lock );
			}
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
			$stored = get_option( 'smc_schema_owner_lock', array() );
			if ( is_array( $stored ) && hash_equals( (string) ( $stored['token'] ?? '' ), $token ) ) {
				delete_option( 'smc_schema_owner_lock' );
			}
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
		$c = $wpdb->get_charset_collate() . ' ENGINE=InnoDB';
		$sql = array();

		$sql[] = "CREATE TABLE {$p}smc_applications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			legal_name varchar(190) NOT NULL DEFAULT '',
			date_of_birth_enc longtext NULL,
			gender varchar(12) NOT NULL DEFAULT '',
			residence_country char(2) NOT NULL DEFAULT '',
			city varchar(120) NOT NULL DEFAULT '',
			address_enc longtext NULL,
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
			generation bigint(20) unsigned NOT NULL DEFAULT 1,
			is_current tinyint(1) NOT NULL DEFAULT 1,
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
			delivery_receipt_hash char(64) NULL,
			delivered_at datetime NULL,
			otp_attempts smallint unsigned NOT NULL DEFAULT 0,
			otp_expires_at datetime NULL,
			requested_at datetime NOT NULL,
			verified_at datetime NULL,
			withdrawn_at datetime NULL,
			ip_hash char(64) NULL,
			device_hash char(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_generation (user_id,generation),
			KEY user_current (user_id,is_current),
			KEY guardian_email_hash (guardian_email_hash),
			KEY status (status)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_verification_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'submitted',
			queue_type varchar(32) NOT NULL DEFAULT 'new',
			assigned_reviewer bigint(20) unsigned NOT NULL DEFAULT 0,
			assigned_at datetime NULL,
			conflict_status varchar(20) NOT NULL DEFAULT 'undeclared',
			conflict_note text NULL,
			reason_code varchar(64) NULL,
			trace_id char(36) NULL,
			sla_due_at datetime NULL,
			reviewer_note longtext NULL,
			applicant_version bigint(20) unsigned NOT NULL DEFAULT 1,
			approval_generation char(36) NULL,
			approval_snapshot_hash char(64) NULL,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			submitted_at datetime NOT NULL,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY queue (status,queue_type,assigned_reviewer),
			KEY sla_due_at (sla_due_at),
			KEY trace_id (trace_id)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_approval_votes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			reviewer_id bigint(20) unsigned NOT NULL,
			approval_generation char(36) NOT NULL DEFAULT '',
			decision varchar(20) NOT NULL,
			reason text NOT NULL,
			evidence_snapshot longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_generation_reviewer (request_id,approval_generation,reviewer_id),
			KEY decision (request_id,approval_generation,decision)
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
			delivery_receipt_hash char(64) NULL,
			delivered_at datetime NULL,
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
			KEY user_active (user_id,revoked_at),
			KEY expires_at (expires_at),
			KEY revoked_at (revoked_at)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_mfa_factor_state (
			user_id bigint(20) unsigned NOT NULL,
			last_totp_slice bigint(20) NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id)
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
			audit_key_id varchar(64) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action_date (action,created_at),
			KEY subject_hash (subject_hash),
			KEY audit_key_id (audit_key_id)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_audit_tail (
			id tinyint(1) unsigned NOT NULL,
			row_hash char(64) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_migrations (
			migration_key varchar(80) NOT NULL,
			status varchar(20) NOT NULL,
			cursor_value bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (migration_key)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_role_grants (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			membership_type varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			source_application_version bigint(20) unsigned NOT NULL DEFAULT 1,
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			expires_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_type (user_id,membership_type),
			KEY status_type (status,membership_type)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_event_outbox (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			event_type varchar(80) NOT NULL,
			event_version varchar(20) NOT NULL,
			correlation_id char(36) NOT NULL,
			dedupe_hash char(64) NOT NULL,
			subject_hash char(64) NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_error text NULL,
			delivered_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY dedupe_hash (dedupe_hash),
			KEY queue (status,next_attempt_at),
			KEY correlation_id (correlation_id)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_event_inbox (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consumer varchar(80) NOT NULL,
			event_id char(36) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'processing',
			attempts smallint unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			received_at datetime NOT NULL,
			processed_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY consumer_event (consumer,event_id),
			KEY status (status)
		) {$c};";

		$sql[] = "CREATE TABLE {$p}smc_application_repairs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trace_id char(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			repair_type varchar(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			details longtext NULL,
			attempts smallint unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_error text NULL,
			resolved_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY trace_id (trace_id),
			KEY queue (status,next_attempt_at),
			KEY user_id (user_id)
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
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== (string) $engine ) {
				throw new RuntimeException( 'File 00 requires InnoDB transactional tables: ' . $suffix );
			}
		}
		$critical_columns = array(
			'smc_guardian_consents' => array( 'generation','is_current','delivery_receipt_hash','delivered_at' ),
			'smc_contact_otps' => array( 'delivery_receipt_hash','delivered_at' ),
			'smc_verification_requests' => array( 'approval_generation','approval_snapshot_hash','applicant_version','row_version' ),
			'smc_mfa_factor_state' => array( 'last_totp_slice' ),
			'smc_audit_log' => array( 'previous_hash','row_hash','audit_key_id' ),
			'smc_audit_tail' => array( 'row_hash' ),
		);
		foreach ( $critical_columns as $suffix => $required_columns ) {
			$table = $p . $suffix;
			$actual_columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			$missing_columns = array_diff( $required_columns, array_map( 'strval', (array) $actual_columns ) );
			if ( $missing_columns ) {
				throw new RuntimeException( 'Critical File 00 schema columns are missing in ' . $suffix . ': ' . implode( ',', $missing_columns ) );
			}
		}

		// dbDelta does not reliably remove superseded unique indexes. Remove the
		// pre-1.4.0 guardian/vote uniqueness only after the replacement indexes exist.
		$guardian_table = $p . 'smc_guardian_consents';
		$guardian_replacement = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='user_generation' AND NON_UNIQUE=0", $guardian_table ) );
		if ( $guardian_replacement < 2 ) { throw new RuntimeException( 'Guardian generation replacement uniqueness was not created.' ); }
		$legacy_guardian_unique = $wpdb->get_var( "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . esc_sql( $guardian_table ) . "' AND INDEX_NAME='user_id' AND NON_UNIQUE=0 LIMIT 1" );
		if ( $legacy_guardian_unique && false === $wpdb->query( "ALTER TABLE {$guardian_table} DROP INDEX user_id" ) ) {
			throw new RuntimeException( 'Legacy guardian uniqueness could not be removed safely.' );
		}
		$vote_table = $p . 'smc_approval_votes';
		$vote_replacement = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='request_generation_reviewer' AND NON_UNIQUE=0", $vote_table ) );
		if ( $vote_replacement < 3 ) { throw new RuntimeException( 'Approval-generation reviewer uniqueness was not created.' ); }
		$legacy_vote_unique = $wpdb->get_var( "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . esc_sql( $vote_table ) . "' AND INDEX_NAME='request_reviewer' AND NON_UNIQUE=0 LIMIT 1" );
		if ( $legacy_vote_unique && false === $wpdb->query( "ALTER TABLE {$vote_table} DROP INDEX request_reviewer" ) ) {
			throw new RuntimeException( 'Legacy approval-vote uniqueness could not be removed safely.' );
		}
		if ( false === $wpdb->query( "UPDATE {$guardian_table} SET generation=id,is_current=1 WHERE generation<=0" ) ) {
			throw new RuntimeException( 'Guardian generation backfill failed.' );
		}
		$tail_hash = (string) $wpdb->get_var( "SELECT row_hash FROM {$p}smc_audit_log ORDER BY id DESC LIMIT 1" );
		$now = current_time( 'mysql', true );
		$tail_ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}smc_audit_tail (id,row_hash,updated_at) VALUES (1,%s,%s) ON DUPLICATE KEY UPDATE id=id", $tail_hash, $now ) );
		if ( false === $tail_ok ) {
			throw new RuntimeException( 'Audit tail serializer could not be initialized.' );
		}
		$started = false !== $wpdb->query( 'START TRANSACTION' );
		$rolled = $started && false !== $wpdb->query( 'ROLLBACK' );
		if ( ! $started || ! $rolled ) {
			throw new RuntimeException( 'The database does not provide the required transaction semantics.' );
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
			array( 'read' => true, 'smc_review_verification' => true, 'smc_view_private_documents' => true, 'smc_finalize_verification' => true, 'smc_restore_membership' => true )
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'smc_review_verification', 'smc_view_private_documents', 'smc_finalize_verification', 'smc_manage_membership', 'smc_manage_retention_holds', 'smc_restore_membership', 'smc_manage_repairs' ) as $cap ) {
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
		if ( ! self::backfill_role_grants() ) {
			return;
		}
		self::run_legacy_batch();
	}

	private static function backfill_role_grants() {
		global $wpdb;
		$key = 'role-grants-to-1.3.0';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_migrations WHERE migration_key=%s", $key ), ARRAY_A );
		if ( $row && 'complete' === $row['status'] ) {
			return true;
		}
		$cursor = $row ? absint( $row['cursor_value'] ) : 0;
		$apps = $wpdb->get_results( $wpdb->prepare( "SELECT id,user_id,membership_type,status,row_version FROM {$wpdb->prefix}smc_applications WHERE id>%d ORDER BY id ASC LIMIT 200", $cursor ), ARRAY_A );
		foreach ( (array) $apps as $app ) {
			$type = isset( smc_account_types()[ $app['membership_type'] ] ) ? $app['membership_type'] : 'member';
			$status = 'approved' === $app['status'] ? 'approved' : 'pending';
			if ( ! SMC_Contracts::upsert_role_grant( (int) $app['user_id'], $type, $status, max( 1, (int) $app['row_version'] ), 0 ) || ! SMC_Contracts::sync_wordpress_roles( (int) $app['user_id'] ) ) {
				throw new RuntimeException( 'Role-grant backfill failed.' );
			}
			$cursor = (int) $app['id'];
		}
		$status = count( (array) $apps ) < 200 ? 'complete' : 'running';
		$now = current_time( 'mysql', true );
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_migrations (migration_key,status,cursor_value,last_error,updated_at) VALUES (%s,%s,%d,NULL,%s) ON DUPLICATE KEY UPDATE status=VALUES(status),cursor_value=VALUES(cursor_value),last_error=NULL,updated_at=VALUES(updated_at)", $key, $status, $cursor, $now ) );
		if ( false === $ok ) {
			throw new RuntimeException( 'Role-grant migration checkpoint failed.' );
		}
		if ( 'running' === $status && ! wp_next_scheduled( 'smc_continue_migration' ) ) {
			wp_schedule_single_event( time() + 30, 'smc_continue_migration' );
		}
		return 'complete' === $status;
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
			if ( SMC_DB_VERSION !== get_option( 'smc_db_version', '' ) || SMC_VERSION !== get_option( 'smc_release_version', '' ) ) {
				throw new RuntimeException( 'Migration completion versions could not be persisted.' );
			}
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
		if ( ! SMC_Contracts::upsert_role_grant( $user_id, $type, 'pending', 1, 0 ) ) {
			throw new RuntimeException( 'Legacy membership role grant migration failed.' );
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
		$role_ok = SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( $type, false ) );
		$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'legacy_reverification_required' );
		$audit_ok = $sessions_ok && SMC_Security::audit( 'legacy_membership_migrated_for_reverification', $user_id, array( 'type' => $type ) );
		if ( ! $role_ok || ! $sessions_ok || ! $audit_ok ) {
			throw new RuntimeException( 'Legacy membership migration could not commit role, session, and audit containment.' );
		}
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

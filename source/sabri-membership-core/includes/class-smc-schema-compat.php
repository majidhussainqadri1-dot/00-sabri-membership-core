<?php
defined( 'ABSPATH' ) || exit;

/**
 * Narrow schema/data-compatibility repairs proven necessary by live deployment evidence.
 *
 * This class must never become a generic database repair surface. Every mutation
 * below is allowlisted to an exact historical File 00 state and fails closed for
 * unknown shapes.
 */
final class SMC_Schema_Compat {
	/** Live-proven role-grant failure text emitted by pre-1.2.39 backfill. */
	const ORPHAN_BACKFILL_FAILURE = 'Role-grant backfill failed.';

	/**
	 * Register compatibility preflights ahead of normal migration entry points.
	 */
	public static function init() {
		add_action( 'smc_continue_migration', array( __CLASS__, 'reconcile_verification_queue_index' ), 1 );
		add_action( 'smc_continue_migration', array( __CLASS__, 'reconcile_orphaned_role_grant_backfill' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'reconcile_orphaned_role_grant_backfill' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'finalize_orphaned_role_grant_recovery' ), 11 );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function role_grant_checkpoint( $status, $cursor ) {
		global $wpdb;
		$key = 'role-grants-to-1.3.0';
		$now = current_time( 'mysql', true );
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_migrations (migration_key,status,cursor_value,last_error,updated_at)
				 VALUES (%s,%s,%d,NULL,%s)
				 ON DUPLICATE KEY UPDATE status=VALUES(status),cursor_value=VALUES(cursor_value),last_error=NULL,updated_at=VALUES(updated_at)",
				$key,
				$status,
				absint( $cursor ),
				$now
			)
		);
		return false !== $ok;
	}

	private static function deterministic_orphan_trace_id( $application_id, $user_id ) {
		$hash = hash( 'sha256', 'file00-orphaned-application|' . absint( $application_id ) . '|' . absint( $user_id ) );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Preserve and quarantine an application whose WordPress principal no longer exists.
	 *
	 * The application is historical source evidence and is never deleted. Any
	 * derivative role grant left behind by a previously interrupted backfill is
	 * suspended so a later account re-creation cannot inherit stale entitlement.
	 */
	private static function quarantine_orphaned_application( $app ) {
		global $wpdb;
		$app_id  = absint( $app['id'] ?? 0 );
		$user_id = absint( $app['user_id'] ?? 0 );
		if ( ! $app_id || ! $user_id ) {
			return false;
		}

		$repairs = $wpdb->prefix . 'smc_application_repairs';
		$grants  = $wpdb->prefix . 'smc_role_grants';
		$trace   = self::deterministic_orphan_trace_id( $app_id, $user_id );
		$now     = current_time( 'mysql', true );
		$details = wp_json_encode(
			array(
				'application_id'  => $app_id,
				'orphan_user_id'  => $user_id,
				'membership_type' => sanitize_key( (string) ( $app['membership_type'] ?? 'member' ) ),
				'application_status' => sanitize_key( (string) ( $app['status'] ?? 'draft' ) ),
				'source'          => 'live-v1.2.38-role-grant-backfill',
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $details ) ) {
			return false;
		}

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$repairs} WHERE trace_id=%s LIMIT 1", $trace )
		);
		if ( ! $existing ) {
			$inserted = $wpdb->insert(
				$repairs,
				array(
					'trace_id'        => $trace,
					'user_id'         => $user_id,
					'repair_type'     => 'orphaned_application_missing_user',
					'status'          => 'pending',
					'details'         => $details,
					'attempts'        => 0,
					'next_attempt_at' => $now,
					'last_error'      => 'WordPress principal missing; historical application preserved and role entitlement quarantined.',
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				return false;
			}
			if ( ! SMC_Security::audit( 'orphaned_membership_application_quarantined', 0, array( 'application_id' => $app_id, 'orphan_user_id' => $user_id, 'trace_id' => $trace ) ) ) {
				return false;
			}
		}

		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$grants} SET status='suspended',updated_at=%s WHERE user_id=%d AND status<>'suspended'",
				$now,
				$user_id
			)
		);
		return false !== $updated && '' === (string) $wpdb->last_error;
	}

	/**
	 * Live-proven v1.2.38 compatibility bridge for orphaned application rows.
	 *
	 * Hostinger live evidence showed the historical application table contained
	 * user_id 7 and 8 after those WordPress principals had been deleted. The old
	 * backfill created/updated a role grant and then failed at role synchronization,
	 * permanently preventing the checkpoint from being written. This method runs
	 * only when the exact historical failure is already recorded, the old 1.2.0
	 * migration baseline is complete, and all required modern tables/audit
	 * infrastructure exist. Missing principals are quarantined; existing users use
	 * the canonical grant/synchronization path. Privacy-erasure locks remain
	 * fail-closed and are never treated as missing-user orphans.
	 *
	 * @return bool True only when the compatibility pass reached a complete checkpoint.
	 */
	public static function reconcile_orphaned_role_grant_backfill() {
		global $wpdb;
		if ( ! defined( 'SMC_DB_VERSION' ) || SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ) ) {
			return false;
		}
		$failure = get_option( 'smc_last_migration_failure', array() );
		if ( ! is_array( $failure ) || self::ORPHAN_BACKFILL_FAILURE !== (string) ( $failure['message'] ?? '' ) ) {
			return false;
		}
		if ( ! class_exists( 'SMC_Security' ) || ! SMC_Security::key_ready() ) {
			return false;
		}
		$required = array( 'smc_applications', 'smc_migrations', 'smc_role_grants', 'smc_application_repairs', 'smc_audit_log', 'smc_audit_tail' );
		foreach ( $required as $suffix ) {
			if ( ! self::table_exists( $wpdb->prefix . $suffix ) ) {
				return false;
			}
		}
		$audit_ready = SMC_Installer::audit_infrastructure_ready();
		if ( is_wp_error( $audit_ready ) ) {
			return false;
		}
		$legacy = $wpdb->get_row(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}smc_migrations WHERE migration_key=%s", 'legacy-users-to-1.2.0' ),
			ARRAY_A
		);
		if ( ! $legacy || 'complete' !== (string) ( $legacy['status'] ?? '' ) ) {
			return false;
		}
		$checkpoint = $wpdb->get_row(
			$wpdb->prepare( "SELECT status,cursor_value FROM {$wpdb->prefix}smc_migrations WHERE migration_key=%s", 'role-grants-to-1.3.0' ),
			ARRAY_A
		);
		if ( $checkpoint && 'complete' === (string) ( $checkpoint['status'] ?? '' ) ) {
			return true;
		}
		$cursor = $checkpoint ? absint( $checkpoint['cursor_value'] ?? 0 ) : 0;
		$processed = 0;

		for ( $batch = 0; $batch < 50; $batch++ ) {
			$apps = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id,user_id,membership_type,status,row_version FROM {$wpdb->prefix}smc_applications WHERE id>%d ORDER BY id ASC LIMIT 200",
					$cursor
				),
				ARRAY_A
			);
			if ( empty( $apps ) ) {
				return self::role_grant_checkpoint( 'complete', $cursor );
			}

			foreach ( (array) $apps as $app ) {
				$app_id  = absint( $app['id'] ?? 0 );
				$user_id = absint( $app['user_id'] ?? 0 );
				$user = $user_id ? get_userdata( $user_id ) : false;
				if ( ! $user ) {
					if ( ! self::quarantine_orphaned_application( $app ) ) {
						update_option( 'smc_last_migration_failure', array( 'scope' => 'upgrade', 'message' => 'Orphaned application quarantine failed.', 'time' => current_time( 'mysql', true ) ), false );
						return false;
					}
				} else {
					if ( smc_privacy_erasure_lock( $user_id ) ) {
						update_option( 'smc_last_migration_failure', array( 'scope' => 'upgrade', 'message' => 'Role-grant backfill blocked by privacy erasure lock.', 'time' => current_time( 'mysql', true ) ), false );
						return false;
					}
					$type = isset( smc_account_types()[ $app['membership_type'] ] ) ? $app['membership_type'] : 'member';
					$status = 'approved' === $app['status'] ? 'approved' : 'pending';
					if ( ! SMC_Contracts::upsert_role_grant( $user_id, $type, $status, max( 1, (int) $app['row_version'] ), 0 ) || ! SMC_Contracts::sync_wordpress_roles( $user_id ) ) {
						update_option( 'smc_last_migration_failure', array( 'scope' => 'upgrade', 'message' => self::ORPHAN_BACKFILL_FAILURE, 'time' => current_time( 'mysql', true ) ), false );
						return false;
					}
				}
				$cursor = $app_id;
				$processed++;
				if ( ! self::role_grant_checkpoint( 'running', $cursor ) ) {
					update_option( 'smc_last_migration_failure', array( 'scope' => 'upgrade', 'message' => 'Role-grant compatibility checkpoint failed.', 'time' => current_time( 'mysql', true ) ), false );
					return false;
				}
			}

			if ( count( (array) $apps ) < 200 ) {
				return self::role_grant_checkpoint( 'complete', $cursor );
			}
		}

		update_option( 'smc_last_migration_failure', array( 'scope' => 'upgrade', 'message' => 'Role-grant compatibility batch limit reached.', 'time' => current_time( 'mysql', true ) ), false );
		if ( $processed && ! wp_next_scheduled( 'smc_continue_migration' ) ) {
			wp_schedule_single_event( time() + 30, 'smc_continue_migration' );
		}
		return false;
	}

	/**
	 * Retry the normal installer only after the orphan-safe preflight completed.
	 * This closes hosts where required modern tables were first created by the
	 * priority-10 bootstrap in the same request. Successful promotion also clears
	 * only the exact stale failure/deferred markers proven by this incident.
	 */
	public static function finalize_orphaned_role_grant_recovery() {
		if ( ! current_user_can( 'manage_options' ) || SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ) ) {
			return;
		}
		$failure = get_option( 'smc_last_migration_failure', array() );
		if ( ! is_array( $failure ) || self::ORPHAN_BACKFILL_FAILURE !== (string) ( $failure['message'] ?? '' ) ) {
			return;
		}
		if ( ! self::reconcile_orphaned_role_grant_backfill() ) {
			return;
		}
		try {
			SMC_Installer::maybe_upgrade();
		} catch ( Throwable $error ) {
			if ( function_exists( 'smc_record_bootstrap_failure' ) ) {
				smc_record_bootstrap_failure( 'orphan_role_grant_recovery', $error );
			}
			return;
		}
		if ( SMC_DB_VERSION !== (string) get_option( 'smc_db_version', '' ) ) {
			return;
		}
		$current_failure = get_option( 'smc_last_migration_failure', array() );
		if ( is_array( $current_failure ) && self::ORPHAN_BACKFILL_FAILURE === (string) ( $current_failure['message'] ?? '' ) ) {
			delete_option( 'smc_last_migration_failure' );
		}
		$deferred = get_option( 'smc_migration_deferred_v1', array() );
		if ( is_array( $deferred ) && 'key_configuration_required' === (string) ( $deferred['reason'] ?? '' ) ) {
			delete_option( 'smc_migration_deferred_v1' );
		}
		delete_option( 'smc_activation_pending_v2' );
		update_option(
			'smc_activation_bootstrap_state_v2',
			array(
				'status'     => 'ready',
				'phase'      => 'deferred_upgrade',
				'message'    => 'Protected bootstrap completed.',
				'updated_at' => current_time( 'mysql', true ),
			),
			false
		);
	}

	/**
	 * Reconcile the pre-queue_type verification queue index before dbDelta runs.
	 */
	public static function reconcile_verification_queue_index() {
		global $wpdb;

		$table = $wpdb->prefix . 'smc_verification_requests';
		$exists = $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		if ( ! $exists ) {
			return self::reconcile_approval_decision_index();
		}

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE,INDEX_TYPE
				 FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='queue'
				 ORDER BY SEQ_IN_INDEX",
				$table
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect the verification queue index: ' . sanitize_text_field( $wpdb->last_error ) );
		}
		if ( empty( $rows ) ) {
			return self::reconcile_approval_decision_index();
		}

		$columns = array();
		foreach ( (array) $rows as $row ) {
			if (
				1 !== (int) ( $row['NON_UNIQUE'] ?? -1 ) ||
				'BTREE' !== strtoupper( (string) ( $row['INDEX_TYPE'] ?? '' ) ) ||
				null !== ( $row['SUB_PART'] ?? null )
			) {
				throw new RuntimeException( 'Unsupported verification queue index attributes; automatic migration refused.' );
			}
			$columns[] = (string) ( $row['COLUMN_NAME'] ?? '' );
		}

		$current = array( 'status', 'queue_type', 'assigned_reviewer' );
		$legacy  = array( 'status', 'assigned_reviewer' );
		if ( $current === $columns ) {
			return self::reconcile_approval_decision_index();
		}
		if ( $legacy !== $columns ) {
			throw new RuntimeException( 'Unsupported verification queue index definition; automatic migration refused.' );
		}

		$wpdb->last_error = '';
		if ( false === $wpdb->query( "ALTER TABLE {$table} DROP INDEX `queue`" ) ) {
			throw new RuntimeException( 'Legacy verification queue index could not be removed safely: ' . sanitize_text_field( $wpdb->last_error ) );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='queue'",
				$table
			)
		);
		if ( 0 !== $remaining ) {
			throw new RuntimeException( 'Legacy verification queue index removal did not complete.' );
		}

		return self::reconcile_approval_decision_index();
	}

	/** Reconcile the live-proven pre-approval_generation decision index before dbDelta. */
	public static function reconcile_approval_decision_index() {
		global $wpdb;

		$table = $wpdb->prefix . 'smc_approval_votes';
		$exists = $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		if ( ! $exists ) {
			return true;
		}

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE,INDEX_TYPE
				 FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='decision'
				 ORDER BY SEQ_IN_INDEX",
				$table
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect the approval decision index: ' . sanitize_text_field( $wpdb->last_error ) );
		}
		if ( empty( $rows ) ) {
			return true;
		}

		$columns = array();
		foreach ( (array) $rows as $row ) {
			if (
				1 !== (int) ( $row['NON_UNIQUE'] ?? -1 ) ||
				'BTREE' !== strtoupper( (string) ( $row['INDEX_TYPE'] ?? '' ) ) ||
				null !== ( $row['SUB_PART'] ?? null )
			) {
				throw new RuntimeException( 'Unsupported approval decision index attributes; automatic migration refused.' );
			}
			$columns[] = (string) ( $row['COLUMN_NAME'] ?? '' );
		}

		$current = array( 'request_id', 'approval_generation', 'decision' );
		$legacy  = array( 'request_id', 'decision' );
		if ( $current === $columns ) {
			return true;
		}
		if ( $legacy !== $columns ) {
			throw new RuntimeException( 'Unsupported approval decision index definition; automatic migration refused.' );
		}

		$wpdb->last_error = '';
		if ( false === $wpdb->query( "ALTER TABLE {$table} DROP INDEX `decision`" ) ) {
			throw new RuntimeException( 'Legacy approval decision index could not be removed safely: ' . sanitize_text_field( $wpdb->last_error ) );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='decision'",
				$table
			)
		);
		if ( 0 !== $remaining ) {
			throw new RuntimeException( 'Legacy approval decision index removal did not complete.' );
		}

		return true;
	}

	/** Prove the final queue and approval-decision index signatures after dbDelta. */
	public static function assert_current_queue_indexes() {
		global $wpdb;
		$expected = array(
			$wpdb->prefix . 'smc_verification_requests' => array( 'status', 'queue_type', 'assigned_reviewer' ),
			$wpdb->prefix . 'smc_file_jobs'             => array( 'status', 'next_attempt_at' ),
		);

		foreach ( $expected as $table => $wanted ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE,INDEX_TYPE
					 FROM information_schema.STATISTICS
					 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='queue'
					 ORDER BY SEQ_IN_INDEX",
					$table
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error || count( (array) $rows ) !== count( $wanted ) ) {
				throw new RuntimeException( 'Current queue index could not be verified for ' . $table . '.' );
			}
			$actual = array();
			foreach ( (array) $rows as $row ) {
				if (
					1 !== (int) ( $row['NON_UNIQUE'] ?? -1 ) ||
					'BTREE' !== strtoupper( (string) ( $row['INDEX_TYPE'] ?? '' ) ) ||
					null !== ( $row['SUB_PART'] ?? null )
				) {
					throw new RuntimeException( 'Current queue index attributes are invalid for ' . $table . '.' );
				}
				$actual[] = (string) ( $row['COLUMN_NAME'] ?? '' );
			}
			if ( $wanted !== $actual ) {
				throw new RuntimeException( 'Current queue index columns are invalid for ' . $table . '.' );
			}
		}

		$table = $wpdb->prefix . 'smc_approval_votes';
		$wanted = array( 'request_id', 'approval_generation', 'decision' );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE,INDEX_TYPE
				 FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME='decision'
				 ORDER BY SEQ_IN_INDEX",
				$table
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || count( (array) $rows ) !== count( $wanted ) ) {
			throw new RuntimeException( 'Current approval decision index could not be verified.' );
		}
		$actual = array();
		foreach ( (array) $rows as $row ) {
			if (
				1 !== (int) ( $row['NON_UNIQUE'] ?? -1 ) ||
				'BTREE' !== strtoupper( (string) ( $row['INDEX_TYPE'] ?? '' ) ) ||
				null !== ( $row['SUB_PART'] ?? null )
			) {
				throw new RuntimeException( 'Current approval decision index attributes are invalid.' );
			}
			$actual[] = (string) ( $row['COLUMN_NAME'] ?? '' );
		}
		if ( $wanted !== $actual ) {
			throw new RuntimeException( 'Current approval decision index columns are invalid.' );
		}

		return true;
	}
}

SMC_Schema_Compat::init();

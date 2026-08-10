<?php
defined( 'ABSPATH' ) || exit;

/**
 * Narrow schema-compatibility repairs proven necessary by live deployment evidence.
 *
 * This class must never become a generic database repair surface. Every mutation
 * below is allowlisted to an exact historical File 00 schema signature and fails
 * closed for unknown shapes.
 */
final class SMC_Schema_Compat {
	/**
	 * Register the preflight ahead of the scheduled legacy-migration callback.
	 *
	 * The normal administrator bootstrap invokes the same preflight explicitly.
	 * This early cron hook closes the alternate `smc_continue_migration` entry
	 * point so a scheduled retry cannot reach dbDelta with the stale named index.
	 */
	public static function init() {
		add_action( 'smc_continue_migration', array( __CLASS__, 'reconcile_verification_queue_index' ), 1 );
	}

	/**
	 * Reconcile the pre-queue_type verification queue index before dbDelta runs.
	 *
	 * Historical File 00 deployments can contain the non-unique BTREE index
	 * queue(status,assigned_reviewer). Current schema requires
	 * queue(status,queue_type,assigned_reviewer). WordPress dbDelta may attempt to
	 * ADD the changed named index without first dropping the old named index,
	 * causing MySQL/MariaDB to abort with "Duplicate key name 'queue'".
	 *
	 * Only the exact known legacy index is removed. Fresh installs, the current
	 * index, and an absent index are no-ops. Any other shape is refused.
	 *
	 * @return bool True when the schema is safe for the normal dbDelta pass.
	 * @throws RuntimeException When the index cannot be inspected or has an
	 *                          unsupported shape.
	 */
	public static function reconcile_verification_queue_index() {
		global $wpdb;

		$table = $wpdb->prefix . 'smc_verification_requests';
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
			return true;
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
			return true;
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

		return true;
	}

	/**
	 * Prove the final queue-index signatures after the normal dbDelta migration.
	 *
	 * @return bool
	 * @throws RuntimeException When either critical queue index is not exact.
	 */
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
		return true;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Lifecycle {
	private static $repair_failures = 0;
	private const AUTOMATED_AGE_REASON = 'age_eligibility_failed';
	private const INSTITUTIONAL_AGE_META = '_smc_institutional_age_evidence_attention';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'smc_continue_migration', array( 'SMC_Installer', 'continue_migration' ) );
		add_action( 'smc_lifecycle_daily', array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( 'smc_lifecycle_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'smc_lifecycle_daily' );
		}
		if ( ! wp_next_scheduled( 'smc_process_file_jobs' ) ) {
			wp_schedule_event( time() + 300, 'smc_fifteen_minutes', 'smc_process_file_jobs' );
		}
		if ( ! wp_next_scheduled( 'smc_process_event_outbox' ) ) {
			wp_schedule_event( time() + 360, 'smc_fifteen_minutes', 'smc_process_event_outbox' );
		}
		if ( ! wp_next_scheduled( 'smc_reconcile_applications' ) ) {
			wp_schedule_event( time() + 420, 'smc_fifteen_minutes', 'smc_reconcile_applications' );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['smc_fifteen_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes', 'sabri-membership-core' ) );
		return $schedules;
	}

	public static function daily() {
		global $wpdb;
		$lock = 'smc_lifecycle_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 32 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return;
		}
		try {
			self::repair_institutional_accounts();
			self::recheck_ages();
			self::expire_documents();
			self::cleanup_database();
			self::cleanup_filesystem();
			SMC_Security::process_file_jobs();
		} finally {
			$released = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			if ( 1 !== $released ) {
				SMC_Security::audit( 'lifecycle_lock_release_failed', 0 );
			}
		}
	}

	/**
	 * Repair only a demonstrably automated institutional suspension.
	 *
	 * A canonical Founder or WordPress Administrator is not an ordinary
	 * membership applicant. A former lifecycle age-evidence failure may not be
	 * reinterpreted as a disciplinary suspension. Manual rejection, suspension,
	 * appeal and erasure states remain untouched unless a later explicit approval
	 * resolved them and the latest hard-block event is the automated age check.
	 *
	 * @return int Number of repaired institutional accounts.
	 */
	public static function repair_institutional_accounts() {
		global $wpdb;
		self::$repair_failures = 0;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}smc_applications WHERE status='suspended' ORDER BY id ASC LIMIT 500",
			ARRAY_A
		);
		$repaired = 0;
		foreach ( $rows as $app ) {
			$user_id = (int) $app['user_id'];
			if ( ! self::is_institutional_user( $user_id ) || self::has_unresolved_manual_hard_block( $user_id ) ) {
				continue;
			}
			$context = self::latest_hard_block_context( $user_id );
			if ( 'membership_restricted' !== $context['action'] || self::AUTOMATED_AGE_REASON !== $context['reason'] ) {
				continue;
			}
			if ( self::repair_institutional_account( $app ) ) {
				++$repaired;
			} else {
				++self::$repair_failures;
			}
		}
		return $repaired;
	}

	public static function institutional_repair_complete() {
		return 0 === self::$repair_failures;
	}

	private static function recheck_ages() {
		global $wpdb;
		$cursor = absint( get_option( 'smc_age_recheck_cursor', 0 ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_applications WHERE id>%d AND status NOT IN ('erasure_pending') ORDER BY id LIMIT 500",
				$cursor
			),
			ARRAY_A
		);
		foreach ( $rows as $app ) {
			$cursor = (int) $app['id'];
			$user_id = (int) $app['user_id'];
			$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
			$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
			$minimum_age = smc_minimum_age_for_gender( $app['gender'] );
			$invalid = false === $age || false === $minimum_age || $age < $minimum_age || ( $age < 18 && smc_is_professional_type( $app['membership_type'] ) );

			if ( $invalid ) {
				if ( self::is_institutional_user( $user_id ) ) {
					self::record_institutional_age_attention( $user_id, is_wp_error( $dob ) || empty( $app['date_of_birth_enc'] ) ? 'date_of_birth_unreadable' : self::AUTOMATED_AGE_REASON );
					continue;
				}
				self::restrict( $app, self::AUTOMATED_AGE_REASON );
				continue;
			}

			if ( get_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, true ) ) {
				$wpdb->query( 'START TRANSACTION' );
				delete_user_meta( $user_id, self::INSTITUTIONAL_AGE_META );
				$deleted = ! metadata_exists( 'user', $user_id, self::INSTITUTIONAL_AGE_META );
				$audit_ok = $deleted && SMC_Security::audit( 'institutional_age_evidence_resolved', $user_id );
				if ( $deleted && $audit_ok ) {
					$wpdb->query( 'COMMIT' );
				} else {
					$wpdb->query( 'ROLLBACK' );
					clean_user_cache( $user_id );
				}
			}
			if ( $age >= 18 && $app['guardian_required'] ) {
				$wpdb->query( 'START TRANSACTION' );
				$updated = $wpdb->update( $wpdb->prefix . 'smc_applications', array( 'guardian_required' => 0, 'row_version' => (int) $app['row_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $app['id'], 'row_version' => (int) $app['row_version'] ) );
				$audit_ok = 1 === $updated && SMC_Security::audit( 'guardian_requirement_ended_at_adulthood', $user_id );
				if ( 1 === $updated && $audit_ok ) {
					$wpdb->query( 'COMMIT' );
				} else {
					$wpdb->query( 'ROLLBACK' );
				}
			}
		}
		update_option( 'smc_age_recheck_cursor', count( $rows ) < 500 ? 0 : $cursor, false );
	}

	private static function is_institutional_user( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return smc_is_founder( $user_id ) || ( $user && user_can( $user, 'manage_options' ) );
	}

	private static function record_institutional_age_attention( $user_id, $reason ) {
		global $wpdb;
		$reason = sanitize_key( $reason );
		$current = get_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, true );
		if ( is_array( $current ) && $reason === ( $current['reason'] ?? '' ) && ! empty( $current['audited_at'] ) ) {
			return true;
		}
		$state = array( 'reason' => $reason, 'audited_at' => current_time( 'mysql', true ) );
		$wpdb->query( 'START TRANSACTION' );
		update_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, $state );
		$stored = get_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, true );
		$stored_ok = is_array( $stored ) && $reason === ( $stored['reason'] ?? '' ) && ! empty( $stored['audited_at'] );
		$audit_ok = $stored_ok && SMC_Security::audit(
			'institutional_age_evidence_attention_required',
			$user_id,
			array( 'reason' => $reason )
		);
		if ( ! $stored_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	private static function latest_hard_block_context( $user_id ) {
		global $wpdb;
		$empty = array( 'action' => '', 'reason' => '' );
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( '' === $subject_hash ) {
			return $empty;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT action,details FROM {$wpdb->prefix}smc_audit_log
				WHERE subject_hash=%s AND action IN ('membership_restricted','membership_suspended','membership_rejected','membership_appeal_review','membership_erasure_pending','membership_erasure_requested')
				ORDER BY id DESC LIMIT 1",
				$subject_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return $empty;
		}
		$decoded = isset( $row['details'] ) && is_string( $row['details'] ) ? json_decode( $row['details'], true ) : null;
		return array(
			'action' => isset( $row['action'] ) ? sanitize_key( $row['action'] ) : '',
			'reason' => is_array( $decoded ) && isset( $decoded['reason'] ) ? sanitize_key( $decoded['reason'] ) : '',
		);
	}

	private static function has_unresolved_manual_hard_block( $user_id ) {
		global $wpdb;
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( '' === $subject_hash ) {
			return true;
		}
		$manual_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_audit_log
				WHERE subject_hash=%s AND action IN ('membership_suspended','membership_rejected','membership_appeal_review','membership_erasure_pending','membership_erasure_requested')
				ORDER BY id DESC LIMIT 1",
				$subject_hash
			)
		);
		if ( $manual_id <= 0 ) {
			return false;
		}
		$cleared_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_audit_log
				WHERE subject_hash=%s AND action IN ('verification_approved','membership_approved','institutional_membership_restored')
				ORDER BY id DESC LIMIT 1",
				$subject_hash
			)
		);
		return $manual_id > $cleared_id;
	}

	private static function repair_institutional_account( $app ) {
		global $wpdb;
		$user_id = (int) $app['user_id'];
		$request_status = sanitize_key(
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1",
					$user_id
				)
			)
		);
		$restorable = array( 'draft', 'guardian_pending', 'submitted', 'under_review', 'more_information', 'resubmitted', 'approval_pending', 'approved', 'expired' );
		$restored_status = in_array( $request_status, $restorable, true ) ? $request_status : 'draft';
		$now = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d AND status='suspended'",
				$restored_status,
				$now,
				(int) $app['id'],
				(int) $app['row_version']
			)
		);
		$audit_ok = 1 === $updated && SMC_Security::audit(
			'institutional_lifecycle_suspension_repaired',
			$user_id,
			array(
				'previous_status' => 'suspended',
				'restored_status' => $restored_status,
				'source_reason'   => self::AUTOMATED_AGE_REASON,
			)
		);
		if ( 1 !== $updated || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
		return true;
	}

	private static function expire_documents() {
		global $wpdb;
		$users = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->prefix}smc_identity_documents WHERE expiry_date IS NOT NULL AND expiry_date<UTC_DATE() AND status<>'expired'" );
		foreach ( array_map( 'absint', $users ) as $user_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_identity_documents SET status='expired',updated_at=%s WHERE user_id=%d AND expiry_date IS NOT NULL AND expiry_date<UTC_DATE()", current_time( 'mysql', true ), $user_id ) );
			$app = smc_application( $user_id );
			if ( $app ) {
				self::restrict( $app, 'identity_document_expired', 'expired' );
				SMC_Security::audit( 'identity_document_expired', $user_id, array( 'status' => 'expired' ) );
			}
			smc_notify( $user_id, 'identity_evidence_expired', __( 'Identity Evidence Expired', 'sabri-membership-core' ), __( 'Your membership is restricted until current identity evidence is reviewed.', 'sabri-membership-core' ), 'critical', smc_page_url( 'application', '/membership-application/' ) );
		}
	}

	private static function restrict( $app, $reason, $status = 'suspended' ) {
		global $wpdb;
		$user_id = (int) $app['user_id'];
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d",
				$status,
				current_time( 'mysql', true ),
				(int) $app['id'],
				(int) $app['row_version']
			)
		);
		$role_ok = 1 === $updated && SMC_Contracts::set_all_roles_pending( $user_id, (int) $app['row_version'] + 1 );
		$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, $reason );
		$audit_ok = $sessions_ok && SMC_Security::audit( 'membership_restricted', $user_id, array( 'reason' => $reason ) );
		if ( 1 !== $updated || ! $role_ok || ! $sessions_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
		return true;
	}

	private static function cleanup_database() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_rate_limits WHERE reset_at<UTC_TIMESTAMP() - INTERVAL 1 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 7 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 30 DAY OR revoked_at<UTC_TIMESTAMP() - INTERVAL 30 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_file_jobs WHERE status='complete' AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_event_outbox WHERE status='delivered' AND delivered_at<UTC_TIMESTAMP() - INTERVAL 90 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_event_inbox WHERE status='processed' AND processed_at<UTC_TIMESTAMP() - INTERVAL 90 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_application_repairs WHERE status='complete' AND resolved_at<UTC_TIMESTAMP() - INTERVAL 90 DAY" );
		$wpdb->query( "UPDATE {$wpdb->prefix}smc_guardian_consents SET otp_hash=NULL,otp_lookup_hash=NULL,invitation_token_hash=NULL WHERE status='pending' AND otp_expires_at<UTC_TIMESTAMP()" );
	}

	private static function cleanup_filesystem() {
		global $wpdb;
		$dir = SMC_Security::private_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		$iterator = new DirectoryIterator( $dir );
		$now = time();
		foreach ( $iterator as $file ) {
			if ( $file->isDot() || $file->isLink() || ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			$path = $file->getPathname();
			if ( 0 === strpos( $name, '.smc-tmp-' ) && $file->getMTime() < $now - DAY_IN_SECONDS ) {
				SMC_Security::verified_unlink( $path );
				continue;
			}
			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.smcdoc$/', $name ) ) {
				$registered = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_documents WHERE stored_name=%s LIMIT 1", $name ) );
				$lease = $path . '.lease';
				if ( ! $registered && ( ! is_file( $lease ) || filemtime( $lease ) < $now - DAY_IN_SECONDS ) ) {
					SMC_Security::queue_file_job( $name, 'delete_orphan', '', 'unregistered_expired_document' );
				}
			}
			if ( false !== strpos( $name, '.erase-' ) && $file->getMTime() < $now - DAY_IN_SECONDS ) {
				$canonical = strstr( $name, '.erase-', true );
				$registered = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_documents WHERE stored_name=%s LIMIT 1", $canonical ) );
				if ( ! $registered || is_file( trailingslashit( $dir ) . $canonical ) ) {
					SMC_Security::queue_file_job( $name, 'delete_quarantine', '', 'expired_erasure_quarantine' );
				}
			}
		}
	}
}

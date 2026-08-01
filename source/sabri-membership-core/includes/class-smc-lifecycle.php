<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Lifecycle {
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
	}

	public static function schedules( $schedules ) {
		$schedules['smc_fifteen_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes', 'sabri-membership-core' ) );
		return $schedules;
	}

	public static function daily() {
		self::repair_institutional_accounts();
		self::recheck_ages();
		self::expire_documents();
		self::cleanup_database();
		self::cleanup_filesystem();
		SMC_Security::process_file_jobs();
	}

	/**
	 * Repair only a demonstrably automated institutional suspension.
	 *
	 * A canonical Founder or WordPress Administrator is not an ordinary
	 * membership applicant. A former lifecycle age-evidence failure may not be
	 * reinterpreted as a disciplinary suspension. Manual rejection, suspension,
	 * appeal and erasure states remain untouched unless the latest matching audit
	 * event proves that the suspension was created by the age lifecycle itself.
	 *
	 * @return int Number of repaired institutional accounts.
	 */
	public static function repair_institutional_accounts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}smc_applications WHERE status='suspended' ORDER BY id ASC LIMIT 500",
			ARRAY_A
		);
		$repaired = 0;
		foreach ( $rows as $app ) {
			if ( ! self::is_institutional_user( (int) $app['user_id'] ) ) {
				continue;
			}
			$context = self::latest_hard_block_context( (int) $app['user_id'] );
			if ( 'membership_restricted' !== $context['action'] || self::AUTOMATED_AGE_REASON !== $context['reason'] ) {
				continue;
			}
			if ( self::repair_institutional_account( $app ) ) {
				++$repaired;
			}
		}
		return $repaired;
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
			$invalid = false === $age || $age < smc_minimum_age_for_gender( $app['gender'] ) || ( $age < 18 && smc_is_professional_type( $app['membership_type'] ) );

			if ( $invalid ) {
				if ( self::is_institutional_user( $user_id ) ) {
					self::record_institutional_age_attention( $user_id, is_wp_error( $dob ) || empty( $app['date_of_birth_enc'] ) ? 'date_of_birth_unreadable' : self::AUTOMATED_AGE_REASON );
					continue;
				}
				self::restrict( $app, self::AUTOMATED_AGE_REASON );
				continue;
			}

			if ( get_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, true ) ) {
				delete_user_meta( $user_id, self::INSTITUTIONAL_AGE_META );
				SMC_Security::audit( 'institutional_age_evidence_resolved', $user_id );
			}
			if ( $age >= 18 && $app['guardian_required'] ) {
				$wpdb->update( $wpdb->prefix . 'smc_applications', array( 'guardian_required' => 0, 'row_version' => (int) $app['row_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $app['id'], 'row_version' => (int) $app['row_version'] ) );
				SMC_Security::audit( 'guardian_requirement_ended_at_adulthood', $user_id );
			}
		}
		update_option( 'smc_age_recheck_cursor', count( $rows ) < 500 ? 0 : $cursor, false );
	}

	private static function is_institutional_user( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return smc_is_founder( $user_id ) || ( $user && user_can( $user, 'manage_options' ) );
	}

	private static function record_institutional_age_attention( $user_id, $reason ) {
		$reason = sanitize_key( $reason );
		if ( $reason === get_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, true ) ) {
			return;
		}
		update_user_meta( $user_id, self::INSTITUTIONAL_AGE_META, $reason );
		SMC_Security::audit(
			'institutional_age_evidence_attention_required',
			$user_id,
			array( 'reason' => $reason )
		);
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
			}
			smc_notify( $user_id, 'identity_evidence_expired', __( 'Identity Evidence Expired', 'sabri-membership-core' ), __( 'Your membership is restricted until current identity evidence is reviewed.', 'sabri-membership-core' ), 'critical', smc_page_url( 'application', '/membership-application/' ) );
		}
	}

	private static function restrict( $app, $reason, $status = 'suspended' ) {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d",
				$status,
				current_time( 'mysql', true ),
				(int) $app['id'],
				(int) $app['row_version']
			)
		);
		if ( 1 === $updated ) {
			SMC_Contracts::set_exact_role( (int) $app['user_id'], smc_role_for_type( $app['membership_type'], false ) );
			SMC_Security::revoke_all_sessions( (int) $app['user_id'], $reason );
			SMC_Security::audit( 'membership_restricted', (int) $app['user_id'], array( 'reason' => $reason ) );
		}
	}

	private static function cleanup_database() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_rate_limits WHERE reset_at<UTC_TIMESTAMP() - INTERVAL 1 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 7 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 30 DAY OR revoked_at<UTC_TIMESTAMP() - INTERVAL 30 DAY" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_file_jobs WHERE status='complete' AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 DAY" );
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
			if ( preg_match( '/^[a-f0-9-]+\.smcdoc$/', $name ) ) {
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

<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Lifecycle {
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
		self::recheck_ages();
		self::expire_documents();
		self::cleanup_database();
		self::cleanup_filesystem();
		SMC_Security::process_file_jobs();
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
			$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => (int) $app['user_id'] ) );
			$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
			if ( false === $age || $age < smc_minimum_age_for_gender( $app['gender'] ) || ( $age < 18 && smc_is_professional_type( $app['membership_type'] ) ) ) {
				self::restrict( $app, 'age_eligibility_failed' );
				continue;
			}
			if ( $age >= 18 && $app['guardian_required'] ) {
				$wpdb->update( $wpdb->prefix . 'smc_applications', array( 'guardian_required' => 0, 'row_version' => (int) $app['row_version'] + 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $app['id'], 'row_version' => (int) $app['row_version'] ) );
				SMC_Security::audit( 'guardian_requirement_ended_at_adulthood', (int) $app['user_id'] );
			}
		}
		update_option( 'smc_age_recheck_cursor', count( $rows ) < 500 ? 0 : $cursor, false );
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

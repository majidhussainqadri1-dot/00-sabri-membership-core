<?php
defined( 'ABSPATH' ) || exit;

/**
 * Privacy-minimized, durable File 00 event transport.
 *
 * The outbox is written in the caller's database transaction where possible.
 * Consumers use the inbox helper for replay-safe at-least-once processing.
 */
final class SMC_Events {
	const VERSION = '1.0.0';

	private static $audit_map = array(
		'membership_application_submitted' => 'MembershipSubmitted',
		'guardian_consent_verified'         => 'GuardianVerified',
		'verification_approved'             => 'MembershipApproved',
		'membership_approved'               => 'MembershipApproved',
		'membership_suspended'              => 'MembershipSuspended',
		'membership_restricted'             => 'MembershipSuspended',
		'contact_verified'                  => 'ContactReverified',
		'contact_reverification_required'   => 'ContactReverificationRequired',
		'identity_document_expired'         => 'DocumentExpired',
		'guardian_consent_withdrawn'        => 'ConsentWithdrawn',
		'membership_erasure_requested'      => 'AccountErasureStarted',
		'privacy_erasure_started'           => 'AccountErasureStarted',
		'membership_restored'               => 'MembershipRestored',
		'institutional_membership_restored' => 'MembershipRestored',
	);

	public static function init() {
		add_action( 'smc_process_event_outbox', array( __CLASS__, 'process_outbox' ) );
		add_action( 'smc_lifecycle_daily', array( __CLASS__, 'schedule_processor' ), 30 );
	}

	public static function schedule_processor() {
		if ( ! wp_next_scheduled( 'smc_process_event_outbox' ) ) {
			wp_schedule_single_event( time() + 15, 'smc_process_event_outbox' );
		}
	}

	private static function table_exists( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function minimal_payload( $details ) {
		$details = is_array( $details ) ? $details : array();
		$allowed = array(
			'age', 'type', 'guardian_required', 'applicant_version', 'status', 'old_status', 'new_status',
			'document_key', 'version', 'channel', 'reason_code', 'scope', 'source_version', 'policy_version',
			'contract_version', 'queue_type', 'trace_id', 'role_types',
		);
		$payload = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $details ) ) {
				continue;
			}
			$value = $details[ $key ];
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$payload[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$payload[ $key ] = array_values( array_map( 'sanitize_key', array_slice( $value, 0, 20 ) ) );
			} else {
				$payload[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 190 );
			}
		}
		$payload['source_version']   = SMC_VERSION;
		$payload['contract_version'] = SMC_CONTRACT_VERSION;
		return $payload;
	}

	public static function from_audit( $action, $subject_user_id, $details, $audit_id = 0 ) {
		$action = sanitize_key( $action );
		if ( ! isset( self::$audit_map[ $action ] ) ) {
			return true;
		}
		$details = is_array( $details ) ? $details : array();
		$details['audit_id'] = absint( $audit_id );
		$dedupe = $action . '|' . absint( $subject_user_id ) . '|' . absint( $audit_id );
		return self::emit( self::$audit_map[ $action ], $subject_user_id, $details, $dedupe );
	}

	public static function emit( $event_type, $subject_user_id = 0, $details = array(), $dedupe_key = '' ) {
		global $wpdb;
		if ( ! self::table_exists( 'smc_event_outbox' ) ) {
			return false;
		}
		$event_type = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $event_type );
		if ( '' === $event_type ) {
			return false;
		}
		$event_id = wp_generate_uuid4();
		$correlation_id = isset( $details['correlation_id'] ) && preg_match( '/^[0-9a-f-]{36}$/i', (string) $details['correlation_id'] )
			? strtolower( (string) $details['correlation_id'] )
			: wp_generate_uuid4();
		$payload = self::minimal_payload( $details );
		$dedupe_key = '' !== (string) $dedupe_key ? (string) $dedupe_key : $event_type . '|' . absint( $subject_user_id ) . '|' . $correlation_id;
		$dedupe_hash = hash( 'sha256', $dedupe_key );
		$subject_hash = $subject_user_id ? SMC_Security::subject_hash( $subject_user_id ) : null;
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_event_outbox
			(event_id,event_type,event_version,correlation_id,dedupe_hash,subject_hash,payload,status,attempts,next_attempt_at,created_at,updated_at)
			VALUES (%s,%s,%s,%s,%s,%s,%s,'pending',0,%s,%s,%s)
			ON DUPLICATE KEY UPDATE event_id=event_id",
			$event_id,
			$event_type,
			self::VERSION,
			$correlation_id,
			$dedupe_hash,
			$subject_hash,
			wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			$now,
			$now,
			$now
		);
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return false;
		}
		self::schedule_processor();
		return true;
	}

	public static function process_outbox( $limit = 25, $only_id = 0 ) {
		global $wpdb;
		if ( ! self::table_exists( 'smc_event_outbox' ) ) {
			return 0;
		}
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$only_id = absint( $only_id );
		$lock_name = 'smc_outbox_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 28 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) ) ) {
			return 0;
		}
		$processed = 0;
		try {
			$wpdb->query(
				"UPDATE {$wpdb->prefix}smc_event_outbox SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error='Recovered stale processing claim.',updated_at=UTC_TIMESTAMP() WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
			);
			if ( $only_id ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_event_outbox WHERE id=%d AND status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() LIMIT 1", $only_id ), ARRAY_A );
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}smc_event_outbox
						WHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP()
						ORDER BY id ASC LIMIT %d",
						$limit
					),
					ARRAY_A
				);
			}
			foreach ( (array) $rows as $row ) {
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}smc_event_outbox SET status='processing',attempts=attempts+1,updated_at=%s
						WHERE id=%d AND status IN ('pending','retry')",
						current_time( 'mysql', true ),
						(int) $row['id']
					)
				);
				if ( 1 !== $claimed ) {
					continue;
				}
				$event = array(
					'event_id'       => $row['event_id'],
					'event_type'     => $row['event_type'],
					'event_version'  => $row['event_version'],
					'correlation_id' => $row['correlation_id'],
					'subject_hash'   => $row['subject_hash'],
					'payload'        => json_decode( (string) $row['payload'], true ),
					'created_at'     => $row['created_at'],
				);
				$accepted = has_filter( 'smc_deliver_event' ) ? apply_filters( 'smc_deliver_event', false, $event ) : false;
				do_action( 'smc_outbox_event', $event );
				if ( true === $accepted ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_event_outbox SET status='delivered',delivered_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status='processing'", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $row['id'] ) );
					++$processed;
				} else {
					$attempts = (int) $row['attempts'] + 1;
					$status = $attempts >= 10 ? 'dead_letter' : 'retry';
					$delay = min( DAY_IN_SECONDS, (int) pow( 2, min( 10, $attempts ) ) * MINUTE_IN_SECONDS );
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_event_outbox SET status=%s,next_attempt_at=%s,last_error=%s,updated_at=%s WHERE id=%d AND status='processing'", $status, gmdate( 'Y-m-d H:i:s', time() + $delay ), 'Consumer delivery was not acknowledged.', current_time( 'mysql', true ), (int) $row['id'] ) );
				}
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE status IN ('pending','retry')" ) > 0 ) {
			self::schedule_processor();
		}
		return $processed;
	}

	/**
	 * Replay-safe consumer helper. Handler must return true before the inbox row is committed.
	 */
	public static function consume( $consumer, $event, $handler ) {
		global $wpdb;
		$consumer = sanitize_key( $consumer );
		if ( '' === $consumer || ! is_array( $event ) || empty( $event['event_id'] ) || ! is_callable( $handler ) || ! self::table_exists( 'smc_event_inbox' ) ) {
			return false;
		}
		$event_id = sanitize_text_field( $event['event_id'] );
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}smc_event_inbox (consumer,event_id,status,attempts,received_at,updated_at) VALUES (%s,%s,'processing',1,%s,%s)",
				$consumer, $event_id, $now, $now
			)
		);
		if ( 1 !== $inserted ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_event_inbox WHERE consumer=%s AND event_id=%s FOR UPDATE", $consumer, $event_id ), ARRAY_A );
			if ( $row && 'processed' === $row['status'] ) {
				$wpdb->query( 'COMMIT' );
				return true;
			}
			if ( ! $row || ( 'processing' === $row['status'] && strtotime( $row['updated_at'] . ' UTC' ) > time() - 900 ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_event_inbox SET status='processing',attempts=attempts+1,last_error=NULL,updated_at=%s WHERE id=%d", $now, (int) $row['id'] ) );
			if ( 1 !== $claimed ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}
		$ok = true === call_user_func( $handler, $event );
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_event_inbox SET status='processed',processed_at=%s,last_error=NULL,updated_at=%s WHERE consumer=%s AND event_id=%s AND status='processing'", $now, $now, $consumer, $event_id ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}
}

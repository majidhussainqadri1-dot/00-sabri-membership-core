<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Privacy {
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['sabri-membership-core'] = array(
			'exporter_friendly_name' => __( 'Sabri Membership Core Data', 'sabri-membership-core' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data'=>array(), 'done'=>true ); }
		global $wpdb;
		$user_id = (int) $user->ID;
		$page = max( 1, absint( $page ) );
		// The privacy API invokes exporters page-by-page. File 00 has many
		// datasets, so a per-dataset limit of 100 could emit >1,000 records in one
		// call. Keep each call under a bounded global item budget while preserving
		// deterministic offsets across every dataset.
		$limit = 6;
		$offset = ( $page - 1 ) * $limit;
		$data = 1 === $page ? self::export_identity( $user_id ) : array();
		$more = false;
		$datasets = array(
			'smc_identity_documents'   => "SELECT id,user_id,document_key,version,label,original_name,mime_type,file_size,plain_sha256,scan_status,status,issue_date,expiry_date,reviewed_at,reviewer_note,created_at,updated_at FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_verification_requests'=> "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_verification_events'  => "SELECT * FROM {$wpdb->prefix}smc_verification_events WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_consents'             => "SELECT * FROM {$wpdb->prefix}smc_consents WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_role_grants'          => "SELECT * FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_application_repairs'  => "SELECT * FROM {$wpdb->prefix}smc_application_repairs WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_guardian_consents'    => "SELECT * FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_retention_holds'      => "SELECT * FROM {$wpdb->prefix}smc_retention_holds WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_auth_sessions'        => "SELECT id,user_id,expires_at,two_factor_at,last_totp_slice,ip_hash,device_hash,revoked_at,created_at,updated_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_recovery_codes'       => "SELECT id,user_id,created_at,consumed_at FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_contact_otps'         => "SELECT id,user_id,channel,attempts,expires_at,delivered_at,verified_at,created_at FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d",
			'smc_mfa_factor_state'     => "SELECT user_id,last_totp_slice,updated_at FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d ORDER BY user_id LIMIT %d OFFSET %d",
		);
		foreach ( $datasets as $name => $sql ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $limit + 1, $offset ), ARRAY_A );
			if ( count( $rows ) > $limit ) { $more = true; $rows = array_slice( $rows, 0, $limit ); }
			foreach ( $rows as $index => $row ) {
				foreach ( array_keys( $row ) as $key ) {
					if ( false !== strpos( $key, '_enc' ) || false !== strpos( $key, 'otp_' ) || false !== strpos( $key, 'token_hash' ) || 'code_hash' === $key || 'code_lookup_hash' === $key ) { unset( $row[ $key ] ); }
				}
				$data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), $name . '-' . $user_id . '-' . ( $offset + $index ), $row );
			}
		}
		// Join votes to their owned verification request instead of first loading
		// an unbounded list of request IDs into PHP memory.
		$votes = $wpdb->get_results( $wpdb->prepare( "SELECT v.id,v.request_id,v.reviewer_id,v.approval_generation,v.decision,v.reason,v.evidence_snapshot,v.created_at FROM {$wpdb->prefix}smc_approval_votes v INNER JOIN {$wpdb->prefix}smc_verification_requests r ON r.id=v.request_id WHERE r.user_id=%d ORDER BY v.id LIMIT %d OFFSET %d", $user_id, $limit + 1, $offset ), ARRAY_A );
		if ( count( $votes ) > $limit ) { $more = true; $votes = array_slice( $votes, 0, $limit ); }
		foreach ( $votes as $index => $row ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-approval-vote-' . $user_id . '-' . ( $offset + $index ), $row ); }
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( $subject_hash ) {
			$audits = $wpdb->get_results( $wpdb->prepare( "SELECT id,action,details,created_at FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s ORDER BY id LIMIT %d OFFSET %d", $subject_hash, $limit + 1, $offset ), ARRAY_A );
			if ( count( $audits ) > $limit ) { $more = true; $audits = array_slice( $audits, 0, $limit ); }
			foreach ( $audits as $index => $row ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-audit-' . $user_id . '-' . ( $offset + $index ), $row ); }
		}
		$meta = get_user_meta( $user_id );
		$advanced = array();
		foreach ( $meta as $key => $values ) {
			if ( 0 !== strpos( $key, '_smc_' ) || preg_match( '/(?:secret|token|receipt|otp|recovery|pending_enc)/i', $key ) ) { continue; }
			$advanced[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}
		if ( 1 === $page && $advanced ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-advanced-trust-' . $user_id, $advanced ); }
		if ( class_exists( 'SMC_Advanced_Trust_2026' ) ) {
			$break_glass = (array) get_option( 'smc_break_glass_requests_v1', array() );
			$subject_break_glass = array();
			foreach ( $break_glass as $request_id => $request ) { if ( is_array( $request ) && absint( $request['subject_user_id'] ?? 0 ) === $user_id ) { $subject_break_glass[ $request_id ] = $request; } }
			if ( 1 === $page && $subject_break_glass ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-break-glass-' . $user_id, $subject_break_glass ); }
		}
		// Evidence bytes are encrypted private files. The ordinary HTML privacy archive exposes their
		// names, MIME, size and authenticated SHA-256 above; binary disclosure is provided only through
		// the separately authorized private-document export path so a multi-megabyte scan is never
		// loaded into a public/background HTML exporter or leaked by email/archive generation.
		return array( 'data'=>$data, 'done'=>! $more );
	}

	private static function item( $group_id, $label, $item_id, $values ) {
		$data = array();
		foreach ( $values as $name => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}
			$data[] = array( 'name' => (string) $name, 'value' => null === $value ? '' : (string) $value );
		}
		return array( 'group_id' => $group_id, 'group_label' => $label, 'item_id' => $item_id, 'data' => $data );
	}

	private static function export_identity( $user_id ) {
		global $wpdb;
		$app = smc_application( $user_id );
		if ( ! $app ) {
			return array();
		}
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
		$phone = SMC_Security::decrypt( $app['phone_e164_enc'], 'membership-phone', array( 'user_id' => $user_id ) );
		$address = ! empty( $app['address_enc'] ) ? SMC_Security::decrypt( $app['address_enc'], 'residential-address', array( 'user_id' => $user_id, 'country' => $app['residence_country'] ?? '' ) ) : '';
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d", $user_id ), ARRAY_A );
		$number = $identity ? SMC_Security::decrypt( $identity['document_number_enc'], 'identity-number', array( 'user_id' => $user_id, 'type' => $identity['document_type'], 'country' => $identity['issuing_country'] ) ) : '';
		$values = array(
			__( 'Legal name', 'sabri-membership-core' ) => $app['legal_name'],
			__( 'Date of birth', 'sabri-membership-core' ) => is_wp_error( $dob ) ? __( 'Unavailable', 'sabri-membership-core' ) : $dob,
			__( 'Gender rule', 'sabri-membership-core' ) => $app['gender'],
			__( 'Residence country', 'sabri-membership-core' ) => $app['residence_country'] ?? '',
			__( 'City', 'sabri-membership-core' ) => $app['city'] ?? '',
			__( 'Private address', 'sabri-membership-core' ) => is_wp_error( $address ) ? __( 'Unavailable', 'sabri-membership-core' ) : $address,
			__( 'Phone', 'sabri-membership-core' ) => is_wp_error( $phone ) ? __( 'Unavailable', 'sabri-membership-core' ) : $phone,
			__( 'Requested membership roles', 'sabri-membership-core' ) => implode( ', ', SMC_Contracts::requested_types( $user_id ) ),
			__( 'Approved membership roles', 'sabri-membership-core' ) => implode( ', ', SMC_Contracts::approved_types( $user_id ) ),
			__( 'Primary compatibility type', 'sabri-membership-core' ) => $app['membership_type'],
			__( 'Status', 'sabri-membership-core' ) => $app['status'],
			__( 'Guardian required', 'sabri-membership-core' ) => $app['guardian_required'],
			__( 'Profile visibility assertion', 'sabri-membership-core' ) => $app['profile_visibility'],
			__( 'Policy version', 'sabri-membership-core' ) => $app['policy_version'],
			__( 'Identity type', 'sabri-membership-core' ) => $identity['document_type'] ?? '',
			__( 'Identity number', 'sabri-membership-core' ) => is_wp_error( $number ) ? __( 'Unavailable', 'sabri-membership-core' ) : $number,
			__( 'Identity issuing country', 'sabri-membership-core' ) => $identity['issuing_country'] ?? '',
			__( 'Identity match status', 'sabri-membership-core' ) => $identity['name_match_status'] ?? '',
		);
		return array( self::item( 'smc-identity', __( 'Membership Identity', 'sabri-membership-core' ), 'smc-identity-' . $user_id, $values ) );
	}

	private static function export_evidence( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT document_key,label,original_name,mime_type,file_size,plain_sha256,scan_status,status,issue_date,expiry_date,reviewed_at,reviewer_note,created_at,updated_at FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d ORDER BY id", $user_id ), ARRAY_A );
		$data = array();
		foreach ( $rows as $index => $row ) {
			$data[] = self::item( 'smc-evidence', __( 'Membership Evidence Metadata', 'sabri-membership-core' ), 'smc-evidence-' . $user_id . '-' . $index, $row );
		}
		return $data;
	}

	private static function export_workflow( $user_id ) {
		global $wpdb;
		$data = array();
		foreach ( array( 'smc_verification_requests', 'smc_verification_events', 'smc_consents', 'smc_role_grants', 'smc_application_repairs' ) as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table} WHERE user_id=%d ORDER BY id", $user_id ), ARRAY_A );
			foreach ( $rows as $index => $row ) {
				foreach ( array_keys( $row ) as $key ) {
					if ( false !== strpos( $key, '_enc' ) || false !== strpos( $key, '_hash' ) || false !== strpos( $key, 'otp_' ) || 'invitation_token_hash' === $key ) {
						unset( $row[ $key ] );
					}
				}
				$data[] = self::item( 'smc-workflow', __( 'Membership Workflow and Consent', 'sabri-membership-core' ), $table . '-' . $user_id . '-' . $index, $row );
			}
		}
		$guardian = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d", $user_id ), ARRAY_A );
		if ( $guardian ) {
			$context = array( 'user_id' => $user_id );
			$name = SMC_Security::decrypt( $guardian['guardian_name_enc'], 'guardian-name', $context );
			$email = SMC_Security::decrypt( $guardian['guardian_email_enc'], 'guardian-email', $context );
			$phone = SMC_Security::decrypt( $guardian['guardian_phone_enc'], 'guardian-phone', $context );
			$data[] = self::item(
				'smc-workflow',
				__( 'Membership Workflow and Consent', 'sabri-membership-core' ),
				'smc-guardian-' . $user_id,
				array(
					__( 'Guardian name', 'sabri-membership-core' ) => is_wp_error( $name ) ? __( 'Unavailable', 'sabri-membership-core' ) : $name,
					__( 'Guardian email', 'sabri-membership-core' ) => is_wp_error( $email ) ? __( 'Unavailable', 'sabri-membership-core' ) : $email,
					__( 'Guardian phone', 'sabri-membership-core' ) => is_wp_error( $phone ) ? __( 'Unavailable', 'sabri-membership-core' ) : $phone,
					__( 'Relationship', 'sabri-membership-core' ) => $guardian['relationship'],
					__( 'Consent status', 'sabri-membership-core' ) => $guardian['status'],
					__( 'Consent text', 'sabri-membership-core' ) => $guardian['consent_text'],
					__( 'Policy version', 'sabri-membership-core' ) => $guardian['policy_version'],
					__( 'Requested at', 'sabri-membership-core' ) => $guardian['requested_at'],
					__( 'Verified at', 'sabri-membership-core' ) => $guardian['verified_at'],
					__( 'Withdrawn at', 'sabri-membership-core' ) => $guardian['withdrawn_at'],
				)
			);
		}
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( $subject_hash ) {
			$audits = $wpdb->get_results( $wpdb->prepare( "SELECT action,details,created_at FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s ORDER BY id", $subject_hash ), ARRAY_A );
			foreach ( $audits as $index => $audit ) {
				$data[] = self::item( 'smc-workflow', __( 'Membership Workflow and Consent', 'sabri-membership-core' ), 'smc-audit-' . $user_id . '-' . $index, $audit );
			}
		}
		return $data;
	}

	private static function export_security( $user_id ) {
		global $wpdb;
		$sessions = $wpdb->get_results( $wpdb->prepare( "SELECT expires_at,two_factor_at,ip_hash,device_hash,revoked_at,created_at,updated_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d ORDER BY id", $user_id ), ARRAY_A );
		$session_count = count( $sessions );
		$recovery_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d AND consumed_at IS NULL", $user_id ) );
		$values = array(
			__( 'Email ownership verified', 'sabri-membership-core' ) => SMC_Contracts::contact_verified( $user_id, 'email' ),
			__( 'Mobile ownership verified', 'sabri-membership-core' ) => SMC_Contracts::contact_verified( $user_id, 'mobile' ),
			__( 'Two-factor enabled', 'sabri-membership-core' ) => SMC_Security::two_factor_ready( $user_id ),
			__( 'Active membership sessions', 'sabri-membership-core' ) => $session_count,
			__( 'Unused recovery codes', 'sabri-membership-core' ) => $recovery_count,
		);
		$data = array( self::item( 'smc-security', __( 'Membership Security Summary', 'sabri-membership-core' ), 'smc-security-' . $user_id, $values ) );
		foreach ( $sessions as $index => $session ) {
			$data[] = self::item( 'smc-security', __( 'Membership Security Summary', 'sabri-membership-core' ), 'smc-session-' . $user_id . '-' . $index, $session );
		}
		return $data;
	}

	public static function register_eraser( $erasers ) {
		$erasers['sabri-membership-core'] = array(
			'eraser_friendly_name' => __( 'Sabri Membership Core Data', 'sabri-membership-core' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	private static function lock_for_erasure( $user_id ) {
		$lock = smc_privacy_erasure_lock( $user_id );
		if ( ! $lock ) {
			$now = current_time( 'mysql', true );
			$receipt = substr( hash_hmac( 'sha256', $user_id . '|' . $now . '|' . wp_generate_uuid4(), wp_salt( 'auth' ) ), 0, 32 );
			$lock = array(
				'version'        => 2,
				'locked_at'      => $now,
				'receipt'        => $receipt,
				'containment_at' => '',
			);
			update_user_meta( $user_id, '_smc_privacy_erasure_lock', $lock );
			$stored = smc_privacy_erasure_lock( $user_id );
			if ( ! $stored || ! hash_equals( $receipt, (string) ( $stored['receipt'] ?? '' ) ) ) {
				return new WP_Error( 'smc_erasure_lock_failed', __( 'The account could not be placed into a fail-closed erasure state.', 'sabri-membership-core' ) );
			}
			$lock = $stored;
		}
		if ( ! empty( $lock['containment_at'] ) ) {
			return $lock;
		}
		$receipt = (string) ( $lock['receipt'] ?? '' );
		if ( '' === $receipt ) {
			return new WP_Error( 'smc_erasure_receipt_missing', __( 'The fail-closed erasure lock is incomplete and requires operator repair.', 'sabri-membership-core' ) );
		}
		$sessions_ok = SMC_Security::revoke_all_sessions( $user_id, 'privacy_erasure_locked' );
		$audit_ok = $sessions_ok && SMC_Security::audit( 'privacy_erasure_started', $user_id, array( 'receipt' => $receipt ) );
		if ( ! $sessions_ok || ! $audit_ok ) {
			return new WP_Error( 'smc_erasure_containment_incomplete', __( 'Erasure is locked fail-closed, but containment evidence requires retry.', 'sabri-membership-core' ) );
		}
		$lock['version'] = 2;
		$lock['containment_at'] = current_time( 'mysql', true );
		update_user_meta( $user_id, '_smc_privacy_erasure_lock', $lock );
		$stored = smc_privacy_erasure_lock( $user_id );
		if ( ! $stored || empty( $stored['containment_at'] ) || ! hash_equals( $receipt, (string) ( $stored['receipt'] ?? '' ) ) ) {
			return new WP_Error( 'smc_erasure_containment_state', __( 'Erasure containment succeeded, but its durable state could not be confirmed.', 'sabri-membership-core' ) );
		}
		return $stored;
	}

	public static function erase( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$user_id = $user->ID;
		$hold = self::active_hold( $user_id );
		if ( $hold ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( sprintf( __( 'Erasure is paused by a documented %s retention hold until the hold is released or expires.', 'sabri-membership-core' ), $hold['hold_type'] ) ),
				'done'           => true,
			);
		}
		$lock = self::lock_for_erasure( $user_id );
		if ( is_wp_error( $lock ) ) {
			return array( 'items_removed' => false, 'items_retained' => true, 'messages' => array( $lock->get_error_message() ), 'done' => false );
		}
		$page = max( 1, absint( $page ) );
		$file_result = self::erase_files( $user_id );
		if ( ! empty( $file_result['pending'] ) ) {
			unset( $file_result['pending'] );
			return $file_result;
		}
		return self::erase_records( $user_id, $lock );
	}

	private static function erase_files( $user_id ) {
		global $wpdb;
		$dir = SMC_Security::private_dir();
		if ( is_wp_error( $dir ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( $dir->get_error_message() ),
				'done'           => false,
				'pending'        => true,
			);
		}
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d ORDER BY id LIMIT 50", $user_id ), ARRAY_A );
		$removed = false;
		$retained = false;
		$messages = array();
		foreach ( $docs as $doc ) {
			$source = trailingslashit( $dir ) . basename( $doc['stored_name'] );
			$quarantine = $source . '.erase-' . wp_generate_uuid4();
			if ( is_file( $source ) && ! is_link( $source ) ) {
				if ( ! @rename( $source, $quarantine ) ) {
					SMC_Security::queue_file_job( basename( $source ), 'privacy_delete', $doc['lease_id'], 'quarantine_rename_failed' );
					$retained = true;
					$messages[] = __( 'One encrypted evidence file could not be quarantined; its database reference was preserved and deletion was queued.', 'sabri-membership-core' );
					continue;
				}
				@chmod( $quarantine, 0600 );
			}
			if ( is_file( $quarantine ) && ! SMC_Security::verified_unlink( $quarantine ) ) {
				if ( ! is_file( $source ) ) {
					@rename( $quarantine, $source );
				}
				SMC_Security::queue_file_job( basename( is_file( $source ) ? $source : $quarantine ), 'privacy_delete', $doc['lease_id'], 'verified_delete_failed' );
				$retained = true;
				$messages[] = __( 'One encrypted evidence file could not be verified as deleted; its record was retained for recovery and retry.', 'sabri-membership-core' );
				continue;
			}
			if ( false === $wpdb->delete( $wpdb->prefix . 'smc_identity_documents', array( 'id' => (int) $doc['id'] ), array( '%d' ) ) ) {
				$retained = true;
				$messages[] = __( 'Evidence bytes were deleted, but a metadata row could not be removed and remains queued for operator review.', 'sabri-membership-core' );
				continue;
			}
			SMC_Security::verified_unlink( $source . '.lease' );
			$removed = true;
		}
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d", $user_id ) );
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained || $remaining > 0,
			'messages'       => $messages,
			'done'           => false,
			'pending'        => $remaining > 0,
		);
	}

	private static function erase_records( $user_id, $lock ) {
		global $wpdb;
		$tables = array(
			'smc_contact_otps'=>'user_id', 'smc_mfa_factor_state'=>'user_id', 'smc_recovery_codes'=>'user_id',
			'smc_auth_sessions'=>'user_id', 'smc_verification_events'=>'user_id', 'smc_verification_requests'=>'user_id',
			'smc_consents'=>'user_id', 'smc_role_grants'=>'user_id', 'smc_application_repairs'=>'user_id',
			'smc_guardian_consents'=>'user_id', 'smc_identity_records'=>'user_id', 'smc_retention_holds'=>'user_id',
			'smc_applications'=>'user_id',
		);
		$failed = array();
		$subject_hash = SMC_Security::subject_hash( $user_id );
		$wpdb->query( 'START TRANSACTION' );
		$request_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d", $user_id ) );
		if ( $request_ids ) {
			$ids = implode( ',', array_map( 'absint', $request_ids ) );
			if ( false === $wpdb->query( "DELETE FROM {$wpdb->prefix}smc_approval_votes WHERE request_id IN ({$ids})" ) ) { $failed[]='approval_votes'; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $subject_hash ) {
			$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}smc_event_outbox WHERE subject_hash=%s", $subject_hash ) );
			if ( $event_ids ) { foreach ( $event_ids as $event_id ) { if ( false === $wpdb->delete( $wpdb->prefix . 'smc_event_inbox', array( 'event_id'=>(string)$event_id ), array('%s') ) ) { $failed[]='event_inbox'; break; } } }
			if ( false === $wpdb->delete( $wpdb->prefix . 'smc_event_outbox', array( 'subject_hash'=>$subject_hash ), array('%s') ) ) { $failed[]='event_outbox'; }
		}
		foreach ( $tables as $table=>$column ) { if ( false === $wpdb->delete( $wpdb->prefix . $table, array( $column=>$user_id ), array('%d') ) ) { $failed[]=$table; } }
		$preserved_meta = array( '_smc_privacy_erasure_lock' );
		foreach ( array_keys( get_user_meta( $user_id ) ) as $key ) {
			if ( 0 === strpos( $key, '_smc_' ) && ! in_array( $key, $preserved_meta, true ) && ! delete_user_meta( $user_id, $key ) && metadata_exists( 'user', $user_id, $key ) ) { $failed[]='meta:' . $key; }
		}
		if ( $failed ) { $wpdb->query('ROLLBACK'); clean_user_cache($user_id); return array('items_removed'=>false,'items_retained'=>true,'messages'=>array(sprintf(__( 'Membership erasure remains fail-closed and will retry because atomic deletion failed: %s','sabri-membership-core'),implode(', ',$failed))),'done'=>false); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return array('items_removed'=>false,'items_retained'=>true,'messages'=>array(__( 'Membership erasure commit failed and requires retry.','sabri-membership-core')),'done'=>false); }
		$user = get_userdata( $user_id );
		$wp_ok = true;
		if ( $user ) {
			foreach ( smc_managed_roles() as $role ) { $user->remove_role( $role ); }
			foreach ( array( 'smc_review_verification','smc_view_private_documents','smc_finalize_verification','smc_manage_membership','smc_manage_retention_holds','smc_restore_membership','smc_manage_repairs','smc_configure_institutional_ai','smc_ai_generate_educational_content','smc_ai_submit_educational_content' ) as $cap ) { $user->remove_cap( $cap ); }
			clean_user_cache( $user_id );
			$fresh = get_userdata( $user_id );
			$wp_ok = $fresh && ! array_intersect( (array) $fresh->roles, smc_managed_roles() );
		}
		$break_glass_ok = ! class_exists('SMC_Advanced_Trust_2026') || SMC_Advanced_Trust_2026::purge_break_glass_subject($user_id);
		if ( ! $wp_ok || ! $break_glass_ok ) { update_user_meta($user_id,'_smc_privacy_erasure_lock',array_merge((array)$lock,array('reason'=>'ancillary_cleanup_pending'))); return array('items_removed'=>true,'items_retained'=>true,'messages'=>array(__( 'Canonical records were erased, but role/capability or ancillary trust cleanup remains fail-closed for retry.','sabri-membership-core')),'done'=>false); }
		$anonymous_receipt = substr( hash( 'sha256', (string)$lock['receipt'] . '|' . current_time('mysql',true) ), 0, 24 );
		$audit_ok = SMC_Security::audit( 'privacy_erasure_completed', 0, array( 'anonymous_receipt'=>$anonymous_receipt,'audit_evidence_retained'=>true ) );
		if ( ! $audit_ok ) { return array('items_removed'=>true,'items_retained'=>true,'messages'=>array(__( 'Erasure completed, but completion audit evidence requires retry.','sabri-membership-core')),'done'=>false); }
		return array('items_removed'=>true,'items_retained'=>true,'messages'=>array(__( 'Membership, identity, guardian, consent, session, role, retention-hold and ancillary trust records were erased. Minimal fail-closed erasure and tamper-evident pseudonymous security evidence remain under policy.','sabri-membership-core')),'done'=>true);
	}

	private static function active_hold( $user_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_retention_holds WHERE user_id=%d AND released_at IS NULL AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Sabri Membership Core', 'sabri-membership-core' ),
			'<p>' . esc_html__( 'This module processes membership eligibility, date of birth, gender-specific minimum-age rules, contact-verification state, encrypted government identity evidence, guardian consent, two-factor security state, verification decisions, and tamper-evident audit records. Identity evidence is scanner-gated, authenticated, encrypted, stored outside the public web root, and restricted to authorized reviewers. Data is retained only for the published membership, legal, security, dispute, and restoration periods. Documented legal or safety holds are reported during erasure. Active membership and identity records are erased through the WordPress privacy process, while a minimal fail-closed erasure lock and unchanged tamper-evident security audit evidence are retained under the published security/legal retention schedule so deleted records cannot silently restore access or corrupt the audit chain.', 'sabri-membership-core' ) . '</p>'
		);
	}
}

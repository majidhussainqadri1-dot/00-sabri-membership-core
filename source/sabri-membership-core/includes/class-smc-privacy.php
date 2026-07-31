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
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$page = max( 1, absint( $page ) );
		$groups = array(
			1 => self::export_identity( $user->ID ),
			2 => self::export_evidence( $user->ID ),
			3 => self::export_workflow( $user->ID ),
			4 => self::export_security( $user->ID ),
		);
		return array(
			'data' => isset( $groups[ $page ] ) ? $groups[ $page ] : array(),
			'done' => $page >= count( $groups ),
		);
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
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d", $user_id ), ARRAY_A );
		$number = $identity ? SMC_Security::decrypt( $identity['document_number_enc'], 'identity-number', array( 'user_id' => $user_id, 'type' => $identity['document_type'], 'country' => $identity['issuing_country'] ) ) : '';
		$values = array(
			__( 'Legal name', 'sabri-membership-core' ) => $app['legal_name'],
			__( 'Date of birth', 'sabri-membership-core' ) => is_wp_error( $dob ) ? __( 'Unavailable', 'sabri-membership-core' ) : $dob,
			__( 'Gender rule', 'sabri-membership-core' ) => $app['gender'],
			__( 'Phone', 'sabri-membership-core' ) => is_wp_error( $phone ) ? __( 'Unavailable', 'sabri-membership-core' ) : $phone,
			__( 'Membership type', 'sabri-membership-core' ) => $app['membership_type'],
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
		foreach ( array( 'smc_verification_requests', 'smc_verification_events', 'smc_consents' ) as $table ) {
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
		$page = max( 1, absint( $page ) );
		$file_result = self::erase_files( $user_id );
		if ( ! empty( $file_result['pending'] ) ) {
			unset( $file_result['pending'] );
			return $file_result;
		}
		return self::erase_records( $user_id );
	}

	private static function erase_files( $user_id ) {
		global $wpdb;
		$dir = SMC_Security::private_dir();
		if ( is_wp_error( $dir ) ) {
			return array( 'items_removed' => false, 'items_retained' => true, 'messages' => array( $dir->get_error_message() ), 'done' => true );
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

	private static function erase_records( $user_id ) {
		global $wpdb;
		$receipt = SMC_Security::subject_hash( $user_id );
		$tables = array(
			'smc_contact_otps'          => 'user_id',
			'smc_recovery_codes'        => 'user_id',
			'smc_auth_sessions'         => 'user_id',
			'smc_approval_votes'        => null,
			'smc_verification_events'   => 'user_id',
			'smc_verification_requests' => 'user_id',
			'smc_consents'              => 'user_id',
			'smc_guardian_consents'     => 'user_id',
			'smc_identity_records'      => 'user_id',
			'smc_applications'          => 'user_id',
		);
		$failed = array();
		$request_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d", $user_id ) );
		if ( $request_ids ) {
			$ids = implode( ',', array_map( 'absint', $request_ids ) );
			if ( false === $wpdb->query( "DELETE FROM {$wpdb->prefix}smc_approval_votes WHERE request_id IN ({$ids})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$failed[] = 'approval_votes';
			}
		}
		foreach ( $tables as $table => $column ) {
			if ( null === $column ) {
				continue;
			}
			if ( false === $wpdb->delete( $wpdb->prefix . $table, array( $column => $user_id ), array( '%d' ) ) ) {
				$failed[] = $table;
			}
		}
		$meta = get_user_meta( $user_id );
		foreach ( array_keys( $meta ) as $key ) {
			if ( 0 === strpos( $key, '_smc_' ) && ! delete_user_meta( $user_id, $key ) && metadata_exists( 'user', $user_id, $key ) ) {
				$failed[] = 'meta:' . $key;
			}
		}
		$subject_hash = SMC_Security::subject_hash( $user_id );
		if ( $subject_hash && false === $wpdb->delete( $wpdb->prefix . 'smc_audit_log', array( 'subject_hash' => $subject_hash ), array( '%s' ) ) ) {
			$failed[] = 'audit';
		}
		SMC_Security::audit( 'privacy_erasure_completed', 0, array( 'anonymous_receipt' => substr( hash( 'sha256', $receipt . '|' . current_time( 'mysql', true ) ), 0, 24 ) ) );
		return array(
			'items_removed'  => empty( $failed ),
			'items_retained' => ! empty( $failed ),
			'messages'       => empty( $failed ) ? array() : array( sprintf( __( 'Some membership records remain because deletion failed: %s', 'sabri-membership-core' ), implode( ', ', $failed ) ) ),
			'done'           => true,
		);
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
			'<p>' . esc_html__( 'This module processes membership eligibility, date of birth, gender-specific minimum-age rules, contact-verification state, encrypted government identity evidence, guardian consent, two-factor security state, verification decisions, and tamper-evident audit records. Identity evidence is scanner-gated, authenticated, encrypted, stored outside the public web root, and restricted to authorized reviewers. Data is retained only for the published membership, legal, security, dispute, and restoration periods. Documented legal or safety holds are reported during erasure; otherwise personal membership data and identifying audit links are removed through the WordPress privacy process.', 'sabri-membership-core' ) . '</p>'
		);
	}
}

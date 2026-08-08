#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('apply.py')
text = path.read_text(encoding='utf-8')
start_marker = "# ---------------------------------------------------------------------------\n# Privacy: erasure removes managed WP roles/caps, ancillary holds/break-glass;"
end_marker = "# ---------------------------------------------------------------------------\n# Private file orphan job removes its authenticated companion lease."
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('privacyfix: privacy patch section markers not found')
section = r'''# ---------------------------------------------------------------------------
# Privacy: cursor-bounded complete export and complete membership erasure graph.
# ---------------------------------------------------------------------------
privacy = "source/sabri-membership-core/includes/class-smc-privacy.php"
replace_function(
    privacy, "public", "export", "private", "item",
    r'''	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data'=>array(), 'done'=>true ); }
		global $wpdb;
		$user_id = (int) $user->ID;
		$page = max( 1, absint( $page ) );
		$limit = 100;
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
		$request_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d", $user_id ) );
		if ( $request_ids ) {
			$ids = implode( ',', array_map( 'absint', $request_ids ) );
			$votes = $wpdb->get_results( $wpdb->prepare( "SELECT id,request_id,reviewer_id,approval_generation,decision,reason,evidence_snapshot,created_at FROM {$wpdb->prefix}smc_approval_votes WHERE request_id IN ({$ids}) ORDER BY id LIMIT %d OFFSET %d", $limit + 1, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( count( $votes ) > $limit ) { $more = true; $votes = array_slice( $votes, 0, $limit ); }
			foreach ( $votes as $index => $row ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-approval-vote-' . $user_id . '-' . ( $offset + $index ), $row ); }
		}
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
			$break_glass = (array) get_option( SMC_Advanced_Trust_2026::BREAK_GLASS_OPTION, array() );
			$subject_break_glass = array();
			foreach ( $break_glass as $request_id => $request ) { if ( is_array( $request ) && absint( $request['subject_user_id'] ?? 0 ) === $user_id ) { $subject_break_glass[ $request_id ] = $request; } }
			if ( 1 === $page && $subject_break_glass ) { $data[] = self::item( 'smc-personal-data', __( 'Membership Personal Data', 'sabri-membership-core' ), 'smc-break-glass-' . $user_id, $subject_break_glass ); }
		}
		// Evidence bytes are encrypted private files. The ordinary HTML privacy archive exposes their
		// names, MIME, size and authenticated SHA-256 above; binary disclosure is provided only through
		// the separately authorized private-document export path so a multi-megabyte scan is never
		// loaded into a public/background HTML exporter or leaked by email/archive generation.
		return array( 'data'=>$data, 'done'=>! $more );
	}'''
)
replace_function(
    privacy, "private", "erase_records", "private", "active_hold",
    r'''	private static function erase_records( $user_id, $lock ) {
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
	}'''
)

'''
text = text[:start] + section + text[end:]
path.write_text(text, encoding='utf-8', newline='\n')
print('audit32 privacy patch aligned and strengthened')

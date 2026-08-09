<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_smc_review_transition', array( __CLASS__, 'handle_transition' ) );
		add_action( 'admin_post_smc_review_document', array( __CLASS__, 'handle_document' ) );
		add_action( 'admin_post_smc_assign_review', array( __CLASS__, 'handle_assignment' ) );
		add_action( 'admin_post_smc_declare_conflict', array( __CLASS__, 'handle_conflict' ) );
		add_action( 'admin_post_smc_save_founder', array( __CLASS__, 'save_founder' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Sabri Membership', 'sabri-membership-core' ), __( 'Membership', 'sabri-membership-core' ), 'smc_review_verification', 'smc-membership', array( __CLASS__, 'queue' ), 'dashicons-id-alt', 3 );
		add_submenu_page( 'smc-membership', __( 'Verification Queue', 'sabri-membership-core' ), __( 'Verification Queue', 'sabri-membership-core' ), 'smc_review_verification', 'smc-membership', array( __CLASS__, 'queue' ) );
		add_submenu_page( 'smc-membership', __( 'Audit Integrity', 'sabri-membership-core' ), __( 'Audit Integrity', 'sabri-membership-core' ), 'smc_manage_membership', 'smc-audit', array( __CLASS__, 'audit_page' ) );
		add_submenu_page( 'smc-membership', __( 'Membership Settings', 'sabri-membership-core' ), __( 'Settings', 'sabri-membership-core' ), 'manage_options', 'smc-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! SMC_Security::key_ready() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Sabri Membership is fail-closed: its authenticated-encryption/key subsystem is not ready. Ensure OpenSSL AES-256-GCM is available and either allow the protected managed keyring to provision or configure backed-up SMC_MASTER_KEY and SMC_MASTER_KEY_ID constants.', 'sabri-membership-core' ) . '</p></div>';
		}
		$dir = SMC_Security::key_ready() ? SMC_Security::private_dir() : null;
		if ( is_wp_error( $dir ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $dir->get_error_message() ) . '</p></div>';
		}
		if ( ! smc_founder_user_id() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No official Founder user ID is configured. File 00 will never guess or rename an administrator; configure the exact account under Membership Settings or SMC_FOUNDER_USER_ID.', 'sabri-membership-core' ) . '</p></div>';
		}
		$failure = get_option( 'smc_last_migration_failure', array() );
		if ( ! empty( $failure['message'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Membership migration requires attention: %s', 'sabri-membership-core' ), $failure['message'] ) ) . '</p></div>';
		}
	}

	public static function queue() {
		if ( ! current_user_can( 'smc_review_verification' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) ) {
			wp_die( esc_html__( 'A current authorized reviewer session is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( $user_id ) {
			self::review_screen( $user_id );
			return;
		}
		global $wpdb;
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset = ( $page - 1 ) * 50;
		$status = sanitize_key( wp_unslash( $_GET['status_filter'] ?? '' ) );
		$queue_type = sanitize_key( wp_unslash( $_GET['queue_filter'] ?? '' ) );
		$assignment = sanitize_key( wp_unslash( $_GET['assignment_filter'] ?? '' ) );
		$overdue = ! empty( $_GET['overdue'] );
		$where = array( '1=1' );
		$args = array();
		if ( isset( smc_statuses()[ $status ] ) ) { $where[] = 'r.status=%s'; $args[] = $status; }
		if ( isset( smc_review_queue_types()[ $queue_type ] ) ) { $where[] = 'r.queue_type=%s'; $args[] = $queue_type; }
		if ( 'mine' === $assignment ) { $where[] = 'r.assigned_reviewer=%d'; $args[] = get_current_user_id(); }
		elseif ( 'unassigned' === $assignment ) { $where[] = 'r.assigned_reviewer=0'; }
		if ( $overdue ) { $where[] = "r.sla_due_at IS NOT NULL AND r.sla_due_at<UTC_TIMESTAMP()"; }
		$sql = "SELECT r.*,a.legal_name,a.membership_type,a.guardian_required,u.user_email,
			(SELECT COUNT(*) FROM {$wpdb->prefix}smc_identity_documents d WHERE d.user_id=r.user_id AND d.scan_status='passed') AS evidence_count,
			(SELECT COUNT(*) FROM {$wpdb->prefix}smc_identity_documents d2 WHERE d2.user_id=r.user_id AND d2.status='approved' AND d2.scan_status='passed' AND (d2.expiry_date IS NULL OR d2.expiry_date>=UTC_DATE())) AS approved_evidence_count
			FROM {$wpdb->prefix}smc_verification_requests r
			JOIN {$wpdb->prefix}smc_applications a ON a.user_id=r.user_id
			JOIN {$wpdb->users} u ON u.ID=r.user_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY (r.sla_due_at IS NOT NULL AND r.sla_due_at<UTC_TIMESTAMP()) DESC,FIELD(r.status,'appeal_review','submitted','resubmitted','guardian_pending','under_review','approval_pending','more_information','suspended','rejected','approved'),r.updated_at ASC
			LIMIT 50 OFFSET %d";
		$args[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Verification Queue', 'sabri-membership-core' ) . '</h1><p>' . esc_html__( 'Identity membership only. Doctor credentials and public professional profiles remain owned by Files 09 and 03.', 'sabri-membership-core' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="smc-membership"><label>' . esc_html__( 'Status', 'sabri-membership-core' ) . ' <select name="status_filter"><option value="">' . esc_html__( 'All', 'sabri-membership-core' ) . '</option>';
		foreach ( smc_statuses() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'Queue', 'sabri-membership-core' ) . ' <select name="queue_filter"><option value="">' . esc_html__( 'All', 'sabri-membership-core' ) . '</option>';
		foreach ( smc_review_queue_types() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $queue_type, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'Assignment', 'sabri-membership-core' ) . ' <select name="assignment_filter"><option value="">' . esc_html__( 'All', 'sabri-membership-core' ) . '</option><option value="mine" ' . selected( $assignment, 'mine', false ) . '>' . esc_html__( 'Mine', 'sabri-membership-core' ) . '</option><option value="unassigned" ' . selected( $assignment, 'unassigned', false ) . '>' . esc_html__( 'Unassigned', 'sabri-membership-core' ) . '</option></select></label> <label><input type="checkbox" name="overdue" value="1" ' . checked( $overdue, true, false ) . '> ' . esc_html__( 'Overdue only', 'sabri-membership-core' ) . '</label> <button class="button">' . esc_html__( 'Filter', 'sabri-membership-core' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Applicant', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Queue / status', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Evidence', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'SLA', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Reviewer / conflict', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Trace', 'sabri-membership-core' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg( array( 'page' => 'smc-membership', 'user_id' => (int) $row['user_id'] ), admin_url( 'admin.php' ) );
			$overdue_row = ! empty( $row['sla_due_at'] ) && strtotime( $row['sla_due_at'] . ' UTC' ) < time();
			echo '<tr><td><strong>' . esc_html( $row['legal_name'] ) . '</strong><br><small>' . esc_html( $row['user_email'] ) . '<br>' . esc_html( implode( ', ', array_map( static function ( $type ) { return smc_account_types()[ $type ] ?? $type; }, SMC_Contracts::requested_types( $row['user_id'] ) ) ) ) . '</small></td><td>' . esc_html( smc_review_queue_types()[ $row['queue_type'] ] ?? $row['queue_type'] ) . '<br><strong>' . esc_html( smc_statuses()[ $row['status'] ] ?? $row['status'] ) . '</strong></td><td>' . absint( $row['approved_evidence_count'] ) . '/' . absint( $row['evidence_count'] ) . '</td><td>' . ( $overdue_row ? '<strong>' . esc_html__( 'OVERDUE', 'sabri-membership-core' ) . '</strong><br>' : '' ) . esc_html( $row['sla_due_at'] ?: '—' ) . '</td><td>' . esc_html( $row['assigned_reviewer'] ? get_the_author_meta( 'display_name', $row['assigned_reviewer'] ) : __( 'Unassigned', 'sabri-membership-core' ) ) . '<br>' . esc_html( $row['conflict_status'] ) . '</td><td><code>' . esc_html( $row['trace_id'] ?: '—' ) . '</code></td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Review', 'sabri-membership-core' ) . '</a>';
			if ( ! $row['assigned_reviewer'] ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="smc_assign_review"><input type="hidden" name="request_id" value="' . absint( $row['id'] ) . '">'; wp_nonce_field( 'smc_assign_review_' . $row['id'], 'smc_nonce' ); echo '<button class="button">' . esc_html__( 'Claim', 'sabri-membership-core' ) . '</button></form>'; }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="7">' . esc_html__( 'No requests match the current filters.', 'sabri-membership-core' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	private static function review_screen( $user_id ) {
		global $wpdb;
		$app = smc_application( $user_id );
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
		$guardian = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
		$docs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d ORDER BY document_key", $user_id ), ARRAY_A );
		$user = get_userdata( $user_id );
		if ( ! $app || ! $request || ! $user ) {
			wp_die( esc_html__( 'Membership request not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );
		}
		$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
		$address = ! empty( $app['address_enc'] ) ? SMC_Security::decrypt( $app['address_enc'], 'residential-address', array( 'user_id' => $user_id, 'country' => $app['residence_country'] ?? '' ) ) : '';
		$number = $identity ? SMC_Security::decrypt( $identity['document_number_enc'], 'identity-number', array( 'user_id' => $user_id, 'type' => $identity['document_type'], 'country' => $identity['issuing_country'] ) ) : '';
		$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		$a = SMC_Contracts::assertions( $user_id );
		$requested_role_labels = array_map( static function ( $type ) { return smc_account_types()[ $type ] ?? $type; }, SMC_Contracts::requested_types( $user_id ) );
		echo '<div class="wrap"><h1>' . esc_html( sprintf( __( 'Review: %s', 'sabri-membership-core' ), $app['legal_name'] ) ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=smc-membership' ) ) . '">← ' . esc_html__( 'Back to queue', 'sabri-membership-core' ) . '</a></p>';
		echo '<div class="card" style="max-width:1100px"><h2>' . esc_html__( 'Assignment, SLA and Conflict Governance', 'sabri-membership-core' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Queue', 'sabri-membership-core' ) . ':</strong> ' . esc_html( smc_review_queue_types()[ $request['queue_type'] ] ?? $request['queue_type'] ) . ' &nbsp; <strong>' . esc_html__( 'SLA due', 'sabri-membership-core' ) . ':</strong> ' . esc_html( $request['sla_due_at'] ?: '—' ) . ' &nbsp; <strong>' . esc_html__( 'Trace', 'sabri-membership-core' ) . ':</strong> <code>' . esc_html( $request['trace_id'] ?: '—' ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Assigned reviewer', 'sabri-membership-core' ) . ':</strong> ' . esc_html( $request['assigned_reviewer'] ? get_the_author_meta( 'display_name', $request['assigned_reviewer'] ) : __( 'Unassigned', 'sabri-membership-core' ) ) . ' &nbsp; <strong>' . esc_html__( 'Conflict declaration', 'sabri-membership-core' ) . ':</strong> ' . esc_html( $request['conflict_status'] ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_assign_review"><input type="hidden" name="request_id" value="' . absint( $request['id'] ) . '">'; wp_nonce_field( 'smc_assign_review_' . $request['id'], 'smc_nonce' ); echo '<button class="button">' . esc_html__( 'Assign this review to me', 'sabri-membership-core' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_declare_conflict"><input type="hidden" name="request_id" value="' . absint( $request['id'] ) . '">'; wp_nonce_field( 'smc_declare_conflict_' . $request['id'], 'smc_nonce' ); echo '<label>' . esc_html__( 'Conflict status', 'sabri-membership-core' ) . ' <select name="conflict_status" required><option value="none">' . esc_html__( 'No conflict', 'sabri-membership-core' ) . '</option><option value="conflict">' . esc_html__( 'Conflict exists — recuse me', 'sabri-membership-core' ) . '</option></select></label> <label>' . esc_html__( 'Note', 'sabri-membership-core' ) . ' <input name="conflict_note" maxlength="500"></label> <button class="button">' . esc_html__( 'Record declaration', 'sabri-membership-core' ) . '</button></form></div>';
		echo '<div class="card" style="max-width:1100px"><h2>' . esc_html__( 'Eligibility Snapshot', 'sabri-membership-core' ) . '</h2><table class="widefat striped"><tbody>';
		$facts = array(
			__( 'Legal name', 'sabri-membership-core' ) => $app['legal_name'],
			__( 'Email', 'sabri-membership-core' ) => $user->user_email,
			__( 'Requested membership roles', 'sabri-membership-core' ) => implode( ', ', $requested_role_labels ),
			__( 'Application version', 'sabri-membership-core' ) => (int) $request['applicant_version'],
			__( 'Date of birth', 'sabri-membership-core' ) => is_wp_error( $dob ) ? __( 'Decryption failed', 'sabri-membership-core' ) : $dob,
			__( 'Current calculated age', 'sabri-membership-core' ) => false === $age ? __( 'Invalid', 'sabri-membership-core' ) : $age,
			__( 'Gender rule', 'sabri-membership-core' ) => smc_allowed_genders()[ $app['gender'] ] ?? __( 'Invalid', 'sabri-membership-core' ),
			__( 'Residence country', 'sabri-membership-core' ) => $app['residence_country'] ?? '',
			__( 'City', 'sabri-membership-core' ) => $app['city'] ?? '',
			__( 'Private address', 'sabri-membership-core' ) => is_wp_error( $address ) ? __( 'Decryption failed', 'sabri-membership-core' ) : $address,
			__( 'Identity document', 'sabri-membership-core' ) => is_wp_error( $number ) ? __( 'Decryption failed', 'sabri-membership-core' ) : $number,
			__( 'Guardian consent', 'sabri-membership-core' ) => $app['guardian_required'] ? ( $guardian['status'] ?? __( 'Missing', 'sabri-membership-core' ) ) : __( 'Not required', 'sabri-membership-core' ),
			__( 'Email ownership', 'sabri-membership-core' ) => $a['email_verified'] ? __( 'Verified', 'sabri-membership-core' ) : __( 'Pending', 'sabri-membership-core' ),
			__( 'Mobile ownership', 'sabri-membership-core' ) => $a['phone_verified'] ? __( 'Verified', 'sabri-membership-core' ) : __( 'Pending', 'sabri-membership-core' ),
			__( 'Two-factor setup', 'sabri-membership-core' ) => $a['two_factor_ready'] ? __( 'Enabled', 'sabri-membership-core' ) : __( 'Incomplete', 'sabri-membership-core' ),
			__( 'Professional owner status', 'sabri-membership-core' ) => $a['professional_verified'] ? __( 'Verified or not required', 'sabri-membership-core' ) : __( 'Pending in canonical professional module', 'sabri-membership-core' ),
		);
		foreach ( $facts as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Authenticated Private Evidence', 'sabri-membership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Key', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Status', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Expiry', 'sabri-membership-core' ) . '</th><th></th><th>' . esc_html__( 'Document decision', 'sabri-membership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $docs as $doc ) {
			echo '<tr><td>' . esc_html( $doc['label'] ) . '</td><td>' . esc_html( $doc['status'] ) . '</td><td>' . esc_html( $doc['expiry_date'] ?: '—' ) . '</td><td><a class="button" href="' . esc_url( SMC_Security::document_url( $doc['id'] ) ) . '">' . esc_html__( 'Download securely', 'sabri-membership-core' ) . '</a></td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_review_document"><input type="hidden" name="document_id" value="' . absint( $doc['id'] ) . '"><input type="hidden" name="document_version" value="' . absint( $doc['version'] ) . '">';
			wp_nonce_field( 'smc_review_document_' . $doc['id'], 'smc_nonce' );
			echo '<select name="decision" required><option value="approved">' . esc_html__( 'Approve', 'sabri-membership-core' ) . '</option><option value="rejected">' . esc_html__( 'Reject', 'sabri-membership-core' ) . '</option></select><input name="note" required placeholder="' . esc_attr__( 'Evidence-specific reason', 'sabri-membership-core' ) . '"><button class="button">' . esc_html__( 'Save', 'sabri-membership-core' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Governed State Transition', 'sabri-membership-core' ) . '</h2><p>' . esc_html__( 'Every decision requires a reason and optimistic row version. Approval also records an explicit legal-name match and may require a second independent reviewer.', 'sabri-membership-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_review_transition"><input type="hidden" name="user_id" value="' . absint( $user_id ) . '"><input type="hidden" name="row_version" value="' . absint( $request['row_version'] ) . '">';
		wp_nonce_field( 'smc_review_transition_' . $request['id'], 'smc_nonce' );
		echo '<p><label>' . esc_html__( 'Decision', 'sabri-membership-core' ) . ' <select name="decision" required><option value="under_review">' . esc_html__( 'Claim / continue review', 'sabri-membership-core' ) . '</option><option value="more_information">' . esc_html__( 'Request more information', 'sabri-membership-core' ) . '</option><option value="approve">' . esc_html__( 'Approve / cast approval vote', 'sabri-membership-core' ) . '</option><option value="reject">' . esc_html__( 'Reject', 'sabri-membership-core' ) . '</option><option value="suspend">' . esc_html__( 'Suspend approved membership', 'sabri-membership-core' ) . '</option><option value="restore">' . esc_html__( 'Restore after successful independent appeal', 'sabri-membership-core' ) . '</option></select></label></p>';
		echo '<p><label>' . esc_html__( 'Reason code', 'sabri-membership-core' ) . ' <select name="reason_code" required><option value="">' . esc_html__( 'Select', 'sabri-membership-core' ) . '</option>'; foreach ( smc_review_reason_codes() as $code => $label ) { echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>'; } echo '</select></label></p>';
		echo '<p><label><input type="checkbox" name="name_match" value="matched"> ' . esc_html__( 'I compared the legal name and authenticated identity evidence and record an exact or adequately explained match.', 'sabri-membership-core' ) . '</label></p><p><label><input type="checkbox" name="confirm_high_risk" value="1"> ' . esc_html__( 'For approve, reject, suspend or restore, I confirm the current applicant version, evidence, conflict declaration and irreversible/downstream effects shown above.', 'sabri-membership-core' ) . '</label></p><p><label>' . esc_html__( 'Mandatory reason', 'sabri-membership-core' ) . '<br><textarea name="reason" required minlength="12" style="width:100%"></textarea></label></p><p>' . esc_html__( 'Approve, reject, suspend and restore require a current two-factor session. Appeals must be decided by an independent reviewer.', 'sabri-membership-core' ) . '</p><button class="button button-primary">' . esc_html__( 'Apply Controlled Decision', 'sabri-membership-core' ) . '</button></form></div></div>';
	}

	public static function handle_document() {
		if ( ! current_user_can( 'smc_review_verification' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) ) { wp_die( esc_html__( 'A current authorized reviewer session is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['document_id'] ?? 0 ); check_admin_referer( 'smc_review_document_' . $id, 'smc_nonce' );
		$version = absint( $_POST['document_version'] ?? 0 ); $decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ); $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		if ( ! in_array( $decision, array( 'approved','rejected' ), true ) || strlen( $note ) < 8 ) { wp_die( esc_html__( 'Invalid document decision.', 'sabri-membership-core' ), '', array( 'response' => 400 ) ); }
		global $wpdb; $wpdb->query( 'START TRANSACTION' );
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		$request = $doc ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1 FOR UPDATE", (int) $doc['user_id'] ), ARRAY_A ) : null;
		$allowed_states = array( 'under_review','approval_pending','resubmitted','submitted' );
		if ( ! $doc ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The evidence record is unavailable.', 'sabri-membership-core' ), '', array( 'response' => 404 ) ); }
		if ( (int) $doc['user_id'] === get_current_user_id() ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'A reviewer cannot decide their own evidence.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		if ( ! $request || (int) $request['assigned_reviewer'] !== get_current_user_id() || 'none' !== $request['conflict_status'] || ! in_array( $request['status'], $allowed_states, true ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'This document is not in your currently assigned no-conflict review.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_identity_documents SET status=%s,reviewed_by=%d,reviewed_at=%s,reviewer_note=%s,updated_at=%s WHERE id=%d AND version=%d AND user_id=%d", $decision, get_current_user_id(), $now, $note, $now, $id, $version, (int) $doc['user_id'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'document_' . $decision, (int) $doc['user_id'], array( 'document_id' => $id, 'version' => $version, 'reason_code' => 'document_' . $decision ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The evidence decision could not be committed with its audit record. Reload the case.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The evidence decision commit failed. Reload the case.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); } self::redirect_review( (int) $doc['user_id'] );
	}

	public static function handle_assignment() {
		if ( ! current_user_can( 'smc_review_verification' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) ) { wp_die( esc_html__( 'A current authorized reviewer session is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['request_id'] ?? 0 ); check_admin_referer( 'smc_assign_review_' . $id, 'smc_nonce' ); global $wpdb; $wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		$claimable_states = array( 'submitted', 'resubmitted', 'under_review', 'approval_pending', 'appeal_review' );
		$already_voted = false;
		if ( $row && 'approval_pending' === (string) $row['status'] && ! empty( $row['approval_generation'] ) ) {
			$already_voted = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_approval_votes WHERE request_id=%d AND approval_generation=%s AND reviewer_id=%d LIMIT 1", (int) $row['id'], (string) $row['approval_generation'], get_current_user_id() ) );
		}
		if ( ! $row || (int) $row['user_id'] === get_current_user_id() || ! in_array( (string) $row['status'], $claimable_states, true ) || $already_voted || ( (int) $row['assigned_reviewer'] && (int) $row['assigned_reviewer'] !== get_current_user_id() ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The review is no longer claimable by this reviewer.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET assigned_reviewer=%d,assigned_at=%s,conflict_status='undeclared',conflict_note=NULL,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d", get_current_user_id(), $now, $now, $id, (int) $row['row_version'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'review_assigned', (int) $row['user_id'], array( 'request_id' => $id, 'reviewer_id' => get_current_user_id() ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The review could not be assigned safely.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The review assignment commit failed.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); } self::redirect_review( (int) $row['user_id'] );
	}

	public static function handle_conflict() {
		if ( ! current_user_can( 'smc_review_verification' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) ) { wp_die( esc_html__( 'A current authorized reviewer session is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['request_id'] ?? 0 ); check_admin_referer( 'smc_declare_conflict_' . $id, 'smc_nonce' );
		$status = sanitize_key( wp_unslash( $_POST['conflict_status'] ?? '' ) ); $note = sanitize_textarea_field( wp_unslash( $_POST['conflict_note'] ?? '' ) );
		if ( ! in_array( $status, array( 'none','conflict' ), true ) || ( 'conflict' === $status && strlen( $note ) < 8 ) ) { wp_die( esc_html__( 'A valid conflict declaration is required.', 'sabri-membership-core' ), '', array( 'response' => 400 ) ); }
		global $wpdb; $wpdb->query( 'START TRANSACTION' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || (int) $row['assigned_reviewer'] !== get_current_user_id() ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Only the assigned reviewer may record this declaration.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$assigned = 'conflict' === $status ? 0 : get_current_user_id(); $assigned_at = 'conflict' === $status ? null : ( $row['assigned_at'] ?: current_time( 'mysql', true ) ); $now = current_time( 'mysql', true );
		$updated = $wpdb->update( $wpdb->prefix . 'smc_verification_requests', array( 'conflict_status'=>$status,'conflict_note'=>$note,'assigned_reviewer'=>$assigned,'assigned_at'=>$assigned_at,'row_version'=>(int)$row['row_version']+1,'updated_at'=>$now ), array( 'id'=>$id,'row_version'=>(int)$row['row_version'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'review_conflict_' . $status, (int) $row['user_id'], array( 'request_id'=>$id,'reason_code'=>'conflict_' . $status ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The conflict declaration could not be committed.', 'sabri-membership-core' ), '', array( 'response'=>409 ) ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The conflict declaration commit failed.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); } self::redirect_review( (int) $row['user_id'] );
	}

	public static function handle_transition() {
		if ( ! current_user_can( 'smc_review_verification' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$user_id = absint( $_POST['user_id'] ?? 0 );
		global $wpdb;
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1", $user_id ), ARRAY_A );
		if ( ! $request ) {
			wp_die( esc_html__( 'Request not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );
		}
		check_admin_referer( 'smc_review_transition_' . $request['id'], 'smc_nonce' );
		$version = absint( $_POST['row_version'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$reason_code = sanitize_key( wp_unslash( $_POST['reason_code'] ?? '' ) );
		if ( $user_id === get_current_user_id() || strlen( $reason ) < 12 || ! isset( smc_review_reason_codes()[ $reason_code ] ) ) {
			wp_die( esc_html__( 'Self-review is forbidden and a substantive reason is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$old = $request['status'];
		$matrix = array(
			'submitted'        => array( 'under_review' ),
			'resubmitted'      => array( 'under_review' ),
			'appeal_review'    => array( 'restore', 'reject' ),
			'under_review'     => array( 'under_review', 'more_information', 'approve', 'reject' ),
			'approval_pending' => array( 'approve', 'more_information', 'reject' ),
			'approved'         => array( 'suspend' ),
			'suspended'        => array( 'restore' ),
		);
		if ( ! isset( $matrix[ $old ] ) || ! in_array( $decision, $matrix[ $old ], true ) ) {
			wp_die( esc_html__( 'This state transition is not permitted.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( $request['assigned_reviewer'] && (int) $request['assigned_reviewer'] !== get_current_user_id() && ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( esc_html__( 'This review is assigned to another reviewer.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( (int) $request['assigned_reviewer'] !== get_current_user_id() || 'none' !== $request['conflict_status'] ) {
			wp_die( esc_html__( 'The review must be assigned to you and a no-conflict declaration must be current.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( in_array( $decision, array( 'approve', 'reject', 'suspend', 'restore' ), true ) ) {
			if ( empty( $_POST['confirm_high_risk'] ) ) { wp_die( esc_html__( 'Explicit confirmation is required for this high-risk decision.', 'sabri-membership-core' ), '', array( 'response'=>400 ) ); }
			if ( ! SMC_Security::session_is_verified( get_current_user_id() ) ) { wp_die( esc_html__( 'A current two-factor reviewer session is required for this high-risk decision.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		}
		if ( 'restore' === $decision ) {
			if ( ! current_user_can( 'smc_restore_membership' ) && ! current_user_can( 'smc_finalize_verification' ) ) {
				wp_die( esc_html__( 'Restoration requires senior restoration authority.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
			}
			$previous_actor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT actor_id FROM {$wpdb->prefix}smc_verification_events WHERE request_id=%d AND new_status IN ('rejected','suspended') ORDER BY id DESC LIMIT 1", (int) $request['id'] ) );
			if ( $previous_actor && $previous_actor === get_current_user_id() ) {
				wp_die( esc_html__( 'An appeal or restoration must be decided by an independent reviewer.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
			}
		}
		if ( 'approve' === $decision ) {
			if ( 'appeal' === sanitize_key( $request['queue_type'] ?? '' ) ) { wp_die( esc_html__( 'Appeal provenance cannot be converted to ordinary approval; use the governed restore decision.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
			self::approve( $user_id, $request, $version, $reason, $reason_code );
		}
		$new = array( 'suspend' => 'suspended', 'reject' => 'rejected', 'restore' => 'approved' )[ $decision ] ?? $decision;
		self::commit_transition( $user_id, $request, $version, $new, $reason, $reason_code );
		self::notify_decision( $user_id, $new, $reason );
		self::redirect_review( $user_id );
	}

	private static function approval_gate( $votes, $required_votes, $can_finalize ) {
		$votes = max( 0, (int) $votes );
		$required_votes = max( 1, (int) $required_votes );
		if ( $votes < $required_votes ) {
			return 'pending_votes';
		}
		if ( $required_votes > 1 && ! $can_finalize ) {
			return 'pending_senior';
		}
		return 'finalize';
	}

	private static function approve( $user_id, $request, $version, $reason, $reason_code ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$version = absint( $version );
		$wpdb->query( 'START TRANSACTION' );
		$request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d AND user_id=%d LIMIT 1 FOR UPDATE",
				(int) $request['id'],
				$user_id
			),
			ARRAY_A
		);
		if ( ! $request || (int) $request['row_version'] !== $version ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The review request changed concurrently. Reload before approving.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$app = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_applications WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$guardian = $wpdb->get_row( $wpdb->prepare( "SELECT status,consent_hash,policy_version,verified_at,withdrawn_at FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$approved_document_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,document_key,version,plain_sha256,expiry_date FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d AND status='approved' AND scan_status='passed' AND (expiry_date IS NULL OR expiry_date>=UTC_DATE()) ORDER BY document_key ASC FOR UPDATE",
				$user_id
			),
			ARRAY_A
		);
		$a = SMC_Contracts::assertions( $user_id );
		$age = false;
		if ( $app && ! empty( $app['date_of_birth_enc'] ) ) {
			$dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
			$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		}
		$minimum_age = $app ? smc_effective_minimum_age( $app['gender'], $app['residence_country'] ?? '' ) : false;
		$required = array_keys( smc_required_identity_documents() );
		$approved_docs = array_column( $approved_document_rows, 'document_key' );
		if (
			! $app || false === $age || false === $minimum_age || $age < $minimum_age ||
			( $age < 18 && (bool) array_intersect( SMC_Contracts::requested_types( $user_id ), smc_professional_types() ) ) ||
			! $a['email_verified'] || ! $a['phone_verified'] ||
			! $a['two_factor_ready'] || ! $a['guardian_verified'] || ! $a['professional_verified'] ||
			array_diff( $required, $approved_docs ) || ! $identity ||
			( ! empty( $app['guardian_required'] ) && ( ! $guardian || 'verified' !== $guardian['status'] || ! empty( $guardian['withdrawn_at'] ) ) ) ||
			(int) $request['applicant_version'] <= 0 ||
			'matched' !== sanitize_key( wp_unslash( $_POST['name_match'] ?? '' ) )
		) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Approval predicates failed: age, guardian, contacts, two-factor setup, professional-owner verification, exact approved documents, or explicit identity-name comparison is incomplete.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}

		$document_snapshot = array();
		foreach ( $approved_document_rows as $document ) {
			$document_snapshot[] = array(
				'id'      => (int) $document['id'],
				'key'     => (string) $document['document_key'],
				'version' => (int) $document['version'],
				'sha256'  => (string) $document['plain_sha256'],
				'expiry'  => (string) ( $document['expiry_date'] ?? '' ),
			);
		}
		$snapshot = wp_json_encode(
			array(
				'applicant_version' => (int) $request['applicant_version'],
				'application_id'    => (int) $app['id'],
				'application_row_version' => (int) $app['row_version'],
				'policy_version'    => (string) $app['policy_version'],
				'documents'         => $document_snapshot,
				'identity_id'       => (int) $identity['id'],
				'identity_hash'     => (string) $identity['document_number_hash'],
				'identity_updated'  => (string) $identity['updated_at'],
				'guardian'          => $guardian ? array(
					'status'         => (string) $guardian['status'],
					'consent_hash'   => (string) $guardian['consent_hash'],
					'policy_version' => (string) $guardian['policy_version'],
					'verified_at'    => (string) $guardian['verified_at'],
					'withdrawn_at'   => (string) $guardian['withdrawn_at'],
				) : array( 'required' => false ),
				'professional'      => (bool) $a['professional_verified'],
				'requested_roles'   => SMC_Contracts::requested_types( $user_id ),
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $snapshot ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The approval evidence snapshot could not be created.', 'sabri-membership-core' ), '', array( 'response' => 500 ) );
		}

		$snapshot_hash = hash( 'sha256', $snapshot );
		$generation = ! empty( $request['approval_generation'] ) ? (string) $request['approval_generation'] : wp_generate_uuid4();
		$stored_snapshot_hash = (string) ( $request['approval_snapshot_hash'] ?? '' );
		if ( '' !== $stored_snapshot_hash && ! hash_equals( $stored_snapshot_hash, $snapshot_hash ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Approval evidence changed after the approval generation was opened. Start a new correction/review generation.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( '' === $stored_snapshot_hash ) {
			$generation_saved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET approval_generation=%s,approval_snapshot_hash=%s WHERE id=%d AND row_version=%d AND (approval_generation IS NULL OR approval_generation='')", $generation, $snapshot_hash, (int) $request['id'], (int) $request['row_version'] ) );
			if ( 1 !== $generation_saved ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The approval generation changed concurrently.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		}
		$vote = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_approval_votes (request_id,reviewer_id,approval_generation,decision,reason,evidence_snapshot,created_at) VALUES (%d,%d,%s,'approve',%s,%s,%s) ON DUPLICATE KEY UPDATE decision='approve',reason=VALUES(reason),evidence_snapshot=VALUES(evidence_snapshot),created_at=VALUES(created_at)",
			(int) $request['id'], get_current_user_id(), $generation, $reason, $snapshot, current_time( 'mysql', true )
		) );
		if ( false === $vote ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Approval vote could not be recorded.', 'sabri-membership-core' ), '', array( 'response' => 500 ) ); }
		$required_votes = array_intersect( SMC_Contracts::requested_types( $user_id ), smc_professional_types() ) ? 2 : 1;
		$votes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reviewer_id) FROM {$wpdb->prefix}smc_approval_votes WHERE request_id=%d AND approval_generation=%s AND decision='approve'", (int) $request['id'], $generation ) );
		$senior_required = $required_votes > 1;
		$can_finalize = ! $senior_required || current_user_can( 'smc_finalize_verification' );
		$approval_gate = self::approval_gate( $votes, $required_votes, $can_finalize );
		$now = current_time( 'mysql', true );
		if ( 'finalize' !== $approval_gate ) {
			$pending_reason = 'pending_votes' === $approval_gate
				? sprintf( __( '%1$d of %2$d independent approval votes recorded.', 'sabri-membership-core' ), $votes, $required_votes )
				: __( 'The required independent votes are complete; senior finalization remains required.', 'sabri-membership-core' );
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='approval_pending',assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,reason_code=%s,reviewer_note=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND user_id=%d AND row_version=%d AND applicant_version=%d AND approval_generation=%s AND approval_snapshot_hash=%s", $reason_code, $pending_reason . ' ' . $reason, $now, (int) $request['id'], $user_id, $version, (int) $request['applicant_version'], $generation, $snapshot_hash ) );
			$event_ok = 1 === $ok1 && self::append_event( $request, 'approval_pending', $pending_reason, $user_id );
			$audit_ok = $event_ok && SMC_Security::audit( 'membership_approval_pending', $user_id, array( 'request_id'=>(int)$request['id'],'votes'=>$votes,'required_votes'=>$required_votes,'approval_generation'=>$generation,'evidence_snapshot_sha256'=>$snapshot_hash,'reason_code'=>$reason_code ) );
			if ( 1 !== $ok1 || ! $event_ok || ! $audit_ok ) {
				$wpdb->query( 'ROLLBACK' );
				wp_die( esc_html__( 'The approval vote could not be committed atomically.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The approval-pending decision could not be committed.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
			self::notify_decision( $user_id, 'approval_pending', $pending_reason );
			self::redirect_review( $user_id );
		}

		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='approved',assigned_reviewer=%d,reason_code=%s,reviewer_note=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND row_version=%d AND applicant_version=%d", get_current_user_id(), $reason_code, $reason, $now, $now, (int) $request['id'], $user_id, $version, (int) $request['applicant_version'] ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='approved',row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND status=%s AND row_version=%d", $now, $now, (int) $app['id'], $user_id, $app['status'], (int) $app['row_version'] ) );
		$ok3 = $wpdb->update( $wpdb->prefix . 'smc_identity_records', array( 'name_match_status' => 'matched', 'name_match_note' => $reason, 'verified_at' => $now, 'verified_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => (int) $identity['id'], 'user_id' => $user_id ) );
		$hold = array( 'operation'=>'approve','started_at'=>time() ); update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );
		$role_ok = 1 === $ok1 && 1 === $ok2 && false !== $ok3 && get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) === $hold && SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id(), false );
		$event_ok = $role_ok && self::append_event( $request, 'approved', $reason, $user_id );
		$audit_ok = $event_ok && SMC_Security::audit( 'membership_approved', $user_id, array( 'request_id'=>(int)$request['id'],'votes'=>$votes,'approval_generation'=>$generation,'evidence_snapshot_sha256'=>$snapshot_hash,'reason_code'=>$reason_code ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || false === $ok3 || ! $role_ok || ! $event_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			wp_die( esc_html__( 'Approval could not be committed atomically.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); clean_user_cache( $user_id ); wp_die( esc_html__( 'Approval database commit failed; role/session projection was not attempted.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		$roles_wp_ok = SMC_Contracts::sync_wordpress_roles( $user_id );
		$sessions_ok = $roles_wp_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_approved_requires_fresh_login' );
		if ( ! $roles_wp_ok || ! $sessions_ok ) { if ( class_exists( 'SMC_Completion' ) ) { SMC_Completion::queue_effects_repair( $user_id, 'approve', 'approved', 'postcommit_effects' ); } wp_die( esc_html__( 'Approval is durably recorded but remains fail-closed pending role/session reconciliation.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
		clean_user_cache( $user_id );
		self::notify_decision( $user_id, 'approved', $reason );
		self::redirect_review( $user_id );
	}

	private static function commit_transition( $user_id, $request, $version, $new, $reason, $reason_code ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$hold = array( 'operation'=>'transition','target_status'=>$new,'started_at'=>time() ); update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );
		if ( get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { wp_die( esc_html__( 'Could not establish the fail-closed reconciliation hold.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		$wpdb->query( 'START TRANSACTION' );
		$restrict = in_array( $new, array( 'rejected', 'suspended' ), true );
		$restore = 'approved' === $new && in_array( $request['status'], array( 'appeal_review', 'suspended' ), true );
		$decided_at = ( $restrict || $restore ) ? $now : null;
		$ok1 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,queue_type=%s,assigned_reviewer=%d,reason_code=%s,reviewer_note=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND row_version=%d AND assigned_reviewer=%d AND conflict_status='none'",
				$new, $restore ? 'appeal' : ( $restrict ? 'appeal' : (string) $request['queue_type'] ), get_current_user_id(), $reason_code, $reason, $decided_at, $now, (int) $request['id'], $version, get_current_user_id()
			)
		);
		$ok2 = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE user_id=%d AND status=%s",
				$new, $decided_at, $now, $user_id, $request['status']
			)
		);
		$role_ok = true;
		if ( $restrict ) {
			$role_ok = SMC_Contracts::set_all_roles_pending( $user_id, (int) $request['applicant_version'] + 1, false );
		} elseif ( $restore ) {
			$role_ok = SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id(), false );
		} else { $role_ok = true; }
		$event_ok = self::append_event( $request, $new, $reason, $user_id );
		$audit_action = $restore ? 'membership_restored' : 'membership_' . $new;
		$audit_ok = $event_ok && SMC_Security::audit( $audit_action, $user_id, array( 'request_id' => (int) $request['id'], 'reason' => $reason, 'reason_code' => $reason_code, 'role_types' => SMC_Contracts::requested_types( $user_id ) ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || ! $role_ok || ! $event_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			wp_die( esc_html__( 'The request changed concurrently; no decision was applied.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); clean_user_cache( $user_id ); return; }
		$role_effect = ( $restrict || $restore ) ? SMC_Contracts::sync_wordpress_roles( $user_id ) : true;
		$session_effect = ( $restrict || $restore ) && $role_effect ? SMC_Security::revoke_all_sessions( $user_id, $restore ? 'membership_restored_requires_fresh_login' : 'membership_' . $new ) : $role_effect;
		if ( ! $role_effect || ! $session_effect ) { if ( class_exists( 'SMC_Completion' ) ) { SMC_Completion::queue_effects_repair( $user_id, 'transition', $new, 'postcommit_effects' ); } return; }
		delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
		clean_user_cache( $user_id );
	}

	private static function append_event( $request, $new, $reason, $user_id ) {
		global $wpdb;
		$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_hash FROM {$wpdb->prefix}smc_verification_events WHERE request_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", (int) $request['id'] ) );
		$created_at = current_time( 'mysql', true );
		$payload = wp_json_encode( array( 'request_id'=>(int)$request['id'],'user_id'=>$user_id,'actor_id'=>get_current_user_id(),'old'=>$request['status'],'new'=>$new,'note'=>$reason,'previous'=>$previous,'time'=>$created_at ) );
		$hash = SMC_Security::blind_index( $payload, 'verification-event' );
		return ! is_wp_error( $hash ) && 1 === $wpdb->insert( $wpdb->prefix . 'smc_verification_events', array( 'request_id'=>(int)$request['id'],'user_id'=>$user_id,'actor_id'=>get_current_user_id(),'old_status'=>$request['status'],'new_status'=>$new,'note'=>$reason,'previous_hash'=>$previous,'event_hash'=>$hash,'created_at'=>$created_at ) );
	}

	private static function notify_decision( $user_id, $status, $reason ) {
		smc_notify( $user_id, 'membership_' . $status, __( 'Membership Status Update', 'sabri-membership-core' ), sprintf( __( 'Your membership status is now %1$s. %2$s', 'sabri-membership-core' ), smc_statuses()[ $status ] ?? $status, $reason ), in_array( $status, array( 'suspended', 'rejected' ), true ) ? 'critical' : 'high', smc_page_url( 'status', '/membership-status/' ) );
	}

	private static function redirect_review( $user_id ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'smc-membership', 'user_id' => absint( $user_id ), 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function settings_page() {
		$id = smc_founder_user_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Settings', 'sabri-membership-core' ) . '</h1><h2>' . esc_html__( 'Official Founder Identity', 'sabri-membership-core' ) . '</h2><p><strong>' . esc_html( smc_policy()['founder_public_name'] ) . '</strong></p><p>' . esc_html__( 'No administrator is selected or renamed automatically. Prefer the immutable SMC_FOUNDER_USER_ID constant; otherwise explicitly save one existing user ID below.', 'sabri-membership-core' ) . '</p>';
		if ( defined( 'SMC_FOUNDER_USER_ID' ) ) {
			echo '<p><code>SMC_FOUNDER_USER_ID=' . absint( SMC_FOUNDER_USER_ID ) . '</code></p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_save_founder">';
		wp_nonce_field( 'smc_save_founder', 'smc_nonce' );
		echo '<p><label>' . esc_html__( 'Exact WordPress user ID', 'sabri-membership-core' ) . ' <input type="number" name="founder_user_id" value="' . absint( $id ) . '" min="1" required></label></p><p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I verified the exact account and understand that this grants official Founder identity only.', 'sabri-membership-core' ) . '</label></p><button class="button button-primary">' . esc_html__( 'Save Exact Founder Account', 'sabri-membership-core' ) . '</button></form></div>';
	}

	public static function save_founder() {
		if ( ! current_user_can( 'manage_options' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) || defined( 'SMC_FOUNDER_USER_ID' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'smc_save_founder', 'smc_nonce' );
		$id = absint( $_POST['founder_user_id'] ?? 0 );
		if ( ! get_userdata( $id ) || empty( $_POST['confirm'] ) ) {
			wp_die( esc_html__( 'Select and confirm an existing exact user account.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		$had_previous = false !== get_option( 'smc_founder_user_id', false );
		$previous = get_option( 'smc_founder_user_id', null );
		$wpdb->query( 'START TRANSACTION' );
		update_option( 'smc_founder_user_id', $id, false );
		$stored = (int) get_option( 'smc_founder_user_id', 0 );
		$audit_ok = $stored === $id && SMC_Security::audit( 'founder_account_configured', $id, array( 'configured_by' => get_current_user_id() ) );
		$commit_ok = $audit_ok && $stored === $id && false !== $wpdb->query( 'COMMIT' );
		if ( ! $commit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $had_previous ) { update_option( 'smc_founder_user_id', $previous, false ); } else { delete_option( 'smc_founder_user_id' ); }
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'smc_founder_user_id', 'options' );
			wp_die( esc_html__( 'Founder identity could not be committed with its audit evidence.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'smc_founder_user_id', 'options' );
		wp_safe_redirect( admin_url( 'admin.php?page=smc-settings&updated=1' ) );
		exit;
	}

	public static function audit_page() {
		global $wpdb;
		$integrity = SMC_Security::verify_audit_chain();
		$status = ! empty( $integrity['valid'] )
			? sprintf( __( 'Audit chain verified: %d rows checked.', 'sabri-membership-core' ), (int) $integrity['checked'] )
			: sprintf( __( 'Audit chain failure at row %1$d: %2$s', 'sabri-membership-core' ), (int) $integrity['failed_id'], sanitize_text_field( $integrity['reason'] ) );
		$class = ! empty( $integrity['valid'] ) ? 'notice notice-success' : 'notice notice-error';
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 200", ARRAY_A );
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Audit Integrity', 'sabri-membership-core' ) . '</h1><div class="' . esc_attr( $class ) . '"><p>' . esc_html( $status ) . '</p></div><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Time', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Actor', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Action', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Hash', 'sabri-membership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . absint( $row['id'] ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td><td>' . absint( $row['actor_id'] ) . '</td><td>' . esc_html( $row['action'] ) . '</td><td><code>' . esc_html( substr( $row['row_hash'], 0, 16 ) ) . '…</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

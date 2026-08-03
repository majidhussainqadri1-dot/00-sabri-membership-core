<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_smc_review_transition', array( __CLASS__, 'handle_transition' ) );
		add_action( 'admin_post_smc_review_document', array( __CLASS__, 'handle_document' ) );
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Sabri Membership is fail-closed: define a securely backed-up SMC_MASTER_KEY of at least 32 random characters before processing identity data.', 'sabri-membership-core' ) . '</p></div>';
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
		if ( ! current_user_can( 'smc_review_verification' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		if ( $user_id ) {
			self::review_screen( $user_id );
			return;
		}
		global $wpdb;
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset = ( $page - 1 ) * 50;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*,a.legal_name,a.membership_type,a.guardian_required,u.user_email
				FROM {$wpdb->prefix}smc_verification_requests r
				JOIN {$wpdb->prefix}smc_applications a ON a.user_id=r.user_id
				JOIN {$wpdb->users} u ON u.ID=r.user_id
				ORDER BY FIELD(r.status,'submitted','resubmitted','appeal_review','under_review','approval_pending','more_information','rejected','suspended','approved'),r.updated_at ASC
				LIMIT 50 OFFSET %d",
				$offset
			),
			ARRAY_A
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Verification Queue', 'sabri-membership-core' ) . '</h1><p>' . esc_html__( 'Identity membership only. Doctor credentials and public professional profiles remain owned by Files 09 and 03.', 'sabri-membership-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Applicant', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Type', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Status', 'sabri-membership-core' ) . '</th><th>' . esc_html__( 'Reviewer', 'sabri-membership-core' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg( array( 'page' => 'smc-membership', 'user_id' => (int) $row['user_id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td><strong>' . esc_html( $row['legal_name'] ) . '</strong><br><small>' . esc_html( $row['user_email'] ) . '</small></td><td>' . esc_html( smc_account_types()[ $row['membership_type'] ] ?? $row['membership_type'] ) . '</td><td>' . esc_html( smc_statuses()[ $row['status'] ] ?? $row['status'] ) . '</td><td>' . esc_html( $row['assigned_reviewer'] ? get_the_author_meta( 'display_name', $row['assigned_reviewer'] ) : __( 'Unassigned', 'sabri-membership-core' ) ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Review', 'sabri-membership-core' ) . '</a></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No requests on this page.', 'sabri-membership-core' ) . '</td></tr>';
		}
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
		$number = $identity ? SMC_Security::decrypt( $identity['document_number_enc'], 'identity-number', array( 'user_id' => $user_id, 'type' => $identity['document_type'], 'country' => $identity['issuing_country'] ) ) : '';
		$age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
		$a = SMC_Contracts::assertions( $user_id );
		echo '<div class="wrap"><h1>' . esc_html( sprintf( __( 'Review: %s', 'sabri-membership-core' ), $app['legal_name'] ) ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=smc-membership' ) ) . '">← ' . esc_html__( 'Back to queue', 'sabri-membership-core' ) . '</a></p>';
		echo '<div class="card" style="max-width:1100px"><h2>' . esc_html__( 'Eligibility Snapshot', 'sabri-membership-core' ) . '</h2><table class="widefat striped"><tbody>';
		$facts = array(
			__( 'Legal name', 'sabri-membership-core' ) => $app['legal_name'],
			__( 'Email', 'sabri-membership-core' ) => $user->user_email,
			__( 'Membership type', 'sabri-membership-core' ) => smc_account_types()[ $app['membership_type'] ] ?? $app['membership_type'],
			__( 'Date of birth', 'sabri-membership-core' ) => is_wp_error( $dob ) ? __( 'Decryption failed', 'sabri-membership-core' ) : $dob,
			__( 'Current calculated age', 'sabri-membership-core' ) => false === $age ? __( 'Invalid', 'sabri-membership-core' ) : $age,
			__( 'Gender rule', 'sabri-membership-core' ) => smc_allowed_genders()[ $app['gender'] ] ?? __( 'Invalid', 'sabri-membership-core' ),
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
		echo '<p><label>' . esc_html__( 'Decision', 'sabri-membership-core' ) . ' <select name="decision" required><option value="under_review">' . esc_html__( 'Claim / continue review', 'sabri-membership-core' ) . '</option><option value="more_information">' . esc_html__( 'Request more information', 'sabri-membership-core' ) . '</option><option value="approve">' . esc_html__( 'Approve / cast approval vote', 'sabri-membership-core' ) . '</option><option value="reject">' . esc_html__( 'Reject', 'sabri-membership-core' ) . '</option><option value="suspend">' . esc_html__( 'Suspend approved membership', 'sabri-membership-core' ) . '</option></select></label></p>';
		echo '<p><label><input type="checkbox" name="name_match" value="matched"> ' . esc_html__( 'I compared the legal name and authenticated identity evidence and record an exact or adequately explained match.', 'sabri-membership-core' ) . '</label></p><p><label>' . esc_html__( 'Mandatory reason', 'sabri-membership-core' ) . '<br><textarea name="reason" required minlength="12" style="width:100%"></textarea></label></p><button class="button button-primary">' . esc_html__( 'Apply Controlled Decision', 'sabri-membership-core' ) . '</button></form></div></div>';
	}

	public static function handle_document() {
		if ( ! current_user_can( 'smc_review_verification' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$id = absint( $_POST['document_id'] ?? 0 );
		check_admin_referer( 'smc_review_document_' . $id, 'smc_nonce' );
		$version = absint( $_POST['document_version'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		if ( ! in_array( $decision, array( 'approved', 'rejected' ), true ) || strlen( $note ) < 8 ) {
			wp_die( esc_html__( 'Invalid document decision.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE id=%d", $id ), ARRAY_A );
		if ( ! $doc || (int) $doc['user_id'] === get_current_user_id() ) {
			wp_die( esc_html__( 'A reviewer cannot decide their own evidence.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_identity_documents SET status=%s,reviewed_by=%d,reviewed_at=%s,reviewer_note=%s,updated_at=%s WHERE id=%d AND version=%d",
				$decision, get_current_user_id(), current_time( 'mysql', true ), $note, current_time( 'mysql', true ), $id, $version
			)
		);
		$audit_ok = 1 === $updated && SMC_Security::audit( 'document_' . $decision, (int) $doc['user_id'], array( 'document_id' => $id, 'version' => $version, 'reason' => $note ) );
		if ( 1 !== $updated || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The evidence decision could not be committed with its audit record. Reload and review the current version.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		self::redirect_review( (int) $doc['user_id'] );
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
		if ( $user_id === get_current_user_id() || strlen( $reason ) < 12 ) {
			wp_die( esc_html__( 'Self-review is forbidden and a substantive reason is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$old = $request['status'];
		$matrix = array(
			'submitted'        => array( 'under_review' ),
			'resubmitted'      => array( 'under_review' ),
			'appeal_review'    => array( 'under_review', 'approve', 'reject' ),
			'under_review'     => array( 'under_review', 'more_information', 'approve', 'reject' ),
			'approval_pending' => array( 'approve', 'more_information', 'reject' ),
			'approved'         => array( 'suspend' ),
		);
		if ( ! isset( $matrix[ $old ] ) || ! in_array( $decision, $matrix[ $old ], true ) ) {
			wp_die( esc_html__( 'This state transition is not permitted.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( $request['assigned_reviewer'] && (int) $request['assigned_reviewer'] !== get_current_user_id() && ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( esc_html__( 'This review is assigned to another reviewer.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( 'approve' === $decision ) {
			self::approve( $user_id, $request, $version, $reason );
		}
		$new = array( 'suspend' => 'suspended', 'reject' => 'rejected' )[ $decision ] ?? $decision;
		self::commit_transition( $user_id, $request, $version, $new, $reason );
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

	private static function approve( $user_id, $request, $version, $reason ) {
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
		$guardian = $wpdb->get_row( $wpdb->prepare( "SELECT status,consent_hash,policy_version,verified_at,withdrawn_at FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
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
		$minimum_age = $app ? smc_minimum_age_for_gender( $app['gender'] ) : false;
		$required = array_keys( smc_required_identity_documents() );
		$approved_docs = array_column( $approved_document_rows, 'document_key' );
		if (
			! $app || false === $age || false === $minimum_age || $age < $minimum_age ||
			( $age < 18 && smc_is_professional_type( $app['membership_type'] ) ) ||
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
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $snapshot ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The approval evidence snapshot could not be created.', 'sabri-membership-core' ), '', array( 'response' => 500 ) );
		}

		$vote = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}smc_approval_votes (request_id,reviewer_id,decision,reason,evidence_snapshot,created_at)
				VALUES (%d,%d,'approve',%s,%s,%s)
				ON DUPLICATE KEY UPDATE decision='approve',reason=VALUES(reason),evidence_snapshot=VALUES(evidence_snapshot),created_at=VALUES(created_at)",
				(int) $request['id'], get_current_user_id(), $reason, $snapshot, current_time( 'mysql', true )
			)
		);
		if ( false === $vote ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Approval vote could not be recorded.', 'sabri-membership-core' ), '', array( 'response' => 500 ) );
		}
		$required_votes = smc_is_professional_type( $app['membership_type'] ) ? 2 : 1;
		$votes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT reviewer_id) FROM {$wpdb->prefix}smc_approval_votes WHERE request_id=%d AND decision='approve' AND BINARY evidence_snapshot=%s",
				(int) $request['id'],
				$snapshot
			)
		);
		$senior_required = $required_votes > 1;
		$can_finalize = ! $senior_required || current_user_can( 'smc_finalize_verification' );
		$approval_gate = self::approval_gate( $votes, $required_votes, $can_finalize );
		$now = current_time( 'mysql', true );
		if ( 'finalize' !== $approval_gate ) {
			$pending_reason = 'pending_votes' === $approval_gate
				? sprintf( __( '%1$d of %2$d independent approval votes recorded.', 'sabri-membership-core' ), $votes, $required_votes )
				: __( 'The required independent votes are complete; senior finalization remains required.', 'sabri-membership-core' );
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='approval_pending',assigned_reviewer=%d,reviewer_note=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND user_id=%d AND row_version=%d AND applicant_version=%d", get_current_user_id(), $pending_reason . ' ' . $reason, $now, (int) $request['id'], $user_id, $version, (int) $request['applicant_version'] ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='approval_pending',row_version=row_version+1,updated_at=%s WHERE id=%d AND user_id=%d AND status=%s AND row_version=%d", $now, (int) $app['id'], $user_id, $request['status'], (int) $app['row_version'] ) );
			$event_ok = self::append_event( $request, 'approval_pending', $pending_reason . ' ' . $reason, $user_id );
			$audit_ok = 1 === $ok1 && 1 === $ok2 && $event_ok && SMC_Security::audit( 'membership_approval_pending', $user_id, array( 'request_id' => (int) $request['id'], 'votes' => $votes, 'required_votes' => $required_votes, 'senior_finalization_required' => $senior_required && ! $can_finalize, 'evidence_snapshot_sha256' => hash( 'sha256', $snapshot ) ) );
			if ( 1 !== $ok1 || 1 !== $ok2 || ! $event_ok || ! $audit_ok ) {
				$wpdb->query( 'ROLLBACK' );
				wp_die( esc_html__( 'The approval vote could not be committed atomically.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
			}
			$wpdb->query( 'COMMIT' );
			self::notify_decision( $user_id, 'approval_pending', $pending_reason );
			self::redirect_review( $user_id );
		}

		$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='approved',assigned_reviewer=%d,reviewer_note=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND row_version=%d AND applicant_version=%d", get_current_user_id(), $reason, $now, $now, (int) $request['id'], $user_id, $version, (int) $request['applicant_version'] ) );
		$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status='approved',row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND status=%s AND row_version=%d", $now, $now, (int) $app['id'], $user_id, $request['status'], (int) $app['row_version'] ) );
		$ok3 = $wpdb->update( $wpdb->prefix . 'smc_identity_records', array( 'name_match_status' => 'matched', 'name_match_note' => $reason, 'verified_at' => $now, 'verified_by' => get_current_user_id(), 'updated_at' => $now ), array( 'id' => (int) $identity['id'], 'user_id' => $user_id ) );
		$role_ok = 1 === $ok1 && 1 === $ok2 && false !== $ok3 && SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( $app['membership_type'], true ) );
		$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_approved_requires_fresh_login' );
		$event_ok = $sessions_ok && self::append_event( $request, 'approved', $reason, $user_id );
		$audit_ok = $event_ok && SMC_Security::audit( 'membership_approved', $user_id, array( 'request_id' => (int) $request['id'], 'votes' => $votes, 'evidence_snapshot_sha256' => hash( 'sha256', $snapshot ) ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || false === $ok3 || ! $role_ok || ! $sessions_ok || ! $event_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			wp_die( esc_html__( 'Approval could not be committed atomically.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
		self::notify_decision( $user_id, 'approved', $reason );
		self::redirect_review( $user_id );
	}

	private static function commit_transition( $user_id, $request, $version, $new, $reason ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$final = in_array( $new, array( 'rejected', 'suspended' ), true );
		if ( $final ) {
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,assigned_reviewer=%d,reviewer_note=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE id=%d AND row_version=%d", $new, get_current_user_id(), $reason, $now, $now, (int) $request['id'], $version ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,decided_at=%s,updated_at=%s WHERE user_id=%d AND status=%s", $new, $now, $now, $user_id, $request['status'] ) );
		} else {
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,assigned_reviewer=%d,reviewer_note=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d", $new, get_current_user_id(), $reason, $now, (int) $request['id'], $version ) );
			$ok2 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s", $new, $now, $user_id, $request['status'] ) );
		}
		$role_ok = true;
		$sessions_ok = true;
		if ( $final ) {
			$app = smc_application( $user_id );
			$role_ok = $app && SMC_Contracts::set_exact_role( $user_id, smc_role_for_type( $app['membership_type'], false ) );
			$sessions_ok = SMC_Security::revoke_all_sessions( $user_id, 'membership_' . $new );
		}
		$event_ok = self::append_event( $request, $new, $reason, $user_id );
		$audit_ok = SMC_Security::audit( 'membership_' . $new, $user_id, array( 'request_id' => (int) $request['id'], 'reason' => $reason ) );
		if ( 1 !== $ok1 || 1 !== $ok2 || ! $role_ok || ! $sessions_ok || ! $event_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			wp_die( esc_html__( 'The request changed concurrently; no decision was applied.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $user_id );
	}

	private static function append_event( $request, $new, $reason, $user_id ) {
		global $wpdb;
		$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_hash FROM {$wpdb->prefix}smc_verification_events WHERE request_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", (int) $request['id'] ) );
		$payload = wp_json_encode( array( 'request_id' => (int) $request['id'], 'user_id' => $user_id, 'actor_id' => get_current_user_id(), 'old' => $request['status'], 'new' => $new, 'note' => $reason, 'previous' => $previous, 'time' => current_time( 'mysql', true ) ) );
		$hash = SMC_Security::blind_index( $payload, 'verification-event' );
		return ! is_wp_error( $hash ) && 1 === $wpdb->insert( $wpdb->prefix . 'smc_verification_events', array( 'request_id' => (int) $request['id'], 'user_id' => $user_id, 'actor_id' => get_current_user_id(), 'old_status' => $request['status'], 'new_status' => $new, 'note' => $reason, 'previous_hash' => $previous, 'event_hash' => $hash, 'created_at' => current_time( 'mysql', true ) ) );
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
		if ( ! current_user_can( 'manage_options' ) || defined( 'SMC_FOUNDER_USER_ID' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'smc_save_founder', 'smc_nonce' );
		$id = absint( $_POST['founder_user_id'] ?? 0 );
		if ( ! get_userdata( $id ) || empty( $_POST['confirm'] ) ) {
			wp_die( esc_html__( 'Select and confirm an existing exact user account.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		update_option( 'smc_founder_user_id', $id, false );
		$stored = (int) get_option( 'smc_founder_user_id', 0 );
		$audit_ok = $stored === $id && SMC_Security::audit( 'founder_account_configured', $id, array( 'configured_by' => get_current_user_id() ) );
		if ( $stored !== $id || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Founder identity could not be committed with its audit evidence.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
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

<?php
/**
 * Real WordPress + MariaDB integration exercise for governed lost-factor recovery.
 * Run through WP-CLI eval-file with SMC_RECOVERY_TEST_MODE.
 */

global $wpdb;

$mode     = getenv( 'SMC_RECOVERY_TEST_MODE' ) ?: 'assert-request';
$password = getenv( 'SMC_RECOVERY_TEST_PASSWORD' ) ?: 'Recovery-CI-Password!9';
$user     = get_user_by( 'login', 'founder' );
if ( ! $user ) {
	fwrite( STDERR, "founder fixture missing\n" );
	exit( 70 );
}
$user_id = (int) $user->ID;
$table   = $wpdb->prefix . 'smc_application_repairs';

$assert = static function ( $condition, $message, $code = 71 ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( $code );
	}
};

$ensure_admin = static function ( $login, $email ) use ( $assert ) {
	$actor = get_user_by( 'login', $login );
	if ( ! $actor ) {
		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'Recovery-Approver-Password!9',
				'user_email' => $email,
				'role'       => 'administrator',
			)
		);
		$assert( ! is_wp_error( $id ), 'Could not create independent recovery approver fixture.', 94 );
		$actor = get_userdata( (int) $id );
	}
	$actor->set_role( 'administrator' );
	delete_user_meta( (int) $actor->ID, '_smc_revalidation_required_at' );
	return $actor;
};

$verified_actor = static function ( $login ) use ( $assert, $wpdb ) {
	$actor = get_user_by( 'login', $login );
	$assert( $actor && user_can( $actor, 'manage_options' ), 'Approval actor must be an Administrator.', 95 );
	$actor_id = (int) $actor->ID;
	wp_set_current_user( $actor_id );
	delete_user_meta( $actor_id, '_smc_revalidation_required_at' );

	$expiration = time() + 2 * HOUR_IN_SECONDS;
	$manager    = WP_Session_Tokens::get_instance( $actor_id );
	$token      = $manager->create( $expiration );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $actor_id, $expiration, 'logged_in', $token );
	$hash = SMC_Security::blind_index( $token, 'session-token' );
	$assert( ! is_wp_error( $hash ), 'Could not blind-index approver session token.', 96 );
	$now = current_time( 'mysql', true );
	$stored = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_auth_sessions (user_id,token_hash,expires_at,two_factor_at,last_totp_slice,ip_hash,device_hash,revoked_at,created_at,updated_at) VALUES (%d,%s,%s,%s,NULL,NULL,NULL,NULL,%s,%s) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),two_factor_at=VALUES(two_factor_at),revoked_at=NULL,updated_at=VALUES(updated_at)",
			$actor_id,
			$hash,
			gmdate( 'Y-m-d H:i:s', $expiration ),
			$now,
			$now,
			$now
		)
	);
	$assert( false !== $stored, 'Could not persist MFA-verified approver session fixture.', 97 );
	$assert( SMC_Security::session_is_verified( $actor_id ), 'Approver session must satisfy the real File 00 MFA-session verifier.', 98 );
	return $actor;
};

$current_case = static function () use ( $wpdb, $table, $user_id ) {
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id=%d AND repair_type=%s ORDER BY id DESC LIMIT 1",
			$user_id,
			'lost_factor_recovery'
		),
		ARRAY_A
	);
};

if ( 'setup' === $mode ) {
	$assert( SMC_DB_VERSION === get_option( 'smc_db_version', '' ), 'File 00 schema migration must be complete before recovery tests run.' );
	$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	$assert( 'InnoDB' === $engine, 'Recovery case table must exist and use InnoDB.' );
	update_option( 'smc_founder_user_id', $user_id, false );

	$approver_a = $ensure_admin( 'recovery-approver-a', 'recovery-approver-a@example.test' );
	$approver_b = $ensure_admin( 'recovery-approver-b', 'recovery-approver-b@example.test' );
	$assert( (int) $approver_a->ID !== $user_id && (int) $approver_b->ID !== $user_id && (int) $approver_a->ID !== (int) $approver_b->ID, 'Recovery approvers must be distinct from each other and from the Founder.', 99 );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id=%d AND repair_type=%s", $user_id, 'lost_factor_recovery' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d", $user_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d", $user_id ) );
	foreach ( array( $user_id, (int) $approver_a->ID, (int) $approver_b->ID ) as $session_user_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d", $session_user_id ) );
	}
	foreach ( array( '_smc_totp_secret_enc', '_smc_totp_secret', '_smc_2fa_enabled', '_smc_totp_pending_enc', '_smc_totp_pending_expires', '_smc_revalidation_required_at' ) as $key ) {
		delete_user_meta( $user_id, $key );
	}

	$secret   = SMC_Security::base32_secret();
	$envelope = SMC_Security::encrypt( $secret, 'totp-secret', array( 'user_id' => $user_id ) );
	$assert( ! is_wp_error( $envelope ), 'TOTP fixture encryption failed.' );
	update_user_meta( $user_id, '_smc_totp_secret_enc', $envelope );
	update_user_meta( $user_id, '_smc_2fa_enabled', '1' );
	$codes = SMC_Security::recovery_codes( $user_id, 8, static function ( $plain ) { return 8 === count( (array) $plain ); } );
	$assert( ! is_wp_error( $codes ) && 8 === count( $codes ), 'Recovery-code fixture could not be stored.' );

	$now = current_time( 'mysql', true );
	$state = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=VALUES(last_totp_slice),updated_at=VALUES(updated_at)", $user_id, 123456, $now ) );
	$assert( false !== $state, 'Factor replay-state fixture could not be stored.' );
	$session = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_auth_sessions (user_id,token_hash,expires_at,two_factor_at,last_totp_slice,ip_hash,device_hash,revoked_at,created_at,updated_at) VALUES (%d,%s,%s,%s,%d,NULL,NULL,NULL,%s,%s)", $user_id, hash( 'sha256', 'recovery-ci-session' ), gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), $now, 123456, $now, $now ) );
	$assert( 1 === $session, 'Active File 00 session fixture could not be stored.' );
	$assert( SMC_Security::two_factor_ready( $user_id ), 'Founder TOTP fixture must be operational before lost-factor recovery.' );
	$assert( 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d", $user_id ) ), 'Eight recovery codes must exist before reset.' );
	fwrite( STDOUT, "account-recovery-wordpress-mysql setup: PASS\n" );
	return;
}

if ( 'request' === $mode ) {
	wp_set_current_user( $user_id );
	$_POST = array(
		'action'               => 'smc_account_recovery_request',
		'password'             => $password,
		'confirm_lost_factors' => '1',
		'smc_nonce'            => wp_create_nonce( 'smc_account_recovery_request' ),
	);
	$_REQUEST = $_POST;
	SMC_Account_Recovery::handle_request();
	return;
}

if ( 'assert-request' === $mode ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND repair_type=%s AND status IN ('requested','cooling','approved')", $user_id, 'lost_factor_recovery' ) );
	$assert( 1 === $count, 'Concurrent recovery requests must serialize to exactly one active case.', 72 );
	$row     = $current_case();
	$details = json_decode( (string) $row['details'], true );
	$assert( is_array( $details ), 'Recovery details must be valid JSON.', 73 );
	$assert( ! empty( $details['privileged'] ), 'Founder case must be privileged.', 74 );
	$assert( 2 === (int) ( $details['required_approvals'] ?? 0 ), 'Founder case must require two independent approvals.', 75 );
	$assert( DAY_IN_SECONDS === (int) ( $details['cooling_seconds'] ?? 0 ), 'Founder case must default to a 24-hour cooling period.', 76 );
	$assert( ! empty( $details['old_contact_hash'] ) && 64 === strlen( (string) $details['old_contact_hash'] ), 'Recovery case must bind old-contact continuity.', 77 );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log WHERE action=%s", 'account_recovery_requested' ) );
	$event_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE event_type=%s", 'AccountRecoveryRequested' ) );
	$assert( $audit_count >= 1 && 1 === $event_count, 'Request must commit audit and one deduplicated outbox event.', 78 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql request/concurrency: PASS\n" );
	return;
}

if ( 'self-approve' === $mode ) {
	$verified_actor( 'founder' );
	$row = $current_case();
	$assert( is_array( $row ), 'Recovery case missing before self-approval adversarial test.', 100 );
	$_POST = array(
		'action'             => 'smc_account_recovery_approve',
		'case_id'            => (int) $row['id'],
		'evidence_type'      => 'video',
		'evidence_reference' => 'founder-self-evidence-should-fail',
		'attest'             => '1',
		'smc_nonce'          => wp_create_nonce( 'smc_account_recovery_approve_' . (int) $row['id'] ),
	);
	$_REQUEST = $_POST;
	SMC_Account_Recovery::handle_approve();
	return;
}

if ( 'assert-self-rejected' === $mode ) {
	$row     = $current_case();
	$details = is_array( $row ) ? json_decode( (string) $row['details'], true ) : array();
	$approvals = is_array( $details ) && isset( $details['approvals'] ) && is_array( $details['approvals'] ) ? $details['approvals'] : array();
	$assert( 0 === count( $approvals ), 'Recovering Founder must never be able to approve the Founder recovery case.', 101 );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log WHERE action=%s", 'account_recovery_approval_recorded' ) );
	$assert( 0 === $audit_count, 'Rejected self-approval must not create approval audit evidence.', 102 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql self-approval rejection: PASS\n" );
	return;
}

if ( 'approve-a' === $mode || 'approve-b' === $mode ) {
	$login     = 'approve-a' === $mode ? 'recovery-approver-a' : 'recovery-approver-b';
	$type      = 'approve-a' === $mode ? 'video' : 'in_person';
	$reference = 'approve-a' === $mode ? 'recovery-evidence-reference-alpha-001' : 'recovery-evidence-reference-beta-002';
	$verified_actor( $login );
	$row = $current_case();
	$assert( is_array( $row ), 'Recovery case missing before real approval handler.', 103 );
	$_POST = array(
		'action'             => 'smc_account_recovery_approve',
		'case_id'            => (int) $row['id'],
		'evidence_type'      => $type,
		'evidence_reference' => $reference,
		'attest'             => '1',
		'smc_nonce'          => wp_create_nonce( 'smc_account_recovery_approve_' . (int) $row['id'] ),
	);
	$_REQUEST = $_POST;
	SMC_Account_Recovery::handle_approve();
	return;
}

if ( 'assert-approval-one' === $mode ) {
	$row     = $current_case();
	$details = is_array( $row ) ? json_decode( (string) $row['details'], true ) : array();
	$approvals = is_array( $details ) && isset( $details['approvals'] ) && is_array( $details['approvals'] ) ? $details['approvals'] : array();
	$actor = get_user_by( 'login', 'recovery-approver-a' );
	$assert( 1 === count( $approvals ), 'First real independent approval must be recorded exactly once.', 104 );
	$assert( $actor && (int) $actor->ID === (int) $approvals[0]['actor_id'], 'First approval must be bound to the actual independent approver.', 105 );
	$assert( 'cooling' === (string) $row['status'], 'One approval cannot approve a Founder recovery case.', 106 );
	$assert( 64 === strlen( (string) ( $approvals[0]['reference_hash'] ?? '' ) ), 'Evidence reference must be persisted only as a keyed hash.', 107 );
	$assert( false === strpos( (string) $row['details'], 'recovery-evidence-reference-alpha-001' ), 'Raw evidence reference must not be persisted.', 108 );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log WHERE action=%s", 'account_recovery_approval_recorded' ) );
	$approved_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE event_type=%s", 'AccountRecoveryApproved' ) );
	$assert( 1 === $audit_count && 0 === $approved_event, 'First approval must audit but must not emit final approval before dual control and cooling complete.', 109 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql first independent approval: PASS\n" );
	return;
}

if ( 'elapse-cooling' === $mode ) {
	$row = $current_case();
	$assert( is_array( $row ), 'Recovery case missing before cooling-period simulation.', 110 );
	$details = json_decode( (string) $row['details'], true );
	$assert( is_array( $details ), 'Recovery details missing before cooling-period simulation.', 111 );
	$details['ready_after'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET details=%s,next_attempt_at=%s,updated_at=%s WHERE id=%d AND status='cooling'",
			wp_json_encode( $details ),
			$details['ready_after'],
			current_time( 'mysql', true ),
			(int) $row['id']
		)
	);
	$assert( 1 === $updated, 'Cooling-period simulation could not advance the test clock.', 112 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql cooling simulation: PASS\n" );
	return;
}

if ( 'assert-approved' === $mode ) {
	$row     = $current_case();
	$details = is_array( $row ) ? json_decode( (string) $row['details'], true ) : array();
	$approvals = is_array( $details ) && isset( $details['approvals'] ) && is_array( $details['approvals'] ) ? $details['approvals'] : array();
	$actors = array_map( static function ( $approval ) { return (int) ( $approval['actor_id'] ?? 0 ); }, $approvals );
	$assert( 'approved' === (string) $row['status'], 'Second distinct real approval after cooling must transition the case to approved.', 113 );
	$assert( 2 === count( array_unique( $actors ) ) && 2 === count( $approvals ), 'Founder recovery must contain exactly two distinct independent approvals.', 114 );
	$assert( false === strpos( (string) $row['details'], 'recovery-evidence-reference-alpha-001' ) && false === strpos( (string) $row['details'], 'recovery-evidence-reference-beta-002' ), 'Raw out-of-band evidence references must never be persisted.', 115 );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log WHERE action=%s", 'account_recovery_approval_recorded' ) );
	$event_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE event_type=%s", 'AccountRecoveryApproved' ) );
	$assert( 2 === $audit_count && 1 === $event_count, 'Dual approval must produce two approval audit records and one final approval event.', 116 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql dual approval: PASS\n" );
	return;
}

if ( 'complete' === $mode ) {
	wp_set_current_user( $user_id );
	$case_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id=%d AND repair_type=%s AND status='approved' ORDER BY id DESC LIMIT 1", $user_id, 'lost_factor_recovery' ) );
	$assert( $case_id > 0, 'Approved case missing before completion.', 81 );
	$_POST = array(
		'action'        => 'smc_account_recovery_complete',
		'case_id'       => $case_id,
		'password'      => $password,
		'confirm_reset' => '1',
		'smc_nonce'     => wp_create_nonce( 'smc_account_recovery_complete_' . $case_id ),
	);
	$_REQUEST = $_POST;
	SMC_Account_Recovery::handle_complete();
	return;
}

if ( 'assert-complete' === $mode ) {
	$row = $current_case();
	$assert( is_array( $row ) && 'completed' === $row['status'], 'Approved recovery case must finish exactly once.', 82 );
	$assert( ! metadata_exists( 'user', $user_id, '_smc_2fa_enabled' ), 'Old 2FA enabled flag must be removed.', 83 );
	$assert( ! metadata_exists( 'user', $user_id, '_smc_totp_secret_enc' ), 'Old TOTP secret must be removed.', 84 );
	$assert( metadata_exists( 'user', $user_id, '_smc_totp_pending_enc' ), 'A brand-new pending TOTP enrollment must be staged.', 85 );
	$assert( absint( get_user_meta( $user_id, '_smc_totp_pending_expires', true ) ) > time(), 'Pending TOTP enrollment must have a future expiry.', 86 );
	$assert( absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) ) > 0, 'Recovery must require fresh revalidation.', 87 );
	$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d", $user_id ) ), 'Every old recovery code must be invalidated.', 88 );
	$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d", $user_id ) ), 'Old factor replay state must be invalidated.', 89 );
	$active_sessions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL", $user_id ) );
	$assert( 0 === $active_sessions, 'Every File 00 session must be revoked before factor reset completes.', 90 );
	$audit_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log WHERE action=%s", 'account_recovery_factor_reset_completed' ) );
	$event_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE event_type=%s", 'AccountRecoveryFactorResetCompleted' ) );
	$assert( 1 === $audit_count && 1 === $event_count, 'Completion must commit one audit record and one outbox event.', 91 );
	$chain = SMC_Security::verify_audit_chain();
	$assert( is_array( $chain ) && ! empty( $chain['valid'] ), 'Audit chain must remain valid after recovery reset.', 92 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql completion: PASS\n" );
	return;
}

fwrite( STDERR, "unknown SMC_RECOVERY_TEST_MODE\n" );
exit( 93 );

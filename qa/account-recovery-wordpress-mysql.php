<?php
/**
 * Real WordPress + MariaDB integration exercise for governed lost-factor recovery.
 * Run through WP-CLI eval-file with SMC_RECOVERY_TEST_MODE.
 */

$mode = getenv( 'SMC_RECOVERY_TEST_MODE' ) ?: 'assert-request';
$password = getenv( 'SMC_RECOVERY_TEST_PASSWORD' ) ?: 'Recovery-CI-Password!9';
$user = get_user_by( 'login', 'founder' );
if ( ! $user ) {
	fwrite( STDERR, "founder fixture missing\n" );
	exit( 70 );
}
$user_id = (int) $user->ID;
$table = $wpdb->prefix . 'smc_application_repairs';

$assert = static function ( $condition, $message, $code = 71 ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( $code );
	}
};

if ( 'setup' === $mode ) {
	$assert( SMC_DB_VERSION === get_option( 'smc_db_version', '' ), 'File 00 schema migration must be complete before recovery tests run.' );
	$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	$assert( 'InnoDB' === $engine, 'Recovery case table must exist and use InnoDB.' );
	update_option( 'smc_founder_user_id', $user_id, false );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id=%d AND repair_type=%s", $user_id, 'lost_factor_recovery' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d", $user_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d", $user_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d", $user_id ) );
	foreach ( array( '_smc_totp_secret_enc', '_smc_totp_secret', '_smc_2fa_enabled', '_smc_totp_pending_enc', '_smc_totp_pending_expires', '_smc_revalidation_required_at' ) as $key ) {
		delete_user_meta( $user_id, $key );
	}

	$secret = SMC_Security::base32_secret();
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
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d AND repair_type=%s ORDER BY id DESC LIMIT 1", $user_id, 'lost_factor_recovery' ), ARRAY_A );
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

if ( 'approve-fixture' === $mode ) {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d AND repair_type=%s ORDER BY id DESC LIMIT 1", $user_id, 'lost_factor_recovery' ), ARRAY_A );
	$assert( is_array( $row ), 'Recovery case missing before completion fixture.', 79 );
	$details = json_decode( (string) $row['details'], true );
	$details['ready_after'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
	$details['approvals'] = array(
		array( 'actor_id' => 9001, 'evidence_type' => 'video', 'reference_hash' => hash( 'sha256', 'evidence-a' ), 'approved_at' => current_time( 'mysql', true ), 'evidence_version' => 'lost-factor-v1' ),
		array( 'actor_id' => 9002, 'evidence_type' => 'in_person', 'reference_hash' => hash( 'sha256', 'evidence-b' ), 'approved_at' => current_time( 'mysql', true ), 'evidence_version' => 'lost-factor-v1' ),
	);
	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='approved',details=%s,next_attempt_at=%s,updated_at=%s WHERE id=%d", wp_json_encode( $details ), $details['ready_after'], current_time( 'mysql', true ), (int) $row['id'] ) );
	$assert( 1 === $updated, 'Approved completion fixture could not be prepared.', 80 );
	fwrite( STDOUT, "account-recovery-wordpress-mysql completion fixture: PASS\n" );
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
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d AND repair_type=%s ORDER BY id DESC LIMIT 1", $user_id, 'lost_factor_recovery' ), ARRAY_A );
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

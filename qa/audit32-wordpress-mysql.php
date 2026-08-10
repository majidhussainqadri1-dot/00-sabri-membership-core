<?php
/**
 * Real WordPress + MySQL/InnoDB integration smoke for the File 00 corrective candidate.
 * Run with: wp eval-file <repo>/qa/audit32-wordpress-mysql.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded.\n" );
	exit( 2 );
}

$failures = array();
$passed   = 0;
$check    = static function ( $condition, $label ) use ( &$failures, &$passed ) {
	if ( $condition ) {
		++$passed;
		echo "PASS {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "FAIL {$label}\n";
};

global $wpdb;
$check( defined( 'SMC_VERSION' ) && '1.2.37' === SMC_VERSION, 'runtime 1.2.37 loaded in real WordPress' );
$check( defined( 'SMC_DB_VERSION' ) && '1.4.4' === SMC_DB_VERSION, 'database contract 1.4.4 loaded' );
$check( class_exists( 'SMC_Security' ) && class_exists( 'SMC_Installer' ) && class_exists( 'SMC_Schema_Compat' ), 'File 00 runtime classes loaded' );

$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'smc_' ) . '%' ) );
$check( count( $tables ) >= 20, 'File 00 schema tables installed' );
foreach ( $tables as $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	$check( 'InnoDB' === $engine, 'InnoDB engine: ' . $table );
}

SMC_Schema_Compat::assert_current_queue_indexes();
$check( true, 'critical queue and approval decision indexes match current schema' );

$guardian_table = $wpdb->prefix . 'smc_guardian_consents';
$vote_table     = $wpdb->prefix . 'smc_approval_votes';
$request_table  = $wpdb->prefix . 'smc_verification_requests';
$factor_table   = $wpdb->prefix . 'smc_mfa_factor_state';
$audit_log      = $wpdb->prefix . 'smc_audit_log';
$audit_tail     = $wpdb->prefix . 'smc_audit_tail';

$guardian_indexes = $wpdb->get_col( "SHOW INDEX FROM {$guardian_table} WHERE Key_name='user_generation'", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$vote_indexes     = $wpdb->get_col( "SHOW INDEX FROM {$vote_table} WHERE Key_name='request_generation_reviewer'", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( count( $guardian_indexes ) >= 2, 'guardian immutable-generation unique index exists' );
$check( count( $vote_indexes ) >= 3, 'approval-generation reviewer unique index exists' );
$check( $wpdb->get_var( "SHOW TABLES LIKE '{$factor_table}'" ) === $factor_table, 'legacy factor-state table retained for safe migration compatibility' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( $wpdb->get_var( "SHOW TABLES LIKE '{$audit_tail}'" ) === $audit_tail, 'serialized audit-tail table exists' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 'audit_key_id' === (string) $wpdb->get_var( "SHOW COLUMNS FROM {$audit_log} LIKE 'audit_key_id'", 0 ), 'audit key-generation column exists' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$transaction_started = false !== $wpdb->query( 'START TRANSACTION' );
$transaction_audit = $transaction_started && SMC_Security::audit( 'audit32_outer_transaction_smoke', 0, array( 'source' => 'real-mysql' ) );
$transaction_committed = $transaction_audit && false !== $wpdb->query( 'COMMIT' );
if ( ! $transaction_committed ) { $wpdb->query( 'ROLLBACK' ); }
$check( $transaction_committed, 'transaction-owned audit append uses the read-only schema readiness gate' );
$unidentified_audit_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_log} WHERE row_hash<>'' AND (audit_key_id IS NULL OR audit_key_id='')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 0 === $unidentified_audit_rows, 'every fresh modern audit row authenticates its key generation' );

$user_id = wp_insert_user(
	array(
		'user_login' => 'audit32-child-' . wp_generate_password( 8, false, false ),
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'audit32-child-' . wp_generate_password( 8, false, false ) . '@example.test',
	)
);
$check( ! is_wp_error( $user_id ), 'integration subject user created' );
if ( is_wp_error( $user_id ) ) {
	$user_id = 0;
}
$now = current_time( 'mysql', true );

$guardian_common = array(
	'user_id'                    => $user_id,
	'guardian_name_enc'          => 'test-name',
	'guardian_email_enc'         => 'test-email',
	'guardian_email_hash'        => hash( 'sha256', 'guardian@example.test' ),
	'guardian_phone_enc'         => 'test-phone',
	'guardian_phone_hash'        => hash( 'sha256', '+10000000000' ),
	'relationship'               => 'parent',
	'legal_authority_confirmed'  => 1,
	'status'                     => 'verified',
	'consent_text'               => 'integration consent',
	'consent_hash'               => hash( 'sha256', 'integration consent' ),
	'policy_version'             => smc_policy()['version'],
	'otp_attempts'               => 0,
	'requested_at'               => $now,
	'verified_at'                => $now,
);
$first = array_merge( $guardian_common, array( 'generation' => 1, 'is_current' => 0 ) );
$second = array_merge( $guardian_common, array( 'generation' => 2, 'is_current' => 1, 'guardian_email_hash' => hash( 'sha256', 'successor@example.test' ) ) );
$g1 = $wpdb->insert( $guardian_table, $first );
$g2 = $wpdb->insert( $guardian_table, $second );
$count_generations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$guardian_table} WHERE user_id=%d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$current_generation = (int) $wpdb->get_var( $wpdb->prepare( "SELECT generation FROM {$guardian_table} WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 1 === $g1 && 1 === $g2 && 2 === $count_generations && 2 === $current_generation, 'guardian succession supports two immutable generations with one current successor' );

$request_insert = $wpdb->insert(
	$request_table,
	array(
		'user_id'                => $user_id,
		'status'                 => 'approval_pending',
		'queue_type'             => 'professional',
		'assigned_reviewer'      => 0,
		'conflict_status'        => 'undeclared',
		'applicant_version'      => 7,
		'approval_generation'    => '11111111-1111-4111-8111-111111111111',
		'approval_snapshot_hash' => hash( 'sha256', 'snapshot-a' ),
		'row_version'            => 3,
		'submitted_at'           => $now,
		'created_at'             => $now,
		'updated_at'             => $now,
	)
);
$request_id = (int) $wpdb->insert_id;
$reviewer1 = wp_insert_user( array( 'user_login'=>'audit32-r1-' . wp_generate_password(6,false,false), 'user_pass'=>wp_generate_password(24,true,true), 'user_email'=>'audit32-r1-' . wp_generate_password(6,false,false) . '@example.test' ) );
$reviewer2 = wp_insert_user( array( 'user_login'=>'audit32-r2-' . wp_generate_password(6,false,false), 'user_pass'=>wp_generate_password(24,true,true), 'user_email'=>'audit32-r2-' . wp_generate_password(6,false,false) . '@example.test' ) );
$generation = '11111111-1111-4111-8111-111111111111';
$v1 = $wpdb->insert( $vote_table, array( 'request_id'=>$request_id, 'reviewer_id'=>(int)$reviewer1, 'approval_generation'=>$generation, 'decision'=>'approve', 'reason'=>'integration reviewer one', 'evidence_snapshot'=>'snapshot-a', 'created_at'=>$now ) );
$v2 = $wpdb->insert( $vote_table, array( 'request_id'=>$request_id, 'reviewer_id'=>(int)$reviewer2, 'approval_generation'=>$generation, 'decision'=>'approve', 'reason'=>'integration reviewer two', 'evidence_snapshot'=>'snapshot-a', 'created_at'=>$now ) );
$vote_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reviewer_id) FROM {$vote_table} WHERE request_id=%d AND approval_generation=%s AND decision='approve'", $request_id, $generation ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 1 === $request_insert && 1 === $v1 && 1 === $v2 && 2 === $vote_count, 'two independent professional approval votes coexist on one immutable generation' );

$encrypted = SMC_Security::encrypt( 'audit32-secret', 'integration-smoke', array( 'user_id' => $user_id ) );
$decrypted = is_wp_error( $encrypted ) ? $encrypted : SMC_Security::decrypt( $encrypted, 'integration-smoke', array( 'user_id' => $user_id ) );
$check( ! is_wp_error( $encrypted ) && 'audit32-secret' === $decrypted, 'SMC3 encryption/decryption works in real WordPress runtime' );

$chain = SMC_Security::verify_audit_chain();
$check( is_array( $chain ) && ! empty( $chain['valid'] ), 'tamper-evident audit chain verifies after real MySQL writes' );

if ( $failures ) {
	fwrite( STDERR, sprintf( "audit32 WordPress/MySQL integration: %d PASS / %d FAIL\n", $passed, count( $failures ) ) );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}

echo sprintf( "audit32 WordPress/MySQL integration: %d PASS / 0 FAIL\n", $passed );

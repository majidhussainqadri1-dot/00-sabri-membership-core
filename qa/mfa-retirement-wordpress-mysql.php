<?php
/**
 * Real WordPress + MariaDB acceptance for Founder-approved File 00 MFA retirement.
 * Run with: wp eval-file <repo>/qa/mfa-retirement-wordpress-mysql.php
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
$check( defined( 'SMC_VERSION' ) && '1.2.44' === SMC_VERSION, 'runtime 1.2.44 loaded' );
$check( defined( 'SMC_CONTRACT_VERSION' ) && '1.2.3' === SMC_CONTRACT_VERSION, 'membership contract 1.2.3 loaded' );
$check( defined( 'SMC_DB_VERSION' ) && '1.4.5' === SMC_DB_VERSION, 'database target 1.4.5 loaded' );
$check( class_exists( 'SMC_MFA_Retirement' ), 'MFA retirement runtime loaded' );
$check( ! class_exists( 'SMC_Account_Recovery' ), 'lost-factor recovery runtime absent' );
$check( ! shortcode_exists( 'smc_membership_recovery' ), 'lost-factor recovery shortcode absent' );

$mfa_hooks = array(
	'start_2fa'            => 'handle_start_2fa',
	'finish_2fa'           => 'handle_finish_2fa',
	'challenge_2fa'        => 'handle_challenge_2fa',
	'rotate_recovery'      => 'handle_rotate_recovery',
	'ack_recovery_receipt' => 'handle_ack_recovery_receipt',
);
foreach ( $mfa_hooks as $action => $callback ) {
	$check(
		false === has_action( 'admin_post_smc_' . $action, array( 'SMC_Workflow', $callback ) ),
		'retired handler absent: ' . $action
	);
}

$key = SMC_Security::ensure_key_ready();
$check( ! is_wp_error( $key ), 'encryption/audit key ready' );
$audit = SMC_Installer::ensure_audit_infrastructure();
$check( ! is_wp_error( $audit ), 'audit infrastructure ready' );
SMC_Schema_Compat::reconcile_verification_queue_index();
SMC_Installer::maybe_upgrade();
$check( SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ), 'database migration reached 1.4.5' );
SMC_Schema_Compat::assert_current_queue_indexes();
$check( true === SMC_Installer::audit_infrastructure_ready(), 'audit infrastructure reports ready' );

$user = get_user_by( 'login', 'founder' );
$check( $user instanceof WP_User, 'Founder fixture exists' );
if ( ! $user instanceof WP_User ) {
	fwrite( STDERR, "Founder fixture missing.\n" );
	exit( 3 );
}
wp_set_current_user( $user->ID );

// A prior admin_init in this compound integration run may already have retired
// an empty fixture. Reset only the test completion marker before deliberately
// reseeding obsolete state so this test exercises a fresh retirement pass.
delete_option( SMC_MFA_Retirement::STATE_OPTION );

// Seed representative obsolete factor state only after canonical migration is ready.
update_user_meta( $user->ID, '_smc_2fa_enabled', '1' );
update_user_meta( $user->ID, '_smc_totp_secret_enc', 'legacy-test-envelope' );
update_user_meta( $user->ID, '_smc_totp_pending_enc', 'legacy-pending-envelope' );
update_user_meta( $user->ID, '_smc_totp_pending_expires', time() + HOUR_IN_SECONDS );
update_user_meta( $user->ID, '_smc_recovery_receipt_v2', array( 'version' => 2, 'expires' => time() + HOUR_IN_SECONDS, 'envelope' => 'legacy-receipt' ) );
update_user_meta( $user->ID, '_smc_revalidation_required_at', time() );

$recovery_table = $wpdb->prefix . 'smc_recovery_codes';
$factor_table   = $wpdb->prefix . 'smc_mfa_factor_state';
$session_table  = $wpdb->prefix . 'smc_auth_sessions';
$repair_table   = $wpdb->prefix . 'smc_application_repairs';
$now            = current_time( 'mysql', true );

if ( $recovery_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $recovery_table ) ) ) ) {
	$wpdb->insert(
		$recovery_table,
		array(
			'user_id'          => $user->ID,
			'code_lookup_hash' => hash( 'sha256', 'mfa-retirement-lookup' ),
			'code_hash'        => wp_hash_password( 'RETIREMENT123' ),
			'created_at'       => $now,
		),
		array( '%d', '%s', '%s', '%s' )
	);
}
if ( $factor_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $factor_table ) ) ) ) {
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$factor_table} (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=VALUES(last_totp_slice),updated_at=VALUES(updated_at)",
			$user->ID,
			123456,
			$now
		)
	);
}
if ( $repair_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $repair_table ) ) ) ) {
	$columns = $wpdb->get_col( "DESCRIBE {$repair_table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( in_array( 'repair_type', $columns, true ) && in_array( 'status', $columns, true ) ) {
		$defaults = array(
			'user_id'     => $user->ID,
			'repair_type' => 'lost_factor_recovery',
			'status'      => 'requested',
			'details'     => '{}',
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		$insert = array();
		$formats = array();
		foreach ( $defaults as $column => $value ) {
			if ( in_array( $column, $columns, true ) ) {
				$insert[ $column ] = $value;
				$formats[] = 'user_id' === $column ? '%d' : '%s';
			}
		}
		if ( $insert ) {
			$wpdb->insert( $repair_table, $insert, $formats );
		}
	}
}

$audit_table = $wpdb->prefix . 'smc_audit_log';
$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
SMC_MFA_Retirement::retire_legacy_factor_state();
$audit_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach ( array(
	'_smc_2fa_enabled',
	'_smc_totp_secret_enc',
	'_smc_totp_secret',
	'_smc_totp_pending_enc',
	'_smc_totp_pending_expires',
	'_smc_factor_replace_receipt',
	'_smc_recovery_receipt_v2',
	'_smc_recovery_receipt',
	'_smc_recovery_receipt_expires',
	'_smc_revalidation_required_at',
) as $meta_key ) {
	$check( ! metadata_exists( 'user', $user->ID, $meta_key ), 'obsolete factor meta removed: ' . $meta_key );
}

if ( $recovery_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $recovery_table ) ) ) ) {
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$recovery_table}" ), 'legacy recovery-code rows removed' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
if ( $factor_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $factor_table ) ) ) ) {
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$factor_table}" ), 'legacy TOTP replay rows removed' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
if ( $session_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $session_table ) ) ) ) {
	$check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$session_table} WHERE last_totp_slice IS NOT NULL" ), 'legacy TOTP slices removed from session ledger' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
if ( $repair_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $repair_table ) ) ) ) {
	$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$repair_table} WHERE repair_type=%s AND status IN ('requested','cooling','approved')", 'lost_factor_recovery' ) ), 'active lost-factor recovery cases retired' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$state = get_option( SMC_MFA_Retirement::STATE_OPTION, array() );
$check( is_array( $state ) && SMC_VERSION === (string) ( $state['release'] ?? '' ) && ! empty( $state['completed_at'] ), 'retirement state recorded' );
$check( $audit_after >= $audit_before + 1, 'historical audit chain preserved and retirement append added' );
$check( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$audit_table} WHERE action=%s ORDER BY id DESC LIMIT 1", 'file00_mfa_system_retired' ) ), 'MFA retirement audit event exists' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$chain = SMC_Security::verify_audit_chain();
$check( is_array( $chain ) && ! empty( $chain['valid'] ), 'audit chain verifies after MFA retirement' );

$assertions = SMC_Contracts::assertions( $user->ID );
$check( isset( $assertions['mfa_required'] ) && false === $assertions['mfa_required'], 'canonical assertions declare MFA not required' );
$check( 'none' === (string) ( $assertions['mfa_owner'] ?? '' ), 'canonical assertions expose no MFA owner' );
$check( false === (bool) ( $assertions['two_factor_ready'] ?? true ), 'canonical assertions do not claim an authenticator factor' );
$check( false === (bool) ( $assertions['session_two_factor'] ?? true ), 'canonical assertions do not claim session MFA' );

$security_html = do_shortcode( '[smc_membership_security]' );
$check( false !== strpos( $security_html, 'no longer uses two-factor authentication' ), 'Membership Security explains MFA retirement' );
$check( false === strpos( $security_html, 'name="code"' ), 'Membership Security exposes no MFA code field' );
$check( false === strpos( $security_html, 'Begin Authenticator Setup' ), 'Membership Security exposes no authenticator setup control' );
$check( false === strpos( $security_html, 'Open Governed Recovery' ), 'Membership Security exposes no lost-factor recovery control' );

if ( $failures ) {
	fwrite( STDERR, sprintf( "mfa-retirement WordPress/MariaDB: %d PASS / %d FAIL\n", $passed, count( $failures ) ) );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}
echo sprintf( "mfa-retirement WordPress/MariaDB: %d PASS / 0 FAIL\n", $passed );

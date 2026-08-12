<?php
/**
 * Real WordPress/MariaDB regression for bootstrap crash consistency.
 *
 * Reproduces the partial-write state where the institutional repair marker is
 * already current but the release marker is stale. The next protected
 * bootstrap pass must self-heal the release marker without claiming readiness
 * before DB + institutional repair are complete.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress not loaded.\n" );
	exit( 2 );
}

$failures = array();
$passed   = 0;
$check = static function ( $condition, $label ) use ( &$failures, &$passed ) {
	if ( $condition ) {
		++$passed;
		echo "PASS {$label}\n";
		return;
	}
	$failures[] = $label;
	echo "FAIL {$label}\n";
};

$key = SMC_Security::ensure_key_ready();
$check( ! is_wp_error( $key ), 'encryption key ready' );
$audit = SMC_Installer::ensure_audit_infrastructure();
$check( ! is_wp_error( $audit ), 'audit infrastructure ready' );
SMC_Schema_Compat::reconcile_verification_queue_index();
SMC_Installer::maybe_upgrade();
$check( SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ), 'database is at exact target before release finalization' );

$original_repair  = get_option( 'smc_institutional_repair_version', null );
$original_release = get_option( 'smc_release_version', null );
$original_cursor  = get_option( 'smc_institutional_repair_cursor', null );

update_option( 'smc_institutional_repair_cursor', 0, false );
update_option( 'smc_institutional_repair_version', SMC_VERSION, false );
update_option( 'smc_release_version', 'stale-partial-write-marker', false );

$check( SMC_VERSION === (string) get_option( 'smc_institutional_repair_version', '' ), 'partial-write fixture has current repair marker' );
$check( SMC_VERSION !== (string) get_option( 'smc_release_version', '' ), 'partial-write fixture has stale release marker' );

$result = function_exists( 'smc_finalize_institutional_release_state' )
	? smc_finalize_institutional_release_state()
	: false;
$check( true === $result, 'bootstrap finalizer accepts complete repair state' );
$check( SMC_VERSION === (string) get_option( 'smc_release_version', '' ), 'stale release marker self-heals on next bootstrap pass' );
$check( SMC_VERSION === (string) get_option( 'smc_institutional_repair_version', '' ), 'repair marker remains current after self-heal' );

if ( null === $original_repair ) { delete_option( 'smc_institutional_repair_version' ); }
else { update_option( 'smc_institutional_repair_version', $original_repair, false ); }
if ( null === $original_release ) { delete_option( 'smc_release_version' ); }
else { update_option( 'smc_release_version', $original_release, false ); }
if ( null === $original_cursor ) { delete_option( 'smc_institutional_repair_cursor' ); }
else { update_option( 'smc_institutional_repair_cursor', $original_cursor, false ); }

if ( $failures ) {
	fwrite( STDERR, sprintf( "bootstrap consistency: %d PASS / %d FAIL\n", $passed, count( $failures ) ) );
	exit( 1 );
}

echo sprintf( "bootstrap consistency: %d PASS / 0 FAIL\n", $passed );

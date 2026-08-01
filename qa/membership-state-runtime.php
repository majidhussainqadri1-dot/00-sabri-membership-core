<?php
/**
 * Runtime regression for File 00 institutional/application precedence.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'SMC_CONTRACT_VERSION', '1.1.1' );

$GLOBALS['smc_test_rows'] = array();
$GLOBALS['smc_test_users'] = array();
$GLOBALS['smc_test_options'] = array( 'smc_founder_user_id' => 2 );

function __( $text, $domain = '' ) { unset( $domain ); return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { return $GLOBALS['smc_test_options'][ $key ] ?? $default; }
function get_userdata( $user_id ) { return $GLOBALS['smc_test_users'][ (int) $user_id ] ?? false; }
function user_can( $user, $capability ) {
	return is_object( $user ) && ! empty( $user->caps[ $capability ] );
}

final class SMC_Test_DB {
	public string $prefix = 'wp_';
	public function prepare( $query, ...$args ) { unset( $query ); return (int) ( $args[0] ?? 0 ); }
	public function get_row( $prepared, $format ) { unset( $format ); return $GLOBALS['smc_test_rows'][ (int) $prepared ] ?? false; }
}

$wpdb = new SMC_Test_DB();
define( 'ARRAY_A', 'ARRAY_A' );

require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/functions.php';

$failures = array();
$passed = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passed ): void {
	if ( $condition ) { ++$passed; return; }
	$failures[] = $message;
};
$set_user = static function ( int $id, bool $admin = false ): void {
	$GLOBALS['smc_test_users'][ $id ] = (object) array( 'ID' => $id, 'caps' => array( 'manage_options' => $admin ) );
};
$set_row = static function ( int $id, ?string $status, string $type = 'member' ): void {
	if ( null === $status ) { unset( $GLOBALS['smc_test_rows'][ $id ] ); return; }
	$GLOBALS['smc_test_rows'][ $id ] = array( 'user_id' => $id, 'status' => $status, 'membership_type' => $type );
};

$set_user( 1, true );
$set_row( 1, 'draft' );
$state = smc_membership_state( 1 );
$assert( 'verified' === $state['status'], 'Administrator with a legacy draft row must remain institutionally verified.' );
$assert( true === $state['application_exists'] && 'draft' === $state['application_status'], 'Legacy application evidence must remain visible.' );
$assert( true === $state['approved'] && 'administrator' === $state['account_class'], 'Administrator authority must be explicit.' );

$set_user( 2, false );
$set_row( 2, 'under_review', 'doctor' );
$state = smc_membership_state( 2 );
$assert( 'verified' === $state['status'] && true === $state['approved'], 'Founder with a legacy review row must remain institutionally verified.' );
$assert( 'under_review' === $state['application_status'] && 'founder' === $state['account_class'], 'Founder application evidence and class must be retained.' );

foreach ( array( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' ) as $hard_block ) {
	$set_row( 1, $hard_block );
	$state = smc_membership_state( 1 );
	$assert( $hard_block === $state['status'] && false === $state['approved'], 'Institutional hard block must remain controlling: ' . $hard_block );
}

$set_row( 1, 'foreign_status' );
$state = smc_membership_state( 1 );
$assert( 'invalid_application' === $state['status'] && true === $state['application_exists'] && false === $state['approved'], 'Administrator with corrupt application status must fail closed.' );
$assert( 'foreign_status' === $state['application_status'], 'Corrupt status evidence must remain observable without being trusted.' );

$set_user( 3, false );
$set_row( 3, 'draft' );
$state = smc_membership_state( 3 );
$assert( 'draft' === $state['status'] && false === $state['approved'] && false === $state['institutional_account'], 'Ordinary draft application must remain denied.' );

$set_row( 3, 'approved' );
$state = smc_membership_state( 3 );
$assert( 'approved' === $state['status'] && true === $state['approved'], 'Ordinary approved application must remain approved.' );

$set_row( 3, 'unknown_state' );
$state = smc_membership_state( 3 );
$assert( 'invalid_application' === $state['status'] && true === $state['application_exists'] && false === $state['approved'], 'Ordinary corrupt application must not collapse into not_enrolled.' );

$set_user( 4, false );
$set_row( 4, null );
$state = smc_membership_state( 4 );
$assert( 'not_enrolled' === $state['status'] && false === $state['application_exists'], 'Ordinary account without an application must remain not enrolled.' );

$set_row( 1, 'submitted' );
$assert( 'verified' === smc_user_status( 1 ), 'Legacy status API must preserve institutional precedence.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'Membership-state runtime assertions passed: ' . $passed . PHP_EOL;

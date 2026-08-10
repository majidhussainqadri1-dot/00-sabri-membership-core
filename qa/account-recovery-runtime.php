<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['smc_test_founder'] = 30;
$GLOBALS['smc_test_admins'] = array( 20 => true );

function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $tag, $value ) { return $value; }
function smc_is_founder( $user_id ) { return absint( $user_id ) === (int) $GLOBALS['smc_test_founder']; }
function get_userdata( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) { return false; }
	return (object) array( 'ID' => $user_id, 'is_admin' => ! empty( $GLOBALS['smc_test_admins'][ $user_id ] ) );
}
function user_can( $user, $capability ) { return 'manage_options' === $capability && ! empty( $user->is_admin ); }

require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-account-recovery.php';

function invoke_private( $method, array $args = array() ) {
	$reflection = new ReflectionMethod( 'SMC_Account_Recovery', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$ordinary = invoke_private( 'policy_for_user', array( 10 ) );
assert_true( false === $ordinary['privileged'], 'ordinary account must not be privileged' );
assert_true( 1 === $ordinary['required_approvals'], 'ordinary account requires one independent approval' );
assert_true( 3600 === $ordinary['cooling_seconds'], 'ordinary account defaults to one-hour cooling' );

$admin = invoke_private( 'policy_for_user', array( 20 ) );
assert_true( true === $admin['privileged'], 'administrator must be privileged' );
assert_true( 2 === $admin['required_approvals'], 'administrator requires two independent approvals' );
assert_true( 86400 === $admin['cooling_seconds'], 'administrator defaults to 24-hour cooling' );

$founder = invoke_private( 'policy_for_user', array( 30 ) );
assert_true( true === $founder['privileged'], 'Founder must be privileged' );
assert_true( 2 === $founder['required_approvals'], 'Founder requires two independent approvals' );
assert_true( 86400 === $founder['cooling_seconds'], 'Founder defaults to 24-hour cooling' );

$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
$future = gmdate( 'Y-m-d H:i:s', time() + 600 );
$approval_a = array( 'actor_id' => 101, 'reference_hash' => str_repeat( 'a', 64 ) );
$approval_b = array( 'actor_id' => 102, 'reference_hash' => str_repeat( 'b', 64 ) );
$duplicate_actor = array( 'actor_id' => 101, 'reference_hash' => str_repeat( 'c', 64 ) );

$case = array(
	'status' => 'cooling',
	'details_array' => array(
		'required_approvals' => 2,
		'ready_after' => $past,
		'approvals' => array( $approval_a ),
	),
);
assert_true( false === invoke_private( 'case_ready', array( $case ) ), 'one approval must not satisfy privileged recovery' );

$case['details_array']['approvals'][] = $approval_b;
assert_true( true === invoke_private( 'case_ready', array( $case ) ), 'two distinct approvals after cooling must be eligible' );

$case['details_array']['approvals'] = array( $approval_a, $duplicate_actor );
assert_true( 1 === invoke_private( 'approval_count', array( $case['details_array'] ) ), 'duplicate approver must count once' );
assert_true( false === invoke_private( 'case_ready', array( $case ) ), 'duplicate approver must not satisfy dual approval' );

$case['details_array']['approvals'] = array( $approval_a, $approval_b );
$case['details_array']['ready_after'] = $future;
assert_true( false === invoke_private( 'case_ready', array( $case ) ), 'cooling period must be enforced even with approvals' );

$case['details_array']['ready_after'] = $past;
$case['status'] = 'requested';
assert_true( false === invoke_private( 'case_ready', array( $case ) ), 'unapproved request state must not complete' );

fwrite( STDOUT, "account-recovery-runtime: PASS\n" );

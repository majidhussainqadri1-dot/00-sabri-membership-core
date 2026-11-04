<?php
/**
 * File00 V12: real WordPress/MariaDB privilege boundary for File04 attribution.
 * Executed only by the exact-source GitHub Actions WordPress fixture.
 * A deleted/unknown author is an attribution record, never a subscriber.
 */
defined( 'ABSPATH' ) || exit;

$failures = array();
$passed   = 0;
$check    = static function ( $ok, $label ) use ( &$failures, &$passed ) {
	if ( $ok ) {
		$passed++;
		echo 'PASS File04 attribution: ' . $label . "\n";
	} else {
		$failures[] = $label;
		fwrite( STDERR, 'FAIL File04 attribution: ' . $label . "\n" );
	}
};

$admin = get_user_by( 'login', 'founder' );
$role  = get_role( 'administrator' );
if ( ! $admin || ! $role || ! class_exists( 'SMC_CF01_Contract' ) ) {
	fwrite( STDERR, "File04 attribution prerequisite missing: admin/role/File00 contract\n" );
	exit( 2 );
}
$capability = 'sabri_file04_run_migrations';
$previously_granted = $role->has_cap( $capability );
if ( ! $previously_granted ) {
	$role->add_cap( $capability );
}
wp_set_current_user( $admin->ID );

$request = array(
	'file_number'      => '04',
	'legacy_id'        => 98001,
	'legacy_author_id' => 0,
);
ksort( $request, SORT_STRING );
$request['request_digest'] = hash( 'sha256', wp_json_encode( $request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

$receipt = SMC_CF01_Contract::file04_legacy_author_placeholder( null, $request );
$check( is_array( $receipt ) && ! empty( $receipt['verified'] ) && ! empty( $receipt['placeholder'] ), 'creation yields an authorized File00 placeholder receipt' );
$id   = absint( $receipt['user_id'] ?? 0 );
$user = $id ? get_userdata( $id ) : false;
$check( $user instanceof WP_User && 'sabri_legacy_author_placeholder' === $user->user_login, 'created WordPress identity has only the reserved login' );
$check( $user instanceof WP_User && empty( $user->roles ) && empty( $user->caps ), 'fresh attribution account has zero roles and zero direct capabilities' );
$check( $id > 0 && ! user_can( $id, 'read' ) && ! user_can( $id, 'edit_posts' ), 'fresh attribution account cannot inherit subscriber or editor powers' );
$check( $id > 0 && 0 === SMC_CF01_Contract::block_placeholder_session( $id ), 'cookie-current-user resolution denies attribution identity' );
$check( $id > 0 && is_wp_error( SMC_CF01_Contract::block_placeholder_login( $user ) ), 'password authentication denies attribution identity' );

if ( $user instanceof WP_User ) {
	// Historical File00 builds issued the subscriber role. Reuse must
	// remove this role only for the exact, marked and audited sentinel.
	$user->set_role( 'subscriber' );
	clean_user_cache( $id );
	$before = get_userdata( $id );
	$check( $before && in_array( 'subscriber', $before->roles, true ), 'historical subscriber-role fixture was installed' );

	$reused = SMC_CF01_Contract::file04_legacy_author_placeholder( null, $request );
	clean_user_cache( $id );
	$after = get_userdata( $id );
	$check( is_array( $reused ) && ! empty( $reused['verified'] ) && $id === (int) ( $reused['user_id'] ?? 0 ), 'previously audited sentinel remains available after safe role retirement' );
	$check( $after instanceof WP_User && empty( $after->roles ) && empty( $after->caps ), 'historical subscriber-role is durably removed' );

	// set_role('') must not silently remove unrelated direct capabilities.
	// A contaminated sentinel is denied until an operator addresses it.
	$after->add_cap( 'edit_posts', true );
	clean_user_cache( $id );
	$contaminated = SMC_CF01_Contract::file04_legacy_author_placeholder( null, $request );
	$check( is_array( $contaminated ) && empty( $contaminated['verified'] ), 'unexpected direct capabilities cannot be accepted as attribution authority' );
	$bad_user = get_userdata( $id );
	if ( $bad_user instanceof WP_User ) {
		$bad_user->remove_cap( 'edit_posts' );
	}
	clean_user_cache( $id );
	$restored = SMC_CF01_Contract::file04_legacy_author_placeholder( null, $request );
	$check( is_array( $restored ) && ! empty( $restored['verified'] ), 'clean no-role identity can be used again after removal of direct-cap contamination' );
}

$forged = $request;
$forged['legacy_author_id'] = 41;
$blocked = SMC_CF01_Contract::file04_legacy_author_placeholder( array( 'verified' => true ), $forged );
$check( is_array( $blocked ) && empty( $blocked['verified'] ), 'existing sentinel does not launder mismatched request digest' );

if ( ! $previously_granted ) {
	$role->remove_cap( $capability );
}
wp_set_current_user( 0 );

if ( $failures ) {
	fwrite( STDERR, 'File00 real WordPress File04 attribution ' . $passed . ' PASS / ' . count( $failures ) . " FAIL\n" );
	exit( 1 );
}
echo 'File00 real WordPress File04 attribution ' . $passed . " PASS / 0 FAIL\n";

<?php
/**
 * Real pinned File04 and File08 source consumers against File00 inside actual
 * WordPress/MariaDB. This is a source-class integration fixture, NOT evidence
 * of activating or migrating both external plugins as installed products.
 */
defined( 'ABSPATH' ) || exit;
$file04 = getenv( 'FILE04_SOURCE_ROOT' );
$file08 = getenv( 'FILE08_SOURCE_ROOT' );
if ( ! is_string( $file04 ) || ! is_dir( $file04 ) || ! is_string( $file08 ) || ! is_dir( $file08 ) ) {
	fwrite( STDERR, "Pinned File04/File08 source roots are mandatory\n" );
	exit( 2 );
}
require_once $file04 . '/includes/class-snfla-checksum.php';
require_once $file04 . '/includes/class-snfla-plan-completion.php';
require_once $file08 . '/includes/class-wca-authorization.php';
require_once $file08 . '/includes/class-wca-future24.php';

$failures = array();
$passed   = 0;
$check = static function ( $ok, $reason ) use ( &$passed, &$failures ) {
	if ( $ok ) {
		$passed++;
		echo 'PASS real consumer: ' . $reason . "\n";
	} else {
		$failures[] = $reason;
		fwrite( STDERR, 'FAIL real consumer: ' . $reason . "\n" );
	}
};
$founder = get_user_by( 'login', 'founder' );
$role = get_role( 'administrator' );
if ( ! $founder || ! $role ) {
	fwrite( STDERR, "Real consumer fixture administrator unavailable\n" );
	exit( 2 );
}
$cap = 'sabri_file04_run_migrations';
$had_cap = $role->has_cap( $cap );
if ( ! $had_cap ) { $role->add_cap( $cap ); }
wp_set_current_user( $founder->ID );

$known_id = wp_insert_post(
	array(
		'post_type' => 'post',
		'post_status' => 'draft',
		'post_title' => 'File04 immutable source author CI',
		'post_content' => 'Synthetic integration case; no live content.',
		'post_author' => $founder->ID,
	),
	true
);
$check( ! is_wp_error( $known_id ) && absint( $known_id ) > 0, 'synthetic legacy post created in real WordPress' );
if ( ! is_wp_error( $known_id ) && absint( $known_id ) > 0 ) {
	$known = SNFLA_Plan_Completion::authorship_preflight( $known_id );
	$uuid = SMC_CF01_Contract::ensure_subject_uuid( $founder->ID );
	$check( is_array( $known ) && ! empty( $known['verified'] ) && empty( $known['placeholder'] )
		&& (int) ( $known['user_id'] ?? 0 ) === (int) $founder->ID
		&& hash_equals( $uuid, (string) ( $known['platform_uuid'] ?? '' ) ),
		'actual File04 current-main known-author consumer uses File00 canonical UUID' );

	$gone = SNFLA_Plan_Completion::authorship_preflight( $known_id, 991337 );
	$sentinel_id = absint( $gone['user_id'] ?? 0 );
	$check( is_array( $gone ) && ! empty( $gone['verified'] ) && ! empty( $gone['placeholder'] )
		&& $sentinel_id > 0 && ! smc_application( $sentinel_id ),
		'actual File04 current-main deleted-author consumer uses nonmember governed sentinel' );
	$check( $sentinel_id > 0 && 0 === SMC_CF01_Contract::block_placeholder_session( $sentinel_id ),
		'File04 returned sentinel cannot become a current login principal' );

	$role->remove_cap( $cap );
	// WordPress caches the current WP_User::allcaps within a request.
	// Mimic the next protected request after role change instead of
	// treating an intentionally stale in-memory auth object as DB truth.
	wp_set_current_user( 0 );
	clean_user_cache( $founder->ID );
	wp_set_current_user( $founder->ID );
	$denied = SNFLA_Plan_Completion::authorship_preflight( $known_id );
	$check( is_wp_error( $denied ) && 'snfla_author_platform_uuid_unresolved' === $denied->get_error_code(),
		'real File04 consumer denies a known-author request without current migration authority' );
	$denied_ghost = SNFLA_Plan_Completion::authorship_preflight( $known_id, 991337 );
	$check( is_wp_error( $denied_ghost ) && 'snfla_author_placeholder_contract_required' === $denied_ghost->get_error_code(),
		'real File04 consumer denies deleted-author request without migration authority' );
	$role->add_cap( $cap );
	wp_set_current_user( 0 );
	clean_user_cache( $founder->ID );
	wp_set_current_user( $founder->ID );

	wp_set_current_user( 0 );
	$guest = SNFLA_Plan_Completion::authorship_preflight( $known_id );
	$check( is_wp_error( $guest ), 'anonymous real File04 consumer may not resolve an author UUID' );
	wp_set_current_user( $founder->ID );

	$reflection = new ReflectionMethod( 'WCA_Future24', 'subject_user_id' );
	$reflection->setAccessible( true );
	$check( $reflection->invoke( null, $uuid ) === (int) $founder->ID,
		'actual File08 PR29 consumer resolves one unique canonical File00 subject in real MySQL' );
	$check( $reflection->invoke( null, 'not-a-uuid' ) === 0,
		'actual File08 consumer rejects malformed subject' );

	// Temporarily create a usermeta collision, then restore the test DB.
	$other = wp_insert_user( array(
		'user_login' => 'file08_v12_collision_fixture',
		'user_email' => 'file08-v12-collision@example.test',
		'user_pass' => wp_generate_password( 32, true, true ),
		'role' => '',
	) );
	if ( is_wp_error( $other ) ) {
		$check( false, 'second WordPress user created for duplicate-subject negative test' );
	} else {
		$check( true, 'second WordPress user created for duplicate-subject negative test' );
		$prior = get_user_meta( $other, '_smc_platform_uuid_v1', true );
		update_user_meta( $other, '_smc_platform_uuid_v1', $uuid );
		$check( $reflection->invoke( null, $uuid ) === 0,
			'actual File08 consumer fails closed when canonical subject matches 2 WordPress users' );
		update_user_meta( $other, '_smc_platform_uuid_v1', $prior );
		$check( $reflection->invoke( null, $uuid ) === (int) $founder->ID,
			'File08 canonical lookup recovers after duplicate usermeta is removed' );
	}
}
if ( ! $had_cap ) { $role->remove_cap( $cap ); }
wp_set_current_user( 0 );

if ( $failures ) {
	fwrite( STDERR, 'File00+File04+File08 real-source WordPress integration ' . $passed . ' PASS / ' . count( $failures ) . " FAIL\n" );
	exit( 1 );
}
echo 'File00+File04+File08 real-source WordPress integration ' . $passed . " PASS / 0 FAIL\n";

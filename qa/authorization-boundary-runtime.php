<?php
/**
 * Runtime regression for File 00 authorization boundary 1.2.4.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_User {
	public $ID;
	public $allcaps;
	public function __construct( $id, $allcaps = array() ) {
		$this->ID = (int) $id;
		$this->allcaps = $allcaps;
	}
}

final class SMC_Contracts {
	public static function assertions( $user_id ) {
		return $GLOBALS['smc_test_assertions'][ (int) $user_id ];
	}
}

final class SMC_Test_Stop extends RuntimeException {
	public $status;
	public function __construct( $message, $status ) {
		parent::__construct( $message );
		$this->status = (int) $status;
	}
}

$GLOBALS['smc_test_current_user'] = 1;
$GLOBALS['smc_test_logged_in'] = true;
$GLOBALS['smc_test_admin'] = false;
$GLOBALS['smc_test_founder'] = 0;
$GLOBALS['smc_test_redirect'] = '';
$GLOBALS['smc_test_states'] = array();
$GLOBALS['smc_test_assertions'] = array();
$GLOBALS['smc_test_users'] = array();

function add_action( ...$args ) { unset( $args ); }
function remove_action( ...$args ) { unset( $args ); }
function add_filter( ...$args ) { unset( $args ); }
function remove_filter( ...$args ) { unset( $args ); }
function apply_filters( $hook, $value, ...$args ) { unset( $hook, $args ); return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function smc_membership_state( $user_id ) { return $GLOBALS['smc_test_states'][ (int) $user_id ]; }
function smc_is_membership_page() { return false; }
function wp_doing_cron() { return false; }
function is_user_logged_in() { return (bool) $GLOBALS['smc_test_logged_in']; }
function is_admin() { return (bool) $GLOBALS['smc_test_admin']; }
function get_current_user_id() { return (int) $GLOBALS['smc_test_current_user']; }
function get_userdata( $user_id ) { return $GLOBALS['smc_test_users'][ (int) $user_id ] ?? false; }
function user_can( $user, $cap ) { return $user instanceof WP_User && ! empty( $user->allcaps[ $cap ] ); }
function smc_page_url( $key, $fallback = '/' ) { unset( $fallback ); return '/membership-' . $key . '/'; }
function wp_safe_redirect( $url ) { $GLOBALS['smc_test_redirect'] = $url; throw new SMC_Test_Stop( 'redirect', 302 ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function rest_get_url_prefix() { return 'wp-json'; }
function __( $text, $domain = '' ) { unset( $domain ); return $text; }
function esc_html__( $text, $domain = '' ) { unset( $domain ); return $text; }
function wp_die( $message, $title = '', $args = array() ) { unset( $title ); throw new SMC_Test_Stop( (string) $message, (int) ( $args['response'] ?? 500 ) ); }
function smc_founder_user_id() { return (int) $GLOBALS['smc_test_founder']; }

require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authorization.php';

$failures = array();
$passed = 0;
function expect_true( $condition, $name ) {
	global $failures, $passed;
	if ( $condition ) { ++$passed; } else { $failures[] = $name; }
}

function set_user_case( $id, $status, $approved, $eligible, $session, $guardian, $admin = false, $institutional = false, $email = true, $phone = true ) {
	$GLOBALS['smc_test_current_user'] = $id;
	$GLOBALS['smc_test_states'][ $id ] = array( 'status' => $status );
	$GLOBALS['smc_test_assertions'][ $id ] = array(
		'status' => $status,
		'approved' => $approved,
		'eligible' => $eligible,
		'session_two_factor' => $session,
		'guardian_verified' => $guardian,
		'institutional_account' => $institutional,
		'email_verified' => $email,
		'phone_verified' => $phone,
	);
	$GLOBALS['smc_test_users'][ $id ] = new WP_User( $id, $admin ? array( 'manage_options' => true ) : array() );
}

set_user_case( 1, 'approved', true, true, true, true, false );
set_user_case( 2, 'suspended', false, false, false, true, true, true );
set_user_case( 3, 'approved', true, true, true, false, false );
set_user_case( 4, 'verified', true, true, true, true, true, true, false, false );
set_user_case( 5, 'draft', false, false, false, true, false );
set_user_case( 6, 'invalid_application', false, false, false, true, true, true );
set_user_case( 7, 'approved', true, true, true, true, false, false, false, true );

expect_true( SMC_Authorization::is_hard_blocked( 2 ), 'Suspended institutional account is hard-blocked' );
expect_true( SMC_Authorization::is_hard_blocked( 6 ), 'Invalid institutional application is fail-closed' );
expect_true( ! SMC_Authorization::is_hard_blocked( 4 ), 'Verified institutional account is not hard-blocked' );

$caps = SMC_Authorization::filter_capabilities(
	array( 'manage_options' => true, 'publish_posts' => true, 'smc_manage_membership' => true ),
	array( 'publish_posts' ),
	array(),
	$GLOBALS['smc_test_users'][2]
);
expect_true( false === $caps['publish_posts'], 'Hard-blocked administrator loses publishing capability' );
expect_true( false === $caps['smc_manage_membership'], 'Hard-blocked administrator loses File 00 management capability' );
expect_true( true === $caps['manage_options'], 'File 00 does not rewrite core WordPress manage_options' );

$caps = SMC_Authorization::filter_capabilities(
	array( 'publish_posts' => true ),
	array( 'publish_posts' ),
	array(),
	$GLOBALS['smc_test_users'][3]
);
expect_true( false === $caps['publish_posts'], 'Unverified guardian blocks protected capability' );

$caps = SMC_Authorization::filter_capabilities(
	array( 'publish_posts' => true ),
	array( 'publish_posts' ),
	array(),
	$GLOBALS['smc_test_users'][7]
);
expect_true( false === $caps['publish_posts'], 'Unverified ordinary contact ownership blocks protected capability' );

$caps = SMC_Authorization::filter_capabilities(
	array( 'manage_options' => true, 'publish_posts' => true ),
	array( 'publish_posts' ),
	array(),
	$GLOBALS['smc_test_users'][4]
);
expect_true( true === $caps['publish_posts'], 'Verified administrator with current challenge keeps protected capability' );

$_SERVER['REQUEST_URI'] = '/wp-json/sabri/v1/write';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['smc_test_current_user'] = 5;
expect_true( null === SMC_Authorization::enforce_rest_state( null ), 'Safe authenticated REST read remains available' );

$_SERVER['REQUEST_METHOD'] = 'POST';
$error = SMC_Authorization::enforce_rest_state( null );
expect_true( $error instanceof WP_Error && 'smc_membership_restricted' === $error->get_error_code(), 'Unapproved REST mutation is denied' );

$GLOBALS['smc_test_current_user'] = 2;
$error = SMC_Authorization::enforce_rest_state( null );
expect_true( $error instanceof WP_Error && 'smc_membership_hard_block' === $error->get_error_code(), 'Hard-blocked institutional REST mutation is denied' );

$GLOBALS['smc_test_current_user'] = 4;
expect_true( null === SMC_Authorization::enforce_rest_state( null ), 'Verified institutional REST mutation is allowed' );

// Exact recovery allowlist: a self-service recovery action remains reachable.
$GLOBALS['smc_test_current_user'] = 2;
$GLOBALS['smc_test_admin'] = true;
$_REQUEST = array( 'action' => 'smc_submit_application' );
try {
	SMC_Authorization::enforce_admin_state();
	expect_true( true, 'Exact recovery action remains reachable' );
} catch ( SMC_Test_Stop $error ) {
	expect_true( false, 'Exact recovery action remains reachable' );
}

// A non-recovery smc_* action no longer inherits a broad prefix bypass.
$_REQUEST = array( 'action' => 'smc_review_transition' );
try {
	SMC_Authorization::enforce_admin_state();
	expect_true( false, 'Non-recovery smc action is denied for a hard-blocked administrator' );
} catch ( SMC_Test_Stop $error ) {
	expect_true( 302 === $error->status, 'Non-recovery smc action is denied for a hard-blocked administrator' );
}

// Founder reassignment is immutable through the ordinary settings form.
$GLOBALS['smc_test_current_user'] = 4;
$GLOBALS['smc_test_founder'] = 10;
$_REQUEST = array( 'action' => 'smc_save_founder' );
$_POST = array( 'founder_user_id' => 11 );
try {
	SMC_Authorization::enforce_admin_state();
	expect_true( false, 'Ordinary Founder reassignment is locked' );
} catch ( SMC_Test_Stop $error ) {
	expect_true( 409 === $error->status, 'Ordinary Founder reassignment is locked' );
}

if ( $failures ) {
	fwrite( STDERR, 'authorization boundary runtime: ' . $passed . ' PASS, ' . count( $failures ) . " FAIL\n" );
	foreach ( $failures as $failure ) { fwrite( STDERR, '- ' . $failure . "\n" ); }
	exit( 1 );
}

echo 'authorization boundary runtime: ' . $passed . " PASS, 0 FAIL\n";

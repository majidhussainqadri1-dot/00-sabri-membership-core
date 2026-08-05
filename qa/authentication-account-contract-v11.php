<?php
/**
 * No-network policy checks for smc.authentication-account v1.1.0.
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }

function smc_v11_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php';

$validate = new ReflectionMethod( 'SMC_Authentication_Contract_V11', 'validate_extra_fields' );
$validate->setAccessible( true );

$valid = array(
	'city'                     => 'Gujrat',
	'account_type'             => 'doctor',
	'authentication_method'    => 'google',
	'ethical_conduct_version'  => '2026-08-06',
	'profile_photo_required'   => true,
	'google_subject'           => 'google-subject-123456',
	'google_email_verified'    => true,
	'google_picture_candidate' => 'https://example.test/photo.jpg',
);
$result = $validate->invoke( null, $valid );
smc_v11_assert( is_array( $result ), 'valid v1.1 parent-plan fields were rejected' );
smc_v11_assert( 'Gujrat' === $result['city'], 'city was not retained' );
smc_v11_assert( 'doctor' === $result['account_type'], 'account type was not retained' );
smc_v11_assert( 'google' === $result['authentication_method'], 'Google registration method was not retained' );

$bad = $valid;
$bad['city'] = '';
smc_v11_assert( is_wp_error( $validate->invoke( null, $bad ) ), 'empty city was accepted' );

$bad = $valid;
$bad['account_type'] = 'administrator';
smc_v11_assert( is_wp_error( $validate->invoke( null, $bad ) ), 'privileged undeclared account type was accepted' );

$bad = $valid;
$bad['ethical_conduct_version'] = '';
smc_v11_assert( is_wp_error( $validate->invoke( null, $bad ) ), 'missing ethical consent was accepted' );

$bad = $valid;
$bad['profile_photo_required'] = false;
smc_v11_assert( is_wp_error( $validate->invoke( null, $bad ) ), 'missing profile-photo completion requirement was accepted' );

$bad = $valid;
$bad['google_email_verified'] = false;
smc_v11_assert( is_wp_error( $validate->invoke( null, $bad ) ), 'unverified Google email was accepted' );

$password = $valid;
$password['authentication_method'] = 'password';
$password['google_subject'] = '';
$password['google_email_verified'] = false;
smc_v11_assert( is_array( $validate->invoke( null, $password ) ), 'password registration was incorrectly made dependent on Google' );

$manifest = SMC_Authentication_Contract_V11::manifest();
smc_v11_assert( '1.1.0' === $manifest['contract_version'], 'v1.1 contract version is wrong' );
foreach ( array( 'city', 'account_type', 'ethical_conduct_version', 'profile_photo_required', 'authentication_method', 'google_subject' ) as $field ) {
	smc_v11_assert( in_array( $field, $manifest['fields'], true ), "manifest omits {$field}" );
}
smc_v11_assert( true === $manifest['fail_closed'], 'v1.1 manifest is not fail closed' );

$source = file_get_contents( dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php' );
foreach ( array( 'SMC_Security::encrypt', 'smc_profile_photo_complete', 'smc_profile_completion_route', 'ethical_conduct', 'registration_extra_quarantined' ) as $marker ) {
	smc_v11_assert( false !== strpos( $source, $marker ), "source omits {$marker}" );
}

echo "File 00 authentication-account v1.1.0 policy checks passed.\n";

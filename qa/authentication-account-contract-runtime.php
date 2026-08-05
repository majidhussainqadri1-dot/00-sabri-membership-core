<?php
/**
 * No-network policy checks for the File 00 → File 02 account contract.
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_User {}
function __( $text, $domain = '' ) { return $text; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return strtolower( trim( (string) filter_var( $value, FILTER_SANITIZE_EMAIL ) ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function apply_filters( $hook, $value ) { return $value; }
function wp_salt( $scheme = 'auth' ) { return hash( 'sha256', 'smc-auth-contract-test|' . $scheme ); }
function smc_allowed_genders() { return array( 'male' => 'Male', 'female' => 'Female' ); }
function smc_policy() { return array( 'male_minimum_age' => 15, 'female_minimum_age' => 12, 'guardian_required_under' => 18, 'version' => 'test' ); }
function smc_age_from_dob( $dob ) {
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $dob, new DateTimeZone( 'UTC' ) );
	$errors = DateTimeImmutable::getLastErrors();
	if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date > new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) ) ) { return false; }
	return (int) $date->diff( new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) ) )->y;
}
function smc_effective_minimum_age( $gender, $country = '' ) { return 'female' === $gender ? 12 : 15; }
function smc_normalize_phone( $value ) {
	$value = preg_replace( '/[\s().-]+/', '', trim( (string) $value ) );
	return preg_match( '/^\+[1-9][0-9]{7,14}$/', $value ) ? $value : new WP_Error( 'phone_invalid', 'Invalid phone.' );
}

function smc_auth_contract_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract.php';

$validate = new ReflectionMethod( 'SMC_Authentication_Contract', 'validate_registration' );
$validate->setAccessible( true );
$country = new ReflectionMethod( 'SMC_Authentication_Contract', 'country_code' );
$country->setAccessible( true );
$response = new ReflectionMethod( 'SMC_Authentication_Contract', 'response' );
$response->setAccessible( true );
$receipt_hash = new ReflectionMethod( 'SMC_Authentication_Contract', 'receipt_hash' );
$receipt_hash->setAccessible( true );
$email_hash = new ReflectionMethod( 'SMC_Authentication_Contract', 'email_hash' );
$email_hash->setAccessible( true );
$receipt_matches = new ReflectionMethod( 'SMC_Authentication_Contract', 'receipt_matches' );
$receipt_matches->setAccessible( true );

$adult = array(
	'name'               => 'Test Member',
	'email'              => 'member@example.test',
	'phone'              => '+923001234567',
	'password'           => 'StrongPassword123',
	'password_confirm'   => 'StrongPassword123',
	'sex'                => 'male',
	'date_of_birth'      => '2000-01-01',
	'address'            => 'Gujrat, Punjab, Pakistan',
	'country'            => 'Pakistan',
	'identity_reference' => '35202-1234567-1',
	'identity_type'      => 'national_id',
	'guardian_reference' => '',
	'terms_version'      => '2026-08-05',
	'privacy_version'    => '2026-08-05',
);
$valid = $validate->invoke( null, $adult );
smc_auth_contract_assert( is_array( $valid ), 'valid adult payload was rejected' );
smc_auth_contract_assert( 'PK' === $valid['country'], 'Pakistan was not normalized to PK' );
smc_auth_contract_assert( '+923001234567' === $valid['phone'], 'E.164 phone normalization failed' );
smc_auth_contract_assert( 'national_id' === $valid['identity_type'], 'identity type was not retained' );

$minor = $adult;
$minor['sex'] = 'female';
$minor['date_of_birth'] = ( new DateTimeImmutable( 'today - 13 years' ) )->format( 'Y-m-d' );
$minor['guardian_reference'] = '';
smc_auth_contract_assert( is_wp_error( $validate->invoke( null, $minor ) ), 'minor without guardian reference was accepted' );
$minor['guardian_reference'] = 'guardian-present';
smc_auth_contract_assert( is_array( $validate->invoke( null, $minor ) ), 'eligible minor with guardian reference was rejected' );

$young = $adult;
$young['date_of_birth'] = ( new DateTimeImmutable( 'today - 14 years' ) )->format( 'Y-m-d' );
smc_auth_contract_assert( is_wp_error( $validate->invoke( null, $young ) ), 'male below platform minimum was accepted' );

$bad_password = $adult;
$bad_password['password'] = 'short';
$bad_password['password_confirm'] = 'short';
smc_auth_contract_assert( is_wp_error( $validate->invoke( null, $bad_password ) ), 'short password was accepted' );

$bad_identity = $adult;
$bad_identity['identity_type'] = 'driver_license';
smc_auth_contract_assert( is_wp_error( $validate->invoke( null, $bad_identity ) ), 'unsupported identity type was accepted' );

smc_auth_contract_assert( 'US' === $country->invoke( null, 'United States of America' ), 'country alias normalization failed' );
smc_auth_contract_assert( 'GB' === $country->invoke( null, 'GB' ), 'ISO country code normalization failed' );
smc_auth_contract_assert( '' === $country->invoke( null, 'Unknown Realm' ), 'unknown country was accepted without an approved adapter' );

$normalized = $response->invoke( null, 'ALLOW', 'Account Registered', array( 'user_id' => 7 ) );
smc_auth_contract_assert( 'smc.authentication-account' === $normalized['contract'], 'contract name is wrong' );
smc_auth_contract_assert( '1.0.0' === $normalized['contract_version'], 'contract version is wrong' );
smc_auth_contract_assert( 'allow' === $normalized['result'], 'result was not normalized' );
smc_auth_contract_assert( 'accountregistered' === $normalized['reason_code'], 'reason code was not normalized' );
smc_auth_contract_assert( 7 === $normalized['user_id'], 'response payload was lost' );

$key_hash = $receipt_hash->invoke( null, 'registration-idempotency-key-12345' );
$email_binding = $email_hash->invoke( null, 'Member@Example.Test' );
$receipt = array( 'receipt_hash' => $key_hash, 'email_hash' => $email_binding );
smc_auth_contract_assert( $receipt_matches->invoke( null, $receipt, $key_hash, 'member@example.test' ), 'valid idempotency receipt did not match' );
smc_auth_contract_assert( ! $receipt_matches->invoke( null, $receipt, str_repeat( '0', 64 ), 'member@example.test' ), 'different idempotency key matched' );
smc_auth_contract_assert( ! $receipt_matches->invoke( null, $receipt, $key_hash, 'other@example.test' ), 'different account email matched' );

$manifest = SMC_Authentication_Contract::manifest();
smc_auth_contract_assert( true === $manifest['fail_closed'], 'manifest does not declare fail-closed behavior' );
smc_auth_contract_assert( in_array( 'mark_email_verified', $manifest['methods'], true ), 'manifest omits email completion' );

echo "File 00 authentication-account contract runtime policy checks passed.\n";

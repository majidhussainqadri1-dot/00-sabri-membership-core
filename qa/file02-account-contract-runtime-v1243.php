<?php
define( 'ABSPATH', __DIR__ . '/' );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function is_wp_error( $value ) { return false; }
function apply_filters( $tag, $value ) { return $value; }
function get_user_meta( $user_id, $key, $single = false ) { return ''; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_validate_redirect( $url, $fallback = '' ) { return 0 === strpos( (string) $url, 'https://example.test/' ) ? $url : $fallback; }
function smc_page_url( $key, $fallback = '' ) { return home_url( $fallback ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
class WP_Error { private $code; public function __construct( $code ) { $this->code = (string) $code; } public function get_error_code() { return $this->code; } }
function smc_account_types() { return array( 'member'=>'Member', 'patient'=>'Patient', 'student'=>'Student', 'doctor'=>'Doctor', 'teacher'=>'Teacher', 'researcher'=>'Researcher', 'pharmacy'=>'Pharmacy', 'clinic'=>'Clinic', 'publisher'=>'Publisher' ); }

class V1243_WPDB_Stub {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return 1; }
}
$GLOBALS['wpdb'] = new V1243_WPDB_Stub();

class SMC_CF01_Contract {
	public static function ensure_subject_uuid( $user_id ) {
		return 7 === absint( $user_id ) ? '123e4567-e89b-42d3-a456-426614174000' : '';
	}
}
class SMC_Authentication_Contract {
	public static function register_account( $payload, $context = array() ) {
		return array( 'contract'=>'smc.authentication-account','contract_version'=>'1.0.0','result'=>'allow','reason_code'=>'account_registered','user_id'=>7 );
	}
	public static function mark_email_verified( $user_id, $email, $context = array() ) {
		return array( 'contract'=>'smc.authentication-account','contract_version'=>'1.0.0','result'=>'allow','reason_code'=>'email_verified','user_id'=>absint($user_id) );
	}
	public static function get_completion_state( $user_id, $context = array() ) {
		return array( 'contract'=>'smc.authentication-account','contract_version'=>'1.0.0','result'=>'allow','reason_code'=>'completion_required','user_id'=>absint($user_id),'missing_steps'=>array('two_factor'),'next_route'=>home_url('/membership-security/') );
	}
}
class SMC_Security {
	public static function encrypt( $v, $p, $c=array() ) { return 'enc'; }
	public static function decrypt( $v, $p, $c=array() ) { return ''; }
	public static function audit( $a, $u, $d=array() ) { return true; }
	public static function revoke_all_sessions( $u, $r='' ) { return true; }
}

require_once dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php';

function v1243_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

foreach ( array( 'register_account', 'mark_email_verified', 'get_completion_state' ) as $method ) {
	v1243_assert( is_callable( array( 'SMC_Authentication_Contract_V11', $method ) ), "v1.1 provider method unavailable: {$method}" );
}

$subject = new ReflectionMethod( 'SMC_Authentication_Contract_V11', 'subject_uuid' );
$subject->setAccessible( true );
v1243_assert( '123e4567-e89b-42d3-a456-426614174000' === $subject->invoke( null, 7 ), 'File 00 canonical subject UUID is not exposed by the v1.1 provider' );
v1243_assert( '' === $subject->invoke( null, 0 ), 'invalid user unexpectedly received a subject UUID' );

$normalize = new ReflectionMethod( 'SMC_Authentication_Contract_V11', 'normalize' );
$normalize->setAccessible( true );
$result = $normalize->invoke( null, array( 'contract'=>'smc.authentication-account','contract_version'=>'1.0.0','result'=>'allow','reason_code'=>'account_registered','user_id'=>7,'subject_uuid'=>'123e4567-e89b-42d3-a456-426614174000' ) );
v1243_assert( 'smc.authentication-account' === $result['contract'], 'provider contract name changed' );
v1243_assert( '1.1.0' === $result['contract_version'], 'provider v1.1 version not applied' );
v1243_assert( 7 === $result['user_id'], 'user ID lost during v1.1 normalization' );
v1243_assert( '123e4567-e89b-42d3-a456-426614174000' === $result['subject_uuid'], 'subject UUID lost during v1.1 normalization' );

$completion = SMC_Authentication_Contract_V11::get_completion_state( 7, array() );
v1243_assert( 'allow' === $completion['result'], 'v1.1 completion did not remain available' );
v1243_assert( ! in_array( 'two_factor', $completion['missing_steps'], true ), 'retired File 00 MFA step leaked through v1.1 provider' );

$validator = new ReflectionMethod( 'SMC_Authentication_Contract_V11', 'validate_extra_fields' );
$validator->setAccessible( true );
foreach ( array_keys( smc_account_types() ) as $canonical_type ) {
	$checked = $validator->invoke( null, array( 'city'=>'Gujrat', 'account_type'=>$canonical_type, 'authentication_method'=>'password', 'ethical_conduct_version'=>'2026-test', 'profile_photo_required'=>true ) );
	v1243_assert( is_array( $checked ) && $canonical_type === $checked['account_type'], 'canonical account type rejected by v1.1 provider: ' . $canonical_type );
}
foreach ( array( 'clinic_staff', 'institution_representative' ) as $legacy_alias ) {
	$checked = $validator->invoke( null, array( 'city'=>'Gujrat', 'account_type'=>$legacy_alias, 'authentication_method'=>'password', 'ethical_conduct_version'=>'2026-test', 'profile_photo_required'=>true ) );
	v1243_assert( ! is_array( $checked ), 'legacy provider-only alias unexpectedly accepted: ' . $legacy_alias );
}

$v11_source = file_get_contents( dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php' );
v1243_assert( false === strpos( $v11_source, 'wp_destroy_all_sessions' ), 'non-canonical session destruction returned' );

echo "File 00 v1.2.44 File 02 runtime compatibility checks passed.\n";

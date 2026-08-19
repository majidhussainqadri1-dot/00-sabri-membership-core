<?php
$file02 = getenv( 'FILE02_ROOT' );
$file02_version = trim( (string) getenv( 'FILE02_VERSION' ) );
if ( ! $file02 ) { fwrite( STDERR, "FILE02_ROOT is required\n" ); exit( 2 ); }
if ( '' === $file02_version || ! preg_match( '/^\d+\.\d+\.\d+$/', $file02_version ) ) { fwrite( STDERR, "FILE02_VERSION is required\n" ); exit( 2 ); }
$consumer_path = rtrim( $file02, '/\\' ) . '/includes/class-sauth-account-contract.php';
$bootstrap_path = rtrim( $file02, '/\\' ) . '/sabri-authentication.php';
$registration_path = rtrim( $file02, '/\\' ) . '/includes/class-sa-registration.php';
if ( ! is_file( $consumer_path ) || ! is_file( $bootstrap_path ) || ! is_file( $registration_path ) ) { fwrite( STDERR, "Pinned File 02 consumer files are missing\n" ); exit( 3 ); }
$consumer = file_get_contents( $consumer_path );
$bootstrap = file_get_contents( $bootstrap_path );
$registration = file_get_contents( $registration_path );
$v11 = file_get_contents( dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php' );
$main = file_get_contents( dirname( __DIR__ ) . '/source/sabri-membership-core/sabri-membership-core.php' );
function cross_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
cross_assert( false !== strpos( $bootstrap, 'Version: ' . $file02_version ), 'unexpected File 02 release identity' );
cross_assert( false !== strpos( $bootstrap, "require_once SAUTH_DIR . 'includes/class-sauth-storage-router.php';" ), 'File 02 storage-router bootstrap correction missing' );
cross_assert( false !== strpos( $consumer, "PROVIDER_NAME        = 'smc.authentication-account'" ), 'File 02 provider name changed' );
cross_assert( false !== strpos( $consumer, "PROVIDER_MIN_VERSION = '1.1.0'" ), 'File 02 provider minimum version changed' );
cross_assert( false !== strpos( $consumer, "class_exists( 'SMC_Authentication_Contract_V11' )" ), 'File 02 provider class expectation changed' );
cross_assert( false !== strpos( $consumer, "defined( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION' )" ), 'File 02 provider constant expectation changed' );
foreach ( array( 'register_account', 'mark_email_verified', 'get_completion_state' ) as $method ) {
	cross_assert( false !== strpos( $consumer, "'{$method}'" ), "File 02 method expectation missing: {$method}" );
	cross_assert( false !== strpos( $v11, "function {$method}" ), "File 00 v1.1 provider method missing: {$method}" );
}
cross_assert( false !== strpos( $consumer, "! self::valid_uuid( \$result['subject_uuid'] ?? '' )" ), 'File 02 registration subject UUID contract changed; review pin before changing File 00' );
cross_assert( false !== strpos( $v11, "\$result['subject_uuid'] = \$subject_uuid" ), 'File 00 v1.1 provider does not emit required subject UUID' );
cross_assert( false !== strpos( $v11, 'SMC_CF01_Contract::ensure_subject_uuid' ), 'File 00 v1.1 provider does not use canonical UUID owner' );
cross_assert( false !== strpos( $v11, "array_diff( \$missing, array( 'two_factor' ) )" ), 'File 00 v1.1 provider does not strip retired MFA completion' );
cross_assert( false !== strpos( $main, "define( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION', '1.1.0' );" ), 'File 00 provider version constant missing' );
foreach ( array( 'member', 'patient', 'student', 'doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher' ) as $type ) { cross_assert( false !== strpos( $registration, "'{$type}'" ), "File 02 canonical account choice missing: {$type}" ); }
foreach ( array( 'clinic_staff', 'institution_representative' ) as $legacy ) { cross_assert( false === strpos( $registration, "'{$legacy}'" ), "File 02 provider-only alias remains: {$legacy}" ); }
cross_assert( false !== strpos( $v11, 'array_keys( smc_account_types() )' ), 'File 00 provider is not bound to canonical taxonomy' );
cross_assert( false === strpos( $main, "array( 'SMC_Authentication_Contract', 'init' )" ), 'legacy v1 helper is incorrectly active' );
echo "Exact File 02 {$file02_version} compatibility boundary passed.\n";

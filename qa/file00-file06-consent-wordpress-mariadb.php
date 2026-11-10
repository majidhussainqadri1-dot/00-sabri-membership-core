<?php
/**
 * File00 ↔ File06 case-consent database integration.
 * Runs only in a disposable WordPress 7.0.1/MariaDB CI installation.
 * Source/dependency acceptance, NOT Hostinger staging or live evidence.
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;
if ( ! class_exists( 'SMC_File06_Case_Consent' ) ) {
    fwrite( STDERR, "File06 case-consent provider not loaded\n" );
    exit( 2 );
}
$failures = array();
$pass = 0;
$check = static function ( $condition, $reason ) use ( &$pass, &$failures ) {
    if ( $condition ) {
        $pass++;
        echo 'PASS real File06 DB: ' . $reason . "\n";
    } else {
        $failures[] = $reason;
        fwrite( STDERR, 'FAIL real File06 DB: ' . $reason . "\n" );
    }
};

$users = array();
foreach ( array( 'first', 'second' ) as $suffix ) {
    $id = wp_insert_user( array(
        'user_login' => 'file06_consent_ci_' . $suffix,
        'user_email' => 'file06-consent-' . $suffix . '@example.test',
        'user_pass'  => wp_generate_password( 28, true, true ),
        'role'       => 'subscriber',
    ) );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, 'Cannot create disposable patient: ' . $id->get_error_code() . "\n" );
        exit( 2 );
    }
    $users[] = absint( $id );
}
list( $patient, $unrelated_patient ) = $users;
$table = $wpdb->prefix . 'smc_consents';
$case_a = '11111111-1111-4111-8111-111111111111';
$case_b = '22222222-2222-4222-8222-222222222222';
$receipt_hash = static function ( $uid, $uuid ) {
    return hash_hmac( 'sha256', 'file06-case-v1|' . $uid . '|' . $uuid, wp_salt( 'auth' ) );
};
$add_receipt = static function ( $uid, $uuid ) use ( $wpdb, $table, $receipt_hash ) {
    $created = $wpdb->insert( $table, array(
        'user_id' => $uid,
        'actor_type' => 'self',
        'actor_reference_hash' => $receipt_hash( $uid, $uuid ),
        'purpose' => 'patient-story-research',
        'locale' => 'ur-PK',
        'channel' => 'file06-case',
        'text_snapshot' => 'CI-only policy text, not public clinical information',
        'text_hash' => hash( 'sha256', 'CI-only policy text, not public clinical information' ),
        'policy_version' => 'ci-policy-1',
        'accepted_at' => current_time( 'mysql', true ),
        'withdrawn_at' => null,
    ) );
    if ( 1 !== (int) $created || (int) $wpdb->insert_id < 1 ) {
        throw new RuntimeException( 'Cannot insert disposable case consent: ' . $wpdb->last_error );
    }
    return (int) $wpdb->insert_id;
};

$first = $add_receipt( $patient, $case_a );
$duplicate = $add_receipt( $patient, $case_a );
$other_case = $add_receipt( $patient, $case_b );
$other_person = $add_receipt( $unrelated_patient, $case_a );
$check( $duplicate !== $first, 'two historical concurrent-active case receipts exist' );
$check( 4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
    'actual smc_consents rows committed to MariaDB' );

$make_request = static function ( $uuid, $id ) {
    $request = new WP_REST_Request( 'POST', '/smc/v1/file06/case-consent/withdraw' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $request->set_body( wp_json_encode( array(
        'research_public_id' => $uuid,
        'evidence_ref' => 'smc-f06-r' . $id,
    ) ) );
    return $request;
};
$events = array();
add_action( 'smc_file06_patient_consent_withdrawn_v1',
    static function ( $uuid, $user, $receipt ) use ( &$events ) {
        $events[] = array( $uuid, $user, $receipt );
    }, 10, 3 );

// Cross-site request forgery and case/receipt mismatches may not mutate rows.
wp_set_current_user( $patient );
$wrong_nonce = $make_request( $case_a, $first );
$wrong_nonce->set_header( 'X-WP-Nonce', 'deliberately-invalid' );
$csrf = SMC_File06_Case_Consent::withdraw( $wrong_nonce );
$check( is_wp_error( $csrf ) &&
    'smc_file06_consent_withdraw_auth' === $csrf->get_error_code(),
    'invalid REST nonce cannot revoke a case' );
$wrong_case = SMC_File06_Case_Consent::withdraw( $make_request( $case_b, $first ) );
$check( is_wp_error( $wrong_case ) &&
    'smc_file06_consent_withdraw_invalid' === $wrong_case->get_error_code(),
    'one study-case UUID cannot revoke a different case receipt' );
$check( null === $wpdb->get_var( $wpdb->prepare(
    "SELECT withdrawn_at FROM {$table} WHERE id=%d", $first
) ), 'invalid nonce and wrong-case attempts left consent unchanged' );

wp_set_current_user( $unrelated_patient );
$forged = SMC_File06_Case_Consent::withdraw( $make_request( $case_a, $first ) );
$check( is_wp_error( $forged ) && 'smc_file06_consent_withdraw_invalid' === $forged->get_error_code(),
    'different patient cannot revoke another patient receipt' );

wp_set_current_user( $patient );
$withdraw = SMC_File06_Case_Consent::withdraw( $make_request( $case_a, $first ) );
$payload = is_wp_error( $withdraw ) ? array() : $withdraw->get_data();
$check( ( $payload['state'] ?? '' ) === 'withdrawn',
    'patient can revoke the requested case consent using real MySQL named lock' );
$check( 2 === (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d,%d) AND withdrawn_at IS NOT NULL",
    $first, $duplicate
) ), 'both case receipts revoked in one database operation' );
$check( 2 === (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d,%d) AND withdrawn_at IS NULL",
    $other_case, $other_person
) ), 'different case and different patient remain untouched' );
$check( count( $events ) === 1 && $events[0][0] === $case_a &&
    $events[0][1] === $patient, 'File06 downstream invalidation emitted' );

$again = SMC_File06_Case_Consent::withdraw( $make_request( $case_a, $first ) );
$check( is_wp_error( $again ), 'revoked receipt cannot be reused for another mutation' );

// A second *independent* database connection holds the same case lock.
// Fail closed rather than alter the still-active unrelated case.
$other = new mysqli( '127.0.0.1', 'wordpress', 'wordpress', 'wordpress', 3306 );
if ( $other->connect_errno ) {
    fwrite( STDERR, "MariaDB contention fixture connection failed\n" );
    exit( 2 );
}
$lock_name = 'smc-f06:' . substr( hash( 'sha256',
    $wpdb->dbname . '|' . $wpdb->prefix . '|' . $patient . '|' . $case_b ), 0, 52 );
$acquired = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $lock_name ) . "',0) AS result" );
$got_lock = $acquired && (int) ( $acquired->fetch_assoc()['result'] ?? 0 ) === 1;
$check( $got_lock, 'independent MariaDB connection holds consent case lock' );
if ( $got_lock ) {
    $busy = SMC_File06_Case_Consent::withdraw( $make_request( $case_b, $other_case ) );
    $check( is_wp_error( $busy ) &&
        'smc_file06_consent_busy' === $busy->get_error_code(),
        'contended case lock rejects mutation without an unsafe fallback' );
    $check( null === $wpdb->get_var( $wpdb->prepare(
        "SELECT withdrawn_at FROM {$table} WHERE id=%d", $other_case
    ) ), 'contended case receipt is unchanged' );
    $other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $lock_name ) . "')" );
}
$other->close();

if ( $failures ) {
    fwrite( STDERR, 'File00/File06 real MariaDB: ' . $pass . ' PASS / ' . count( $failures ) . " FAIL\n" );
    exit( 1 );
}
echo 'File00/File06 real MariaDB: ' . $pass . " PASS / 0 FAIL\n";

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


// Real File00 eligibility/storage fixture with synthetic, named File02/File06
// provider claims. It is NOT a live verified-patient or installed File06 test.
$verified_user = wp_insert_user( array(
    'user_login' => 'file06_consent_verified_ci',
    'user_email' => 'file06-consent-verified@example.test',
    'user_pass'  => wp_generate_password( 28, true, true ),
    'role'       => 'subscriber',
) );
if ( is_wp_error( $verified_user ) || ! smc_application( $verified_user ) ) {
    fwrite( STDERR, "Cannot register File00 real patient eligibility fixture\n" );
    exit( 2 );
}
$verified_user = (int) $verified_user;
$now = current_time( 'mysql', true );
$dob = SMC_Security::encrypt( '1990-01-01', 'date-of-birth',
    array( 'user_id' => $verified_user ) );
$phone = '+923041234567';
$encrypted_phone = SMC_Security::encrypt( $phone, 'membership-phone',
    array( 'user_id' => $verified_user ) );
$check( ! is_wp_error( $dob ) && ! is_wp_error( $encrypted_phone ),
    'real File00 application encrypts patient age and contact evidence' );
if ( is_wp_error( $dob ) || is_wp_error( $encrypted_phone ) ) { exit( 2 ); }
$updated = $wpdb->update( $wpdb->prefix . 'smc_applications', array(
    'status' => 'approved',
    'date_of_birth_enc' => $dob,
    'phone_e164_enc' => $encrypted_phone,
    'gender' => 'male', 'residence_country' => 'PK',
    'guardian_required' => 0,
    'updated_at' => $now,
), array( 'user_id' => $verified_user ) );
$check( 1 === (int) $updated
    && SMC_Contracts::upsert_role_grant( $verified_user, 'member', 'approved', 1, 1 ),
    'synthetic approved File00 membership and role grant persist in real DB' );

foreach ( array( 'email' => 'file06-consent-verified@example.test', 'mobile' => $phone ) as $channel => $target ) {
    $blind = SMC_Security::blind_index( $target, 'contact-target' );
    $inserted = ! is_wp_error( $blind ) && 1 === (int) $wpdb->insert(
        $wpdb->prefix . 'smc_contact_otps', array(
            'user_id' => $verified_user,
            'channel' => $channel,
            'target_hash' => $blind,
            'code_lookup_hash' => hash( 'sha256', 'ci-' . $channel . $verified_user ),
            'code_hash' => 'CI-protected-contact-receipt',
            'attempts' => 0,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
            'delivery_receipt_hash' => hash( 'sha256', 'delivered-' . $channel ),
            'delivered_at' => $now, 'verified_at' => $now, 'created_at' => $now,
        )
    );
    $check( $inserted, 'File00 ' . $channel . ' current contact verification in real DB' );
}
foreach ( array_keys( smc_required_identity_documents() ) as $key ) {
    $inserted = $wpdb->insert( $wpdb->prefix . 'smc_identity_documents', array(
        'user_id' => $verified_user, 'document_key' => $key, 'version' => 1,
        'label' => 'CI non-sensitive fixture', 'stored_name' => 'ci-' . $key . '-' . $verified_user,
        'original_name' => 'ci-fixture.txt', 'mime_type' => 'text/plain',
        'file_size' => 1, 'plain_sha256' => hash( 'sha256', $key ),
        'scan_status' => 'passed', 'status' => 'approved',
        'reviewed_by' => 1, 'reviewed_at' => $now, 'lease_id' => wp_generate_uuid4(),
        'created_at' => $now, 'updated_at' => $now,
    ) );
    $check( 1 === (int) $inserted, 'File00 ' . $key . ' synthetic approved document metadata persists' );
}
$auth_fixture = static function ( $baseline, $subject ) use ( $verified_user ) {
    if ( (int) $subject !== $verified_user ) { return $baseline; }
    return array(
        'contract_version' => '1.0.0', 'owner' => 'file02', 'level' => 2,
        'method' => 'ci-current-session', 'verified_at' => time(),
        'passkey_asserted' => false, 'hardware_backed' => false,
    );
};
add_filter( 'smc_file02_authentication_assurance_v1', $auth_fixture, 10, 2 );
$verified_case = '33333333-3333-4333-8333-333333333333';
$policy_text = 'CI research-specific patient consent. Publication requires current verified case authorization.';
$policy_active = true;
$relation_active = true;
add_filter( 'he_v12_file06_consent_policy_v1',
    static function ( $value, $locale ) use ( &$policy_active, $policy_text ) {
        if ( ! $policy_active ) { return null; }
        return array(
            'approved' => true, 'owner_file' => 'file-06',
            'purpose' => 'patient-story-research', 'locale' => $locale,
            'version' => 'ci-policy-1', 'text' => $policy_text,
            'text_hash' => hash( 'sha256', $policy_text ),
        );
    }, 10, 2 );
add_filter( 'he_v12_file06_patient_relation_v1',
    static function ( $value, $subject, $uuid ) use ( $verified_user, $verified_case, &$relation_active ) {
        if ( ! $relation_active || (int) $subject !== $verified_user || $uuid !== $verified_case ) { return null; }
        return array(
            'verified' => true, 'owner_file' => 'file-06',
            'subject_user_id' => $verified_user, 'research_public_id' => $verified_case,
            'relation_version' => 1, 'attested_by' => 5, 'approved_by' => 6,
        );
    }, 10, 3 );
wp_set_current_user( $verified_user );
$claims = SMC_Contracts::assertions( $verified_user );
$profile = SMC_Advanced_Trust_2026::assurance_profile( $verified_user );
$check( ! empty( $claims['eligible'] ) && empty( $claims['suspended'] )
    && empty( $claims['institutional_account'] )
    && (int) ( $profile['identity_assurance_level'] ?? 0 ) >= 3
    && (int) ( $profile['authentication_assurance_level'] ?? 0 ) >= 1,
    'real File00 member eligibility, documents and typed authentication claim allow adult case consent' );
$make_accept = static function ( $case, $policy_text ) {
    $request = new WP_REST_Request( 'POST', '/smc/v1/file06/case-consent' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $request->set_body( wp_json_encode( array(
        'research_public_id' => $case, 'accepted' => true, 'locale' => 'ur-PK',
        'policy_version' => 'ci-policy-1', 'text_hash' => hash( 'sha256', $policy_text ),
    ) ) );
    return $request;
};
$accepted = SMC_File06_Case_Consent::accept( $make_accept( $verified_case, $policy_text ) );
$accepted_data = is_wp_error( $accepted ) ? array() : $accepted->get_data();
$reference = (string) ( $accepted_data['evidence_ref'] ?? '' );
$check( 'accepted' === ( $accepted_data['state'] ?? '' ) && 0 === strpos( $reference, 'smc-f06-r' ),
    'verified adult patient can accept case-specific policy in real WordPress' );
$receipt_id = (int) substr( $reference, strlen( 'smc-f06-r' ) );
$saved = $wpdb->get_row( $wpdb->prepare(
    "SELECT actor_type,user_id,purpose,policy_version,withdrawn_at
    FROM {$table} WHERE id=%d", $receipt_id
), ARRAY_A );
$check( is_array( $saved ) && 'self' === ( $saved['actor_type'] ?? '' )
    && (int) $saved['user_id'] === $verified_user
    && empty( $saved['withdrawn_at'] ),
    'case-specific real DB receipt is auditable, subject-bound and activated' );
$check( SMC_File06_Case_Consent::current_for_file06(
    false, 'smc-file06-v1:' . $receipt_id, $reference,
    $verified_case, 'patient-story-research' ) === true,
    'current real File00 membership and synthetic File06 policy validate actual stored receipt' );
$repeat = SMC_File06_Case_Consent::accept( $make_accept( $verified_case, $policy_text ) );
$repeat_data = is_wp_error( $repeat ) ? array() : $repeat->get_data();
$check( ( $repeat_data['evidence_ref'] ?? '' ) === $reference,
    'repeated same-case acceptance reuses one immutable receipt in MariaDB' );
$policy_active = false;
$check( SMC_File06_Case_Consent::current_for_file06(
    false, 'smc-file06-v1:' . $receipt_id, $reference,
    $verified_case, 'patient-story-research' ) === false,
    'revoked synthetic File06 policy immediately invalidates stored receipt' );
$policy_active = true;
$relation_active = false;
$check( SMC_File06_Case_Consent::current_for_file06(
    false, 'smc-file06-v1:' . $receipt_id, $reference,
    $verified_case, 'patient-story-research' ) === false,
    'revoked synthetic File06 patient relationship invalidates stored receipt' );
$relation_active = true;
$wpdb->update( $wpdb->prefix . 'smc_applications', array( 'status' => 'suspended' ),
    array( 'user_id' => $verified_user ) );
$check( SMC_File06_Case_Consent::current_for_file06(
    false, 'smc-file06-v1:' . $receipt_id, $reference,
    $verified_case, 'patient-story-research' ) === false,
    'real File00 suspension blocks research consent on revalidation' );
wp_set_current_user( 0 );
remove_filter( 'smc_file02_authentication_assurance_v1', $auth_fixture, 10 );

if ( $failures ) {
    fwrite( STDERR, 'File00/File06 real MariaDB: ' . $pass . ' PASS / ' . count( $failures ) . " FAIL\n" );
    exit( 1 );
}
echo 'File00/File06 real MariaDB: ' . $pass . " PASS / 0 FAIL\n";

<?php
/**
 * Real WordPress + MariaDB regression acceptance for File 00 80-round review.
 * Run with: wp eval-file <repo>/qa/review80-wordpress-mysql.php
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress not loaded.\n" ); exit( 2 ); }

global $wpdb;
$failures = array();
$passed = 0;
$check = static function ( $ok, $label ) use ( &$failures, &$passed ) {
	if ( $ok ) { ++$passed; echo "PASS {$label}\n"; return; }
	$failures[] = $label; echo "FAIL {$label}\n";
};

$check( defined( 'SMC_VERSION' ) && '1.2.40' === SMC_VERSION, 'runtime 1.2.40' );
$check( defined( 'SMC_DB_VERSION' ) && '1.4.5' === SMC_DB_VERSION, 'DB contract 1.4.5' );
$check( defined( 'SMC_CONTRACT_VERSION' ) && '1.2.3' === SMC_CONTRACT_VERSION, 'public contract 1.2.3' );
$key = SMC_Security::ensure_key_ready();
$check( ! is_wp_error( $key ), 'key ready' );
$audit = SMC_Installer::ensure_audit_infrastructure();
$check( ! is_wp_error( $audit ), 'audit infrastructure ready' );
SMC_Schema_Compat::reconcile_verification_queue_index();
SMC_Installer::maybe_upgrade();
$check( SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ), 'database migrated to target' );
$check( SMC_VERSION === (string) get_option( 'smc_release_version', '' ), 'release marker current' );

foreach ( array( 'expires_at', 'revoked_at' ) as $index_name ) {
	$cols = $wpdb->get_col( $wpdb->prepare(
		"SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX",
		$wpdb->prefix . 'smc_auth_sessions', $index_name
	) );
	$check( array( $index_name ) === array_values( (array) $cols ), "session cleanup index {$index_name}" );
}

foreach ( array( 'start_2fa','finish_2fa','challenge_2fa','rotate_recovery','ack_recovery_receipt' ) as $action ) {
	$check( false === has_action( 'admin_post_smc_' . $action ), "retired MFA action absent {$action}" );
}

// File02 authentication assurance is the only elevating authentication owner.
$auth_user = wp_insert_user( array(
	'user_login' => 'review80_auth_' . wp_generate_password( 6, false, false ),
	'user_email' => 'review80-auth-' . wp_generate_password( 6, false, false ) . '@example.test',
	'user_pass'  => wp_generate_password( 28, true, true ),
	'role'       => 'subscriber',
) );
$check( ! is_wp_error( $auth_user ), 'authentication fixture user created' );
if ( ! is_wp_error( $auth_user ) ) {
	$file02 = static function ( $baseline, $user_id ) use ( $auth_user ) {
		if ( (int) $user_id !== (int) $auth_user ) { return $baseline; }
		return array( 'contract_version'=>'1.0.0','owner'=>'file02','level'=>2,'method'=>'password_reauth','passkey_asserted'=>false,'hardware_backed'=>false,'verified_at'=>time() );
	};
	add_filter( 'smc_file02_authentication_assurance_v1', $file02, 10, 2 );
	$claim = SMC_Advanced_Trust_2026::authentication_assurance( $auth_user );
	$check( 'file02' === ( $claim['owner'] ?? '' ) && 2 === (int) ( $claim['level'] ?? -1 ), 'fresh File02 assurance accepted' );
	remove_filter( 'smc_file02_authentication_assurance_v1', $file02, 10 );
	$foreign = static function ( $baseline, $user_id ) use ( $auth_user ) {
		if ( (int) $user_id !== (int) $auth_user ) { return $baseline; }
		return array( 'contract_version'=>'1.0.0','owner'=>'file00','level'=>4,'method'=>'totp','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time() );
	};
	add_filter( 'smc_file02_authentication_assurance_v1', $foreign, 10, 2 );
	$claim = SMC_Advanced_Trust_2026::authentication_assurance( $auth_user );
	$check( 'none' === ( $claim['owner'] ?? '' ) && 0 === (int) ( $claim['level'] ?? -1 ), 'foreign File00 authentication claim rejected' );
	remove_filter( 'smc_file02_authentication_assurance_v1', $foreign, 10 );
}

// Requested-role replacement must remove stale grants exactly.
$role_user = wp_insert_user( array(
	'user_login' => 'review80_role_' . wp_generate_password( 6, false, false ),
	'user_email' => 'review80-role-' . wp_generate_password( 6, false, false ) . '@example.test',
	'user_pass'  => wp_generate_password( 28, true, true ),
	'role'       => 'subscriber',
) );
$check( ! is_wp_error( $role_user ) && (bool) smc_application( $role_user ), 'role fixture registered' );
if ( ! is_wp_error( $role_user ) ) {
	$app = smc_application( $role_user );
	$version = max( 1, (int) ( $app['row_version'] ?? 1 ) );
	$check( SMC_Contracts::replace_requested_types( $role_user, array( 'member','student' ), $version ), 'role replacement adds exact requested set' );
	$check( SMC_Contracts::replace_requested_types( $role_user, array( 'member' ), $version ), 'role replacement removes dropped type' );
	$types = $wpdb->get_col( $wpdb->prepare( "SELECT membership_type FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d ORDER BY membership_type", $role_user ) );
	$check( array( 'member' ) === array_values( (array) $types ), 'no stale removed role grant remains' );
}

// Cross-file age projections remain privacy-minimal and fail closed for a minor without guardian verification.
$minor_user = wp_insert_user( array(
	'user_login' => 'review80_minor_' . wp_generate_password( 6, false, false ),
	'user_email' => 'review80-minor-' . wp_generate_password( 6, false, false ) . '@example.test',
	'user_pass'  => wp_generate_password( 28, true, true ),
	'role'       => 'subscriber',
) );
$check( ! is_wp_error( $minor_user ) && (bool) smc_application( $minor_user ), 'minor fixture registered' );
if ( ! is_wp_error( $minor_user ) ) {
	$dob = gmdate( 'Y-m-d', strtotime( '-16 years' ) );
	$enc = SMC_Security::encrypt( $dob, 'date-of-birth', array( 'user_id'=>(int)$minor_user ) );
	$check( ! is_wp_error( $enc ), 'minor DOB encrypted' );
	if ( ! is_wp_error( $enc ) ) {
		$wpdb->update( $wpdb->prefix . 'smc_applications', array( 'date_of_birth_enc'=>$enc,'guardian_required'=>1,'gender'=>'male','updated_at'=>current_time('mysql',true) ), array( 'user_id'=>(int)$minor_user ) );
		$a = SMC_Contracts::assertions( $minor_user );
		$check( ! empty( $a['age_context']['minor'] ) && 'minor' === ( $a['age_context']['age_band'] ?? '' ) && ! array_key_exists( 'age_years', (array) $a['age_context'] ), 'minor age projection has no exact age' );
		$c = (array) ( $a['clinical_commerce'] ?? array() );
		$check( empty( $c['marketplace_direct_deal_allowed'] ) && empty( $c['emergency_online_case_allowed'] ) && ! empty( $c['appointment_requires_guardian_context'] ), 'minor clinical-commerce restrictions fail closed' );
	}
}

// Inbox IDs are strict UUIDs and valid IDs are idempotent.
$callback_count = 0;
$callback = static function () use ( &$callback_count ) { ++$callback_count; return true; };
$check( false === SMC_Events::consume( 'review80', array( 'event_id'=>'not-a-uuid-that-would-be-truncated-in-sql' ), $callback ), 'invalid inbox event ID rejected before persistence' );
$valid_event = array( 'event_id'=>wp_generate_uuid4() );
$first = SMC_Events::consume( 'review80', $valid_event, $callback );
$second = SMC_Events::consume( 'review80', $valid_event, $callback );
$check( true === $first && true === $second && 1 === $callback_count, 'valid inbox event is replay-idempotent' );

// Repair adapter exceptions must transition back to controlled retry/dead-letter, never strand processing.
if ( ! is_wp_error( $role_user ) ) {
	$trace = SMC_Completion::record_repair( $role_user, 'review80_throw', array( 'source'=>'review80' ) );
	$repair_id = $trace ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_application_repairs WHERE trace_id=%s", $trace ) ) : 0;
	$thrower = static function ( $resolved, $row ) { if ( 'review80_throw' === ( $row['repair_type'] ?? '' ) ) { throw new RuntimeException( 'review80 deliberate adapter failure' ); } return $resolved; };
	add_filter( 'smc_repair_application_item', $thrower, 10, 2 );
	if ( $repair_id ) { SMC_Completion::reconcile_applications( 1, $repair_id ); }
	remove_filter( 'smc_repair_application_item', $thrower, 10 );
	$repair = $repair_id ? $wpdb->get_row( $wpdb->prepare( "SELECT status,last_error FROM {$wpdb->prefix}smc_application_repairs WHERE id=%d", $repair_id ), ARRAY_A ) : array();
	$check( $repair_id > 0 && in_array( $repair['status'] ?? '', array( 'retry','dead_letter' ), true ) && 'Repair adapter raised an exception; retry is required.' === ( $repair['last_error'] ?? '' ), 'repair Throwable becomes controlled retry evidence' );
}

// Backup manifest distinguishes persisted DB reality from target identity.
$previous_db = get_option( 'smc_db_version', '' );
update_option( 'smc_db_version', '0.0.0-review80', false );
$manifest = SMC_Completion::backup_manifest();
$check( '0.0.0-review80' === ( $manifest['database_version'] ?? '' ) && '1.4.5' === ( $manifest['target_database_version'] ?? '' ), 'backup manifest reports actual and target DB separately' );
update_option( 'smc_db_version', $previous_db, false );

// Starvation regression: a later institutional repair must be reached through cursorized batches even when 500+ earlier suspended rows are irrelevant.
$cursor_option = 'smc_institutional_repair_cursor';
update_option( $cursor_option, 0, false );
$template_user = wp_insert_user( array(
	'user_login' => 'review80_template_' . wp_generate_password( 6, false, false ),
	'user_email' => 'review80-template-' . wp_generate_password( 6, false, false ) . '@example.test',
	'user_pass'  => wp_generate_password( 28, true, true ),
	'role'       => 'subscriber',
) );
$template = ! is_wp_error( $template_user ) ? smc_application( $template_user ) : false;
$dummy_ids = array();
if ( $template ) {
	$clone = $template; unset( $clone['id'] );
	$clone['status'] = 'suspended'; $clone['row_version'] = 1;
	for ( $i=0; $i<500; $i++ ) {
		$clone['user_id'] = 900000 + $i;
		$clone['created_at'] = current_time( 'mysql', true ); $clone['updated_at'] = $clone['created_at'];
		if ( 1 === $wpdb->insert( $wpdb->prefix . 'smc_applications', $clone ) ) { $dummy_ids[] = (int) $wpdb->insert_id; }
	}
}
$check( 500 === count( $dummy_ids ), 'institutional starvation fixture contains exactly 500 irrelevant suspended rows' );
$admin_user = wp_insert_user( array(
	'user_login' => 'review80_admin_' . wp_generate_password( 6, false, false ),
	'user_email' => 'review80-admin-' . wp_generate_password( 6, false, false ) . '@example.test',
	'user_pass'  => wp_generate_password( 28, true, true ),
	'role'       => 'administrator',
) );
$target = ! is_wp_error( $admin_user ) ? smc_application( $admin_user ) : false;
if ( $target ) {
	$target_id = (int) $target['id'];
	$wpdb->update( $wpdb->prefix . 'smc_applications', array( 'status'=>'suspended','updated_at'=>current_time('mysql',true) ), array( 'id'=>$target_id ) );
	SMC_Security::audit( 'membership_restricted', $admin_user, array( 'reason_code'=>'age_eligibility_failed' ) );
	$eligible_suspended = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_applications WHERE status='suspended' AND id<=%d", $target_id ) );
	$check( $eligible_suspended >= 501, 'institutional starvation target is positioned after at least one full 500-row batch' );
	$previous_cursor = 0;
	$progress_ok = true;
	$repaired = false;
	$max_batches = max( 3, (int) ceil( $eligible_suspended / 500 ) + 2 );
	for ( $attempt = 1; $attempt <= $max_batches; $attempt++ ) {
		SMC_Lifecycle::repair_institutional_accounts();
		$current = smc_application( $admin_user );
		if ( $current && 'suspended' !== ( $current['status'] ?? '' ) ) {
			$repaired = true;
			break;
		}
		$cursor = (int) get_option( $cursor_option, 0 );
		if ( 0 === $cursor || $cursor <= $previous_cursor ) {
			$progress_ok = false;
			break;
		}
		$previous_cursor = $cursor;
	}
	$check( $progress_ok, 'institutional repair cursor advances monotonically while additional batches remain' );
	$check( $repaired, 'institutional repair cursor reaches and repairs later eligible institutional row without starvation' );
} else {
	$check( false, 'institutional starvation target application exists' );
	$check( false, 'institutional repair cursor advances monotonically while additional batches remain' );
	$check( false, 'institutional repair cursor reaches and repairs later eligible institutional row without starvation' );
}
if ( $dummy_ids ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}smc_applications WHERE user_id BETWEEN 900000 AND 900499" );
}
delete_option( $cursor_option );

if ( $failures ) {
	fwrite( STDERR, sprintf( "review80 WordPress/MariaDB: %d PASS / %d FAIL\n", $passed, count( $failures ) ) );
	foreach ( $failures as $failure ) { fwrite( STDERR, "- {$failure}\n" ); }
	exit( 1 );
}
echo sprintf( "review80 WordPress/MariaDB: %d PASS / 0 FAIL\n", $passed );
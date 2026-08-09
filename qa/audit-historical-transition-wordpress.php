<?php
/**
 * Real WordPress/MariaDB regression for the live row-16 historical-key state.
 *
 * Run once before File 00 is active to create the interrupted bridge fixture,
 * then run again after activation to perform and verify guarded recovery.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded.\n" );
	exit( 2 );
}

function smc_transition_test_canonical_value( $value ) {
	if ( is_array( $value ) ) {
		return json_decode( smc_transition_test_canonical_json( $value ), true );
	}
	return $value;
}

function smc_transition_test_canonical_json( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = smc_transition_test_canonical_value( $item ); }
	}
	return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function smc_transition_test_snapshot( $rows ) {
	$normalized = array();
	foreach ( (array) $rows as $row ) {
		$normalized[] = array(
			'id'              => (int) $row['id'],
			'actor_id'        => (int) $row['actor_id'],
			'subject_user_id' => (int) $row['subject_user_id'],
			'subject_hash'    => null === $row['subject_hash'] ? null : (string) $row['subject_hash'],
			'action'          => (string) $row['action'],
			'object_type'     => (string) $row['object_type'],
			'object_id'       => (int) $row['object_id'],
			'details'         => null === $row['details'] ? null : (string) $row['details'],
			'previous_hash'   => (string) $row['previous_hash'],
			'row_hash'        => (string) $row['row_hash'],
			'created_at'      => (string) $row['created_at'],
		);
	}
	return hash( 'sha256', smc_transition_test_canonical_json( $normalized ) );
}

function smc_transition_test_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

global $wpdb;
$audit_table = $wpdb->prefix . 'smc_audit_log';
$tail_table  = $wpdb->prefix . 'smc_audit_tail';

if ( ! class_exists( 'SMC_Security' ) ) {
	if ( ! defined( 'SMC_MASTER_KEY' ) || ! is_string( SMC_MASTER_KEY ) || 0 !== strpos( SMC_MASTER_KEY, 'base64:' ) ) {
		smc_transition_test_fail( 'fixture requires an encoded base64 master key' );
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$tail_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$audit_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$created = $wpdb->query(
		"CREATE TABLE {$audit_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject_hash char(64) NULL,
			action varchar(120) NOT NULL,
			object_type varchar(80) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			details longtext NULL,
			previous_hash char(64) NOT NULL DEFAULT '',
			row_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY (id)
		) " . $wpdb->get_charset_collate() . ' ENGINE=InnoDB'
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( false === $created ) { smc_transition_test_fail( 'legacy bridge table could not be created: ' . $wpdb->last_error ); }

	for ( $id = 1; $id <= 15; ++$id ) {
		$inserted = $wpdb->insert(
			$audit_table,
			array(
				'id' => $id, 'actor_id' => 1, 'subject_user_id' => 1, 'subject_hash' => null,
				'action' => 'legacy_event_' . $id, 'object_type' => 'user', 'object_id' => 1,
				'details' => '{}', 'previous_hash' => '', 'row_hash' => '',
				'created_at' => sprintf( '2026-07-31 01:%02d:00', $id ),
			)
		);
		if ( 1 !== $inserted ) { smc_transition_test_fail( 'legacy prefix row could not be inserted' ); }
	}

	$record = array(
		'actor_id'      => 1,
		'subject_hash'  => null,
		'action'        => 'two_factor_enabled',
		'details'       => '{}',
		'previous_hash' => '',
		'created_at'    => '2026-07-31 01:16:00',
	);
	$historical_key = hash_hkdf( 'sha256', SMC_MASTER_KEY, 32, 'sabri-membership-core:v2', wp_salt( 'auth' ) );
	$row_hash = hash_hmac( 'sha256', smc_transition_test_canonical_json( $record ), $historical_key );
	$inserted = $wpdb->insert(
		$audit_table,
		array_merge(
			array( 'id' => 16, 'subject_user_id' => 0, 'object_type' => '', 'object_id' => 0 ),
			$record,
			array( 'row_hash' => $row_hash )
		)
	);
	if ( 1 !== $inserted ) { smc_transition_test_fail( 'historical modern row 16 could not be inserted' ); }

	$rows = $wpdb->get_results( "SELECT * FROM {$audit_table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	update_option( 'smc_transition_test_snapshot', smc_transition_test_snapshot( $rows ), false );
	update_option( 'smc_transition_test_row16_hash', $row_hash, false );
	delete_option( 'smc_audit_schema_initialized_v1' );
	delete_option( 'smc_audit_legacy_anchor_v1' );
	delete_option( 'smc_audit_chain_epoch_v1' );
	echo "PASS historical row-16 fixture created\n";
	exit( 0 );
}

$ready = SMC_Installer::ensure_audit_infrastructure();
if ( is_wp_error( $ready ) ) { smc_transition_test_fail( $ready->get_error_code() . ': ' . $ready->get_error_message() ); }
if ( ! is_array( $ready ) || empty( $ready['bootstrapped'] ) || 'tail' !== ( $ready['repaired_partial'] ?? '' ) ) {
	smc_transition_test_fail( 'guarded serializer recovery did not report the expected repaired state' );
}

$rows = $wpdb->get_results( "SELECT * FROM {$audit_table} WHERE id<=16 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( 16 !== count( $rows ) ) { smc_transition_test_fail( 'historical row count changed during recovery' ); }
if ( ! hash_equals( (string) get_option( 'smc_transition_test_snapshot', '' ), smc_transition_test_snapshot( $rows ) ) ) {
	smc_transition_test_fail( 'historical row content changed during recovery' );
}
$row16_hash = (string) get_option( 'smc_transition_test_row16_hash', '' );
$tail_hash  = (string) $wpdb->get_var( "SELECT row_hash FROM {$tail_table} WHERE id=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( ! hash_equals( $row16_hash, $tail_hash ) ) { smc_transition_test_fail( 'serializer tail is not bound to historical row 16' ); }

$anchor = get_option( SMC_Security::LEGACY_AUDIT_ANCHOR_OPTION, array() );
if ( ! is_array( $anchor ) || 2 !== (int) ( $anchor['version'] ?? 0 ) || 'smc-audit-legacy-v1-bridge-columns' !== ( $anchor['source_schema'] ?? '' ) || 16 !== (int) ( $anchor['modern_epoch_first_id'] ?? 0 ) || ! hash_equals( $row16_hash, (string) ( $anchor['modern_epoch_first_row_hash'] ?? '' ) ) ) {
	smc_transition_test_fail( 'v2 bridge anchor does not bind the exact row-16 epoch boundary' );
}

$chain = SMC_Security::verify_audit_chain();
if ( ! is_array( $chain ) || empty( $chain['valid'] ) || 16 !== (int) ( $chain['checked'] ?? 0 ) ) {
	smc_transition_test_fail( 'recovered 16-row chain does not verify' );
}
$historical_key_ids = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table} WHERE id<=16 AND audit_key_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( 0 !== $historical_key_ids ) { smc_transition_test_fail( 'historical rows were backfilled with key IDs' ); }

if ( ! SMC_Security::audit( 'historical_transition_forward_append', 0, array( 'fixture' => 'row16' ) ) ) {
	smc_transition_test_fail( 'forward-only audit append failed after recovery' );
}
$row17 = $wpdb->get_row( "SELECT id,previous_hash,row_hash,audit_key_id FROM {$audit_table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( 17 !== (int) ( $row17['id'] ?? 0 ) || ! hash_equals( $row16_hash, (string) ( $row17['previous_hash'] ?? '' ) ) || ! hash_equals( (string) SMC_MASTER_KEY_ID, (string) ( $row17['audit_key_id'] ?? '' ) ) ) {
	smc_transition_test_fail( 'new row is not a keyed forward append from row 16' );
}
$final_chain = SMC_Security::verify_audit_chain();
if ( ! is_array( $final_chain ) || empty( $final_chain['valid'] ) || 17 !== (int) ( $final_chain['checked'] ?? 0 ) ) {
	smc_transition_test_fail( 'mixed historical/current 17-row chain does not verify' );
}

echo "PASS real WordPress/MariaDB historical row-16 transition\n";

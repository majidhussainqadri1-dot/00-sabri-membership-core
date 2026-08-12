<?php
/**
 * File 00 — privacy-safe Live Reality Freeze collector.
 *
 * Run only through WP-CLI against the exact staging/live WordPress instance:
 *   wp eval-file tools/live-reality-freeze.php
 *
 * This script is intentionally read-only. It does not run migrations, mutate
 * options, repair data, rotate keys, activate plugins, or change capabilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded. Run with wp eval-file.\n" );
	exit( 2 );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This diagnostic is restricted to WP-CLI.\n" );
	exit( 3 );
}

if ( ! defined( 'SMC_VERSION' ) || ! defined( 'SMC_FILE' ) ) {
	fwrite( STDERR, "Sabri Membership Core is not loaded.\n" );
	exit( 4 );
}

if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/** Return a digest instead of a potentially sensitive diagnostic string. */
function smc_lrf_digest_text( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	return '' === $value ? '' : hash( 'sha256', $value );
}

/** Keep only bounded, non-secret state fields from an option record. */
function smc_lrf_option_state( $option_name, $fields ) {
	$value = get_option( $option_name, array() );
	if ( ! is_array( $value ) ) {
		return array( 'present' => false );
	}
	$out = array( 'present' => ! empty( $value ) );
	foreach ( $fields as $field ) {
		if ( ! array_key_exists( $field, $value ) ) {
			continue;
		}
		if ( 'message' === $field || 'error' === $field || 'last_error' === $field ) {
			$out[ $field . '_sha256' ] = smc_lrf_digest_text( $value[ $field ] );
			continue;
		}
		$raw = $value[ $field ];
		$out[ $field ] = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : 'non_scalar';
	}
	return $out;
}

/** Verify the deployed manifest without disclosing runtime data. */
function smc_lrf_manifest_evidence() {
	$root     = trailingslashit( dirname( SMC_FILE ) );
	$manifest = $root . 'MANIFEST.sha256';
	$result   = array(
		'present'          => is_readable( $manifest ),
		'manifest_sha256'  => '',
		'entries'          => 0,
		'missing_files'    => 0,
		'hash_mismatches'  => 0,
		'invalid_entries'  => 0,
		'verified'         => false,
		'mismatch_paths'   => array(),
	);
	if ( ! $result['present'] ) {
		return $result;
	}

	$manifest_hash = hash_file( 'sha256', $manifest );
	$result['manifest_sha256'] = is_string( $manifest_hash ) ? $manifest_hash : '';
	$lines = file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $lines ) {
		$result['invalid_entries'] = 1;
		return $result;
	}

	foreach ( $lines as $line ) {
		if ( 1 !== preg_match( '/^([0-9a-f]{64})  ([^\\r\\n]+)$/', (string) $line, $matches ) ) {
			$result['invalid_entries']++;
			continue;
		}
		$expected = strtolower( $matches[1] );
		$relative = str_replace( '\\', '/', trim( $matches[2] ) );
		if ( '' === $relative || 0 === strpos( $relative, '/' ) || false !== strpos( $relative, '../' ) || 'MANIFEST.sha256' === basename( $relative ) ) {
			$result['invalid_entries']++;
			continue;
		}
		$result['entries']++;
		$path = $root . $relative;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			$result['missing_files']++;
			if ( count( $result['mismatch_paths'] ) < 20 ) {
				$result['mismatch_paths'][] = $relative;
			}
			continue;
		}
		$actual = hash_file( 'sha256', $path );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			$result['hash_mismatches']++;
			if ( count( $result['mismatch_paths'] ) < 20 ) {
				$result['mismatch_paths'][] = $relative;
			}
		}
	}

	$result['verified'] = $result['entries'] > 0
		&& 0 === $result['missing_files']
		&& 0 === $result['hash_mismatches']
		&& 0 === $result['invalid_entries'];
	return $result;
}

/** Return only active Sabri/project plugin identity metadata. */
function smc_lrf_project_plugins() {
	$plugins = get_plugins();
	$out = array();
	foreach ( $plugins as $file => $data ) {
		if ( ! is_plugin_active( $file ) ) {
			continue;
		}
		$name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
		$haystack = strtolower( $file . ' ' . $name );
		if ( false === strpos( $haystack, 'sabri' )
			&& false === strpos( $haystack, 'platform-foundation' )
			&& false === strpos( $haystack, 'authentication' ) ) {
			continue;
		}
		$out[] = array(
			'plugin_file' => sanitize_text_field( $file ),
			'name'        => sanitize_text_field( $name ),
			'version'     => sanitize_text_field( isset( $data['Version'] ) ? (string) $data['Version'] : '' ),
		);
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return strcmp( $a['plugin_file'], $b['plugin_file'] );
		}
	);
	return $out;
}

/** Capture only SMC table names/engines; never row contents. */
function smc_lrf_table_shape() {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix . 'smc_' ) . '%';
	$sql  = $wpdb->prepare(
		'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
		$like
	);
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$table_name = (string) ( $row['TABLE_NAME'] ?? '' );
		if ( 0 === strpos( $table_name, $wpdb->prefix ) ) {
			$table_name = substr( $table_name, strlen( $wpdb->prefix ) );
		}
		$out[] = array(
			'table'  => sanitize_text_field( $table_name ),
			'engine' => sanitize_text_field( (string) ( $row['ENGINE'] ?? '' ) ),
		);
	}
	return $out;
}

function smc_lrf_queue_index_state() {
	if ( ! class_exists( 'SMC_Schema_Compat' ) || ! is_callable( array( 'SMC_Schema_Compat', 'assert_current_queue_indexes' ) ) ) {
		return 'unsupported_by_deployed_runtime';
	}
	try {
		SMC_Schema_Compat::assert_current_queue_indexes();
		return 'verified';
	} catch ( Throwable $error ) {
		return 'failed:' . get_class( $error ) . ':' . smc_lrf_digest_text( $error->getMessage() );
	}
}

global $wpdb;
$db_server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
$timezone  = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string', '' );
$entrypoint_hash = is_readable( SMC_FILE ) ? hash_file( 'sha256', SMC_FILE ) : '';
$entrypoint_hash = is_string( $entrypoint_hash ) ? $entrypoint_hash : '';
$manifest_evidence = smc_lrf_manifest_evidence();
$payload_fingerprint = hash(
	'sha256',
	(string) SMC_VERSION . '|' . $entrypoint_hash . '|' . (string) ( $manifest_evidence['manifest_sha256'] ?? '' )
);

$evidence = array(
	'collector' => array(
		'format'       => 'smc-live-reality-freeze-v1',
		'generated_at' => gmdate( 'c' ),
		'read_only'    => true,
	),
	'repository_truth' => array(
		'repository_head' => 'unverified_from_deployed_runtime',
		'note'            => 'Compare deployed hashes/versions below against the separately verified GitHub exact HEAD.',
	),
	'deployed_file00' => array(
		'runtime_version'              => (string) SMC_VERSION,
		'expected_db_version'          => defined( 'SMC_DB_VERSION' ) ? (string) SMC_DB_VERSION : '',
		'actual_db_version'            => (string) get_option( 'smc_db_version', '' ),
		'release_marker'               => (string) get_option( 'smc_release_version', '' ),
		'public_contract_version'      => defined( 'SMC_CONTRACT_VERSION' ) ? (string) SMC_CONTRACT_VERSION : '',
		'cf01_contract_version'        => defined( 'SMC_CF01_CONTRACT_VERSION' ) ? (string) SMC_CF01_CONTRACT_VERSION : '',
		'advanced_trust_version'       => defined( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION' ) ? (string) SMC_ADVANCED_TRUST_CONTRACT_VERSION : '',
		'entrypoint_sha256'            => $entrypoint_hash,
		'payload_fingerprint_sha256'   => $payload_fingerprint,
		'manifest'                     => $manifest_evidence,
		'queue_index_state'            => smc_lrf_queue_index_state(),
	),
	'migration_state' => array(
		'activation_pending' => smc_lrf_option_state( 'smc_activation_pending_v2', array( 'target_release', 'target_db_version', 'requested_at' ) ),
		'bootstrap'          => smc_lrf_option_state( 'smc_activation_bootstrap_state_v2', array( 'status', 'phase', 'message', 'updated_at' ) ),
		'deferred'           => smc_lrf_option_state( 'smc_migration_deferred_v1', array( 'reason', 'updated_at' ) ),
		'last_failure'       => smc_lrf_option_state( 'smc_last_migration_failure', array( 'message', 'updated_at' ) ),
	),
	'cross_file_contracts' => array(
		'file01_runtime_loaded'                     => defined( 'SPF_VERSION' ),
		'file01_runtime_version'                    => defined( 'SPF_VERSION' ) ? (string) SPF_VERSION : '',
		'file01_schema_version'                     => defined( 'SPF_SCHEMA_VERSION' ) ? (string) SPF_SCHEMA_VERSION : '',
		'file01_contract_version'                   => defined( 'SPF_CONTRACT_VERSION' ) ? (string) SPF_CONTRACT_VERSION : '',
		'file01_authorization_bridge_registered'    => false !== has_filter( 'spf_file00_authorization_claim', array( 'SMC_Authorization', 'file01_authorization_claim' ) ),
		'file01_claim_version'                      => defined( 'SMC_FILE01_AUTH_CLAIM_VERSION' ) ? (string) SMC_FILE01_AUTH_CLAIM_VERSION : '',
		'file01_supported_contract'                 => defined( 'SMC_FILE01_FOUNDATION_CONTRACT_VERSION' ) ? (string) SMC_FILE01_FOUNDATION_CONTRACT_VERSION : '',
		'file02_consumer_loaded'                    => defined( 'SAUTH_VERSION' ),
		'file02_consumer_version'                   => defined( 'SAUTH_VERSION' ) ? (string) SAUTH_VERSION : '',
		'file02_provider_class'                     => class_exists( 'SMC_Authentication_Contract_V11' ),
		'file02_provider_version'                   => defined( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION' ) ? (string) SMC_AUTHENTICATION_CONTRACT_V11_VERSION : '',
		'file02_register_account_callable'          => is_callable( array( 'SMC_Authentication_Contract_V11', 'register_account' ) ),
		'file02_mark_email_verified_callable'       => is_callable( array( 'SMC_Authentication_Contract_V11', 'mark_email_verified' ) ),
		'file02_completion_state_callable'          => is_callable( array( 'SMC_Authentication_Contract_V11', 'get_completion_state' ) ),
	),
	'environment' => array(
		'wordpress_version' => get_bloginfo( 'version' ),
		'php_version'       => PHP_VERSION,
		'database_server'   => sanitize_text_field( $db_server ),
		'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
		'timezone'          => sanitize_text_field( $timezone ),
	),
	'database_shape' => array(
		'smc_tables' => smc_lrf_table_shape(),
	),
	'active_project_plugins' => smc_lrf_project_plugins(),
	'live_verification_status' => 'evidence_collected_not_compared',
);

echo wp_json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

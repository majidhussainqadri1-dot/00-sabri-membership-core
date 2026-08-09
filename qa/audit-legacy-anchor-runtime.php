<?php
/** Isolated regression for the 1.0.1 audit snapshot -> HMAC epoch bridge. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/smc-audit-anchor-runtime' );
define( 'ARRAY_A', 'ARRAY_A' );
$smc_test_master_material = '0123456789abcdef0123456789abcdef';
define( 'SMC_MASTER_KEY', 'base64:' . base64_encode( $smc_test_master_material ) );
define( 'SMC_MASTER_KEY_ID', 'runtime-audit-key-v1' );
define( 'SMC_LEGACY_AUTH_SALT', 'smc-retained-auth-salt-v1' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

$GLOBALS['smc_anchor_options'] = array();

function __( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return trim( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt( $scheme = 'auth' ) { return 'smc-runtime-' . $scheme . '-salt'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function current_time( $type, $gmt = false ) { unset( $type, $gmt ); return '2026-08-09 12:00:00'; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['smc_anchor_options'] ) ? $GLOBALS['smc_anchor_options'][ $name ] : $default; }
function add_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['smc_anchor_options'] ) ) { return false; }
	$GLOBALS['smc_anchor_options'][ $name ] = $value;
	return true;
}

final class SMC_Audit_Test_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $columns = array();
	public $rows = array();
	public $tail_exists = false;
	public $tail_hash = '';

	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function esc_like( $value ) { return (string) $value; }
	public function get_col( $prepared ) {
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'information_schema.COLUMNS' ) ) { return array_map( static function ( $row ) { return (string) ( $row['COLUMN_NAME'] ?? '' ); }, $this->columns ); }
		return array();
	}
	public function get_results( $prepared, $format = ARRAY_A ) {
		unset( $format );
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'information_schema.COLUMNS' ) ) { return $this->columns; }
		if ( ! is_array( $prepared ) || false === strpos( $prepared['query'], 'smc_audit_log' ) ) { return array(); }
		$cursor = (int) ( $prepared['args'][0] ?? 0 );
		$limit = (int) ( $prepared['args'][1] ?? 500 );
		$rows = array_values( array_filter( $this->rows, static function ( $row ) use ( $cursor ) { return (int) $row['id'] > $cursor; } ) );
		usort( $rows, static function ( $left, $right ) { return (int) $left['id'] <=> (int) $right['id']; } );
		return array_slice( $rows, 0, $limit );
	}
	public function get_var( $prepared ) {
		if ( is_array( $prepared ) && false !== strpos( $prepared['query'], 'SHOW TABLES LIKE' ) ) {
			$table = (string) ( $prepared['args'][0] ?? '' );
			return $this->tail_exists && 'wp_smc_audit_tail' === $table ? $table : null;
		}
		return null;
	}
	public function get_row( $query, $format = ARRAY_A ) {
		unset( $format );
		return $this->tail_exists && false !== strpos( (string) $query, 'smc_audit_tail' ) ? array( 'row_hash' => $this->tail_hash ) : null;
	}
}

function smc_test_canonical_value( $value ) {
	if ( is_array( $value ) ) {
		$json = smc_test_canonical_json( $value );
		return json_decode( $json, true );
	}
	return $value;
}

function smc_test_canonical_json( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = smc_test_canonical_value( $item ); }
	}
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

function smc_test_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

function smc_test_schema_column( $name, $data_type, $column_type = '', $length = null, $extra = '' ) {
	return array(
		'COLUMN_NAME'              => $name,
		'DATA_TYPE'                => $data_type,
		'COLUMN_TYPE'              => $column_type ?: $data_type,
		'CHARACTER_MAXIMUM_LENGTH' => $length,
		'EXTRA'                    => $extra,
	);
}

$wpdb = new SMC_Audit_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-security.php';

$legacy_columns = array(
	smc_test_schema_column( 'id', 'bigint', 'bigint(20) unsigned', null, 'auto_increment' ),
	smc_test_schema_column( 'actor_id', 'bigint', 'bigint(20) unsigned' ),
	smc_test_schema_column( 'subject_user_id', 'bigint', 'bigint(20) unsigned' ),
	smc_test_schema_column( 'action', 'varchar', 'varchar(120)', 120 ),
	smc_test_schema_column( 'object_type', 'varchar', 'varchar(80)', 80 ),
	smc_test_schema_column( 'object_id', 'bigint', 'bigint(20) unsigned' ),
	smc_test_schema_column( 'details', 'longtext' ),
	smc_test_schema_column( 'created_at', 'datetime' ),
);
$wpdb->columns = $legacy_columns;
$wpdb->rows = array(
	array( 'id'=>1, 'actor_id'=>7, 'subject_user_id'=>11, 'action'=>'login_success', 'object_type'=>'user', 'object_id'=>11, 'details'=>'{"source":"legacy"}', 'created_at'=>'2026-07-31 01:00:00' ),
	array( 'id'=>2, 'actor_id'=>7, 'subject_user_id'=>11, 'action'=>'logout', 'object_type'=>'user', 'object_id'=>11, 'details'=>null, 'created_at'=>'2026-07-31 01:05:00' ),
);

$legacy = SMC_Security::inspect_audit_rows_for_recovery( 2 );
smc_test_assert( ! empty( $legacy['valid'] ) && 2 === $legacy['legacy_rows'] && 0 === $legacy['verified_rows'], 'the exact 1.0.1 prefix is classified, not called a broken HMAC chain' );
$anchor = SMC_Security::establish_legacy_audit_anchor( $legacy );
smc_test_assert( is_array( $anchor ) && ! empty( SMC_Security::verify_legacy_audit_anchor( $legacy, $anchor )['valid'] ), 'the lower-assurance legacy snapshot is signed and verified' );

$wpdb->columns = array_merge(
	$legacy_columns,
	array(
		smc_test_schema_column( 'subject_hash', 'char', 'char(64)', 64 ),
		smc_test_schema_column( 'previous_hash', 'char', 'char(64)', 64 ),
		smc_test_schema_column( 'row_hash', 'char', 'char(64)', 64 ),
	)
);
foreach ( $wpdb->rows as &$row ) { $row['subject_hash'] = null; $row['previous_hash'] = ''; $row['row_hash'] = ''; }
unset( $row );
$normalized = SMC_Security::inspect_audit_rows_for_recovery( 2 );
smc_test_assert( $legacy['legacy_snapshot_hash'] === $normalized['legacy_snapshot_hash'], 'adding empty modern columns does not change the sealed legacy snapshot' );
smc_test_assert( ! empty( SMC_Security::verify_legacy_audit_anchor( $normalized, $anchor )['valid'] ), 'the anchor survives the non-destructive schema bridge' );
$stored_anchor = $GLOBALS['smc_anchor_options'][ SMC_Security::LEGACY_AUDIT_ANCHOR_OPTION ];
unset( $GLOBALS['smc_anchor_options'][ SMC_Security::LEGACY_AUDIT_ANCHOR_OPTION ] );
$late_anchor = SMC_Security::establish_legacy_audit_anchor( $normalized );
smc_test_assert( is_wp_error( $late_anchor ) && 'smc_audit_legacy_anchor_source' === $late_anchor->get_error_code(), 'a new anchor cannot be invented after hash columns already exist' );
$GLOBALS['smc_anchor_options'][ SMC_Security::LEGACY_AUDIT_ANCHOR_OPTION ] = $stored_anchor;

$legacy_literal_integrity_key = hash_hkdf( 'sha256', SMC_MASTER_KEY, 32, 'sabri-membership-core:v2', wp_salt( 'auth' ) );
$current_integrity_key = hash_hkdf( 'sha256', $smc_test_master_material, 32, 'sabri-membership-core:v2', SMC_LEGACY_AUTH_SALT );
$v1_payload = array(
	'version' => 1,
	'assurance' => 'legacy_snapshot_only',
	'source_schema' => 'smc-audit-legacy-v1-no-hmac-columns',
	'legacy_cutoff_id' => 2,
	'legacy_row_count' => 2,
	'legacy_snapshot_hash' => $legacy['legacy_snapshot_hash'],
	'chain_initial_previous_hash' => '',
	'created_at' => '2026-08-09 11:59:00',
);
$v1_anchor = $v1_payload;
$v1_anchor['signature'] = hash_hmac( 'sha256', 'smc:audit-legacy-anchor:v1|' . smc_test_canonical_json( $v1_payload ), $current_integrity_key );
smc_test_assert( ! empty( SMC_Security::verify_legacy_audit_anchor( $normalized, $v1_anchor )['valid'] ), 'existing v1 anchors remain verifiable after the keyring and v2 anchor upgrade' );
$record = array(
	'actor_id' => 7,
	'subject_hash' => null,
	'action' => 'two_factor_enabled',
	'details' => '{}',
	'previous_hash' => '',
	'created_at' => '2026-08-09 12:01:00',
);
$record_hash = hash_hmac( 'sha256', smc_test_canonical_json( $record ), $legacy_literal_integrity_key );
$wpdb->rows[] = array_merge( array( 'id'=>3, 'subject_user_id'=>0, 'object_type'=>'', 'object_id'=>0 ), $record, array( 'row_hash'=>$record_hash ) );
$mixed = SMC_Security::inspect_audit_rows_for_recovery( 3 );
smc_test_assert( ! empty( $mixed['valid'] ) && 2 === $mixed['legacy_rows'] && 1 === $mixed['verified_rows'] && $record_hash === $mixed['last_hash'], 'a new HMAC epoch starts after the sealed legacy prefix' );
smc_test_assert( ! empty( $mixed['bridge_recovery_eligible'] ) && 'smc-audit-legacy-v1-bridge-columns' === $mixed['legacy_source_schema'], 'a verified historical-key suffix proves the interrupted additive bridge boundary' );
smc_test_assert( ! empty( SMC_Security::verify_audit_chain( 3 )['valid'] ), 'row-only verification requires and accepts the exact anchor' );

unset( $GLOBALS['smc_anchor_options'][ SMC_Security::LEGACY_AUDIT_ANCHOR_OPTION ] );
$bridge_anchor = SMC_Security::establish_legacy_audit_anchor( $mixed );
smc_test_assert( is_array( $bridge_anchor ) && 2 === (int) ( $bridge_anchor['version'] ?? 0 ) && 'smc-audit-legacy-v1-bridge-columns' === ( $bridge_anchor['source_schema'] ?? '' ), 'an interrupted bridge is anchored only after its modern suffix verifies under a trusted historical key' );
smc_test_assert( ! empty( SMC_Security::verify_legacy_audit_anchor( $mixed, $bridge_anchor )['valid'] ), 'the bridge anchor binds the exact lower-assurance prefix and first verified modern row' );

$wpdb->tail_exists = true;
$wpdb->tail_hash = $record_hash;
smc_test_assert( ! empty( SMC_Security::verify_audit_chain()['valid'] ), 'the serializer tail binds to the verified modern epoch' );

$wpdb->columns[] = smc_test_schema_column( 'audit_key_id', 'varchar', 'varchar(64)', 64 );
$current_record = array(
	'actor_id' => 7,
	'subject_hash' => null,
	'action' => 'recovery_codes_rotated',
	'details' => '{}',
	'previous_hash' => $record_hash,
	'audit_key_id' => SMC_MASTER_KEY_ID,
	'created_at' => '2026-08-09 12:01:30',
);
$current_hash = hash_hmac( 'sha256', smc_test_canonical_json( $current_record ), $current_integrity_key );
$wpdb->rows[] = array_merge( array( 'id'=>4, 'subject_user_id'=>0, 'object_type'=>'', 'object_id'=>0 ), $current_record, array( 'row_hash'=>$current_hash ) );
$wpdb->tail_hash = $current_hash;
$keyed = SMC_Security::inspect_audit_rows_for_recovery( 4 );
smc_test_assert( ! empty( $keyed['valid'] ) && 2 === $keyed['verified_rows'] && $current_hash === $keyed['last_hash'], 'new rows authenticate their explicit current audit key generation while historical rows remain verifiable' );
smc_test_assert( ! empty( SMC_Security::verify_legacy_audit_anchor( $keyed, $bridge_anchor )['valid'] ), 'later keyed rows do not change the anchored legacy boundary' );
smc_test_assert( ! empty( SMC_Security::verify_audit_chain()['valid'] ), 'mixed historical and explicit-key audit epochs bind to one serializer tail' );

$wpdb->rows[0]['details'] = '{"source":"changed"}';
$changed = SMC_Security::inspect_audit_rows_for_recovery( 3 );
$changed_anchor = SMC_Security::verify_legacy_audit_anchor( $changed, $anchor );
smc_test_assert( empty( $changed_anchor['valid'] ) && 'legacy_anchor_snapshot_mismatch' === $changed_anchor['reason'], 'a changed legacy row invalidates the migration anchor' );
$wpdb->rows[0]['details'] = '{"source":"legacy"}';

$wpdb->rows[2]['action'] = 'tampered';
$tampered = SMC_Security::inspect_audit_rows_for_recovery( 4 );
smc_test_assert( empty( $tampered['valid'] ) && 'row_hash_mismatch' === $tampered['reason'] && 3 === $tampered['failed_id'], 'modern HMAC corruption remains fail-closed with the exact row id' );
$wpdb->rows[2]['action'] = 'two_factor_enabled';

$wpdb->rows[3]['action'] = 'tampered_current';
$tampered_current = SMC_Security::inspect_audit_rows_for_recovery( 4 );
smc_test_assert( empty( $tampered_current['valid'] ) && 'row_hash_mismatch' === $tampered_current['reason'] && 4 === $tampered_current['failed_id'], 'explicit key IDs never turn a wrong HMAC into a recoverable key transition' );
$wpdb->rows[3]['action'] = 'recovery_codes_rotated';
$wpdb->rows[3]['audit_key_id'] = 'retired-unavailable-key';
$missing_generation = SMC_Security::inspect_audit_rows_for_recovery( 4 );
smc_test_assert( empty( $missing_generation['valid'] ) && 'audit_key_generation_unavailable' === $missing_generation['reason'] && 4 === $missing_generation['failed_id'], 'an unavailable explicit audit generation fails closed without trying unrelated keys' );
$wpdb->rows[3]['audit_key_id'] = SMC_MASTER_KEY_ID;

$wpdb->rows[] = array( 'id'=>5, 'actor_id'=>7, 'subject_user_id'=>11, 'subject_hash'=>null, 'action'=>'late_legacy', 'object_type'=>'user', 'object_id'=>11, 'details'=>'{}', 'previous_hash'=>'', 'row_hash'=>'', 'audit_key_id'=>null, 'created_at'=>'2026-08-09 12:02:00' );
$late_legacy = SMC_Security::inspect_audit_rows_for_recovery( 5 );
smc_test_assert( empty( $late_legacy['valid'] ) && 'unhashed_row_after_chain_start' === $late_legacy['reason'], 'an unhashed row cannot appear after the modern epoch begins' );

$wpdb->rows = array( array( 'id'=>1, 'actor_id'=>1, 'subject_hash'=>null, 'action'=>'blank', 'details'=>'{}', 'previous_hash'=>'', 'row_hash'=>'', 'created_at'=>'2026-08-09 12:03:00' ) );
$wpdb->columns = array(
	smc_test_schema_column( 'id', 'bigint', 'bigint(20) unsigned', null, 'auto_increment' ),
	smc_test_schema_column( 'actor_id', 'bigint', 'bigint(20) unsigned' ),
	smc_test_schema_column( 'subject_hash', 'char', 'char(64)', 64 ),
	smc_test_schema_column( 'action', 'varchar', 'varchar(80)', 80 ),
	smc_test_schema_column( 'details', 'longtext' ),
	smc_test_schema_column( 'previous_hash', 'char', 'char(64)', 64 ),
	smc_test_schema_column( 'row_hash', 'char', 'char(64)', 64 ),
	smc_test_schema_column( 'created_at', 'datetime' ),
);
$unknown = SMC_Security::inspect_audit_rows_for_recovery( 1 );
smc_test_assert( empty( $unknown['valid'] ) && 'unhashed_row_without_legacy_schema' === $unknown['reason'], 'blank hashes are not accepted without the exact legacy schema signature' );

$wpdb->rows = array( array( 'id'=>1, 'actor_id'=>1, 'subject_user_id'=>2, 'action'=>'blank', 'object_type'=>'user', 'object_id'=>2, 'details'=>'{}', 'created_at'=>'2026-08-09 12:04:00' ) );
$wpdb->columns = $legacy_columns;
$wpdb->columns[4]['CHARACTER_MAXIMUM_LENGTH'] = 79;
$wpdb->columns[4]['COLUMN_TYPE'] = 'varchar(79)';
$wrong_shape = SMC_Security::inspect_audit_rows_for_recovery( 1 );
smc_test_assert( empty( $wrong_shape['valid'] ) && 'unhashed_row_without_legacy_schema' === $wrong_shape['reason'], 'legacy marker names with incompatible column types remain fail-closed' );

echo "Legacy audit snapshot anchor runtime passed.\n";

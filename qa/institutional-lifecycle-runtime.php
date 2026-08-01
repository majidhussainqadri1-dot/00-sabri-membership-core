<?php
/**
 * Runtime regression for File 00 institutional lifecycle repair.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['smc_test_users']   = array();
$GLOBALS['smc_test_options'] = array();
$GLOBALS['smc_test_meta']    = array();
$GLOBALS['smc_test_audits']  = array();
$GLOBALS['smc_test_cleaned'] = array();

function __( $text, $domain = '' ) { unset( $domain ); return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function get_userdata( $user_id ) { return $GLOBALS['smc_test_users'][ (int) $user_id ] ?? false; }
function user_can( $user, $capability ) { return is_object( $user ) && ! empty( $user->caps[ $capability ] ); }
function get_option( $key, $default = false ) { return $GLOBALS['smc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { unset( $autoload ); $GLOBALS['smc_test_options'][ $key ] = $value; return true; }
function get_user_meta( $user_id, $key, $single = true ) { unset( $single ); return $GLOBALS['smc_test_meta'][ $user_id ][ $key ] ?? ''; }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['smc_test_meta'][ $user_id ][ $key ] = $value; return true; }
function delete_user_meta( $user_id, $key ) { unset( $GLOBALS['smc_test_meta'][ $user_id ][ $key ] ); return true; }
function clean_user_cache( $user_id ) { $GLOBALS['smc_test_cleaned'][] = (int) $user_id; }
function current_time( $type, $gmt = false ) { unset( $type, $gmt ); return '2026-08-02 00:00:00'; }
function wp_next_scheduled( $hook ) { unset( $hook ); return true; }
function wp_schedule_event( ...$args ) { unset( $args ); return true; }
function add_filter( ...$args ) { unset( $args ); }
function add_action( ...$args ) { unset( $args ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function smc_age_from_dob( $dob ) { unset( $dob ); return 40; }
function smc_minimum_age_for_gender( $gender ) { return 'female' === $gender ? 12 : 15; }
function smc_is_professional_type( $type ) { return in_array( $type, array( 'doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher' ), true ); }
function smc_role_for_type( $type, $approved = false ) { unset( $type, $approved ); return 'role'; }
function smc_page_url( $key, $fallback = '/' ) { unset( $key ); return $fallback; }
function smc_notify( ...$args ) { unset( $args ); return true; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function smc_is_founder( $user_id ) { return 2 === (int) $user_id; }

class WP_Error {}

final class SMC_Contracts {
	public static function set_exact_role( $user_id, $role ) { unset( $user_id, $role ); return true; }
}

final class SMC_Security {
	public static function subject_hash( $user_id ) { return 'subject-' . (int) $user_id; }
	public static function audit( $action, $subject = 0, $details = array() ) {
		$GLOBALS['smc_test_audits'][] = array( $action, (int) $subject, $details );
		return true;
	}
	public static function decrypt( $value, $purpose, $context = array() ) { unset( $value, $purpose, $context ); return new WP_Error(); }
	public static function process_file_jobs() {}
	public static function private_dir() { return new WP_Error(); }
	public static function verified_unlink( $path ) { unset( $path ); return true; }
	public static function queue_file_job( ...$args ) { unset( $args ); return true; }
}

final class SMC_Test_DB {
	public string $prefix = 'wp_';
	public array $apps = array();
	public array $request_status = array();
	public array $reasons = array();

	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_results( $query, $format = null ) {
		unset( $format );
		if ( is_string( $query ) && str_contains( $query, "status='suspended'" ) ) {
			return array_values( array_filter( $this->apps, static fn( $app ) => 'suspended' === $app['status'] ) );
		}
		if ( is_array( $query ) && str_contains( $query['query'], 'WHERE id>%d' ) ) {
			return array();
		}
		return array();
	}
	public function get_var( $query ) {
		if ( is_array( $query ) && str_contains( $query['query'], 'smc_audit_log' ) ) {
			return json_encode( array( 'reason' => $this->reasons[ $query['args'][0] ] ?? '' ) );
		}
		if ( is_array( $query ) && str_contains( $query['query'], 'smc_verification_requests' ) ) {
			return $this->request_status[ (int) $query['args'][0] ] ?? '';
		}
		return null;
	}
	public function query( $query ) {
		if ( in_array( $query, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return true;
		}
		if ( is_array( $query ) && str_contains( $query['query'], 'UPDATE wp_smc_applications' ) ) {
			list( $status, $time, $id, $version ) = $query['args'];
			unset( $time );
			foreach ( $this->apps as &$app ) {
				if ( (int) $app['id'] === (int) $id && (int) $app['row_version'] === (int) $version && 'suspended' === $app['status'] ) {
					$app['status'] = $status;
					++$app['row_version'];
					return 1;
				}
			}
			unset( $app );
			return 0;
		}
		return 0;
	}
	public function get_col( $query ) { unset( $query ); return array(); }
	public function update( ...$args ) { unset( $args ); return 1; }
}

$wpdb = new SMC_Test_DB();
$GLOBALS['wpdb'] = $wpdb;

require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-lifecycle.php';

$failures = array();
$passed   = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures, &$passed ): void {
	if ( $condition ) {
		++$passed;
		return;
	}
	$failures[] = $message;
};

$GLOBALS['smc_test_users'][1] = (object) array( 'caps' => array( 'manage_options' => true ) );
$GLOBALS['smc_test_users'][2] = (object) array( 'caps' => array() );
$GLOBALS['smc_test_users'][3] = (object) array( 'caps' => array() );
$GLOBALS['smc_test_users'][4] = (object) array( 'caps' => array( 'manage_options' => true ) );

$wpdb->apps = array(
	array( 'id' => 10, 'user_id' => 1, 'status' => 'suspended', 'row_version' => 3, 'membership_type' => 'member' ),
	array( 'id' => 11, 'user_id' => 2, 'status' => 'suspended', 'row_version' => 2, 'membership_type' => 'doctor' ),
	array( 'id' => 12, 'user_id' => 3, 'status' => 'suspended', 'row_version' => 1, 'membership_type' => 'member' ),
	array( 'id' => 13, 'user_id' => 4, 'status' => 'suspended', 'row_version' => 4, 'membership_type' => 'member' ),
);
$wpdb->reasons = array(
	'subject-1' => 'age_eligibility_failed',
	'subject-2' => 'age_eligibility_failed',
	'subject-3' => 'age_eligibility_failed',
	'subject-4' => 'manual_policy_suspension',
);
$wpdb->request_status = array( 1 => 'approved', 2 => 'under_review', 3 => 'approved', 4 => 'approved' );

$count = SMC_Lifecycle::repair_institutional_accounts();
$assert( 2 === $count, 'Exactly two evidence-bound institutional suspensions should be repaired.' );
$assert( 'approved' === $wpdb->apps[0]['status'], 'Administrator should recover the approved verification state.' );
$assert( 'under_review' === $wpdb->apps[1]['status'], 'Founder should recover the non-disciplinary request state.' );
$assert( 'suspended' === $wpdb->apps[2]['status'], 'Ordinary member suspension must remain untouched.' );
$assert( 'suspended' === $wpdb->apps[3]['status'], 'Manual institutional suspension must remain untouched.' );
$repairs = array_values( array_filter( $GLOBALS['smc_test_audits'], static fn( $audit ) => 'institutional_lifecycle_suspension_repaired' === $audit[0] ) );
$assert( 2 === count( $repairs ), 'Each repair must produce an audit event.' );
$assert( in_array( 1, $GLOBALS['smc_test_cleaned'], true ) && in_array( 2, $GLOBALS['smc_test_cleaned'], true ), 'Repaired users must have caches cleared.' );

if ( $failures ) {
	fwrite( STDERR, "institutional lifecycle runtime: {$passed} PASS, " . count( $failures ) . " FAIL\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "institutional lifecycle runtime: {$passed} PASS, 0 FAIL\n";

<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function current_time( $type, $gmt = false ) { unset( $type, $gmt ); return '2026-08-02 16:00:00'; }

final class SMC_Security {
	public static array $audits = array();
	public static function audit( $action, $user_id, $details = array() ) {
		self::$audits[] = array( $action, $user_id, $details );
		return true;
	}
}

final class SMC_Resubmission_DB {
	public string $prefix = 'wp_';
	public int $row_version = 7;
	public array $queries = array();
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_row( $prepared, $mode ) {
		unset( $mode );
		$this->queries[] = $prepared;
		return array( 'row_version' => $this->row_version );
	}
	public function query( $prepared ) {
		if ( is_string( $prepared ) ) {
			$this->queries[] = array( 'query' => $prepared, 'args' => array() );
			return true;
		}
		$this->queries[] = $prepared;
		return 1;
	}
}

$GLOBALS['wpdb'] = new SMC_Resubmission_DB();
require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-workflow.php';

$method = new ReflectionMethod( 'SMC_Workflow', 'transition_self_service' );
$method->setAccessible( true );
$result = $method->invoke( null, 42, 'more_information', 'resubmitted', 'Updated evidence supplied.' );
if ( true !== $result ) {
	fwrite( STDERR, "Resubmission transition failed.\n" );
	exit( 1 );
}

$queries = $GLOBALS['wpdb']->queries;
$select = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q['query'], 'SELECT row_version' ) ) );
$app_update = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q['query'], 'UPDATE wp_smc_applications' ) ) );
$request_update = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q['query'], 'UPDATE wp_smc_verification_requests' ) ) );

$failures = array();
if ( ! $select || ! str_contains( $select[0]['query'], 'FOR UPDATE' ) ) $failures[] = 'Application generation is not locked before resubmission.';
if ( ! $app_update || ! in_array( 8, $app_update[0]['args'], true ) || ! str_contains( $app_update[0]['query'], 'row_version=%d' ) ) $failures[] = 'Application row version did not advance from 7 to 8 atomically.';
if ( ! $request_update || ! in_array( 8, $request_update[0]['args'], true ) || ! str_contains( $request_update[0]['query'], 'applicant_version=%d' ) ) $failures[] = 'Verification request applicant generation did not advance to 8.';
if ( ! SMC_Security::$audits || 8 !== ( SMC_Security::$audits[0][2]['applicant_version'] ?? 0 ) ) $failures[] = 'Resubmission audit did not bind the new applicant generation.';
if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "resubmission generation runtime: 4 PASS, 0 FAIL\n";

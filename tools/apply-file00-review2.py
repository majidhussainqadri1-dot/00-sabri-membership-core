from pathlib import Path

root = Path('.')
workflow = root / 'source/sabri-membership-core/includes/class-smc-workflow.php'
text = workflow.read_text(encoding='utf-8')
old = """\tprivate static function transition_self_service( $user_id, $old, $new, $note ) {
\t\tglobal $wpdb;
\t\t$now = current_time( 'mysql', true );
\t\t$wpdb->query( 'START TRANSACTION' );
\t\t$ok1 = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s\", $new, $now, $user_id, $old ) );
\t\t$ok2 = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,reviewer_note=%s,assigned_reviewer=0,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s\", $new, $note, $now, $user_id, $old ) );
\t\tif ( 1 !== $ok1 || 1 !== $ok2 ) {
\t\t\t$wpdb->query( 'ROLLBACK' );
\t\t\treturn false;
\t\t}
\t\t$wpdb->query( 'COMMIT' );
\t\tSMC_Security::audit( 'membership_' . $new, $user_id, array( 'note' => $note ) );
\t\treturn true;
\t}
"""
new = """\tprivate static function transition_self_service( $user_id, $old, $new, $note ) {
\t\tglobal $wpdb;
\t\t$now = current_time( 'mysql', true );
\t\t$wpdb->query( 'START TRANSACTION' );
\t\t$app = $wpdb->get_row(
\t\t\t$wpdb->prepare(
\t\t\t\t\"SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE\",
\t\t\t\t$user_id,
\t\t\t\t$old
\t\t\t),
\t\t\tARRAY_A
\t\t);
\t\tif ( ! $app ) {
\t\t\t$wpdb->query( 'ROLLBACK' );
\t\t\treturn false;
\t\t}
\t\t$current_version = (int) $app['row_version'];
\t\t$next_applicant_version = $current_version + 1;
\t\t$ok1 = $wpdb->query(
\t\t\t$wpdb->prepare(
\t\t\t\t\"UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=%d,updated_at=%s WHERE user_id=%d AND status=%s AND row_version=%d\",
\t\t\t\t$new,
\t\t\t\t$next_applicant_version,
\t\t\t\t$now,
\t\t\t\t$user_id,
\t\t\t\t$old,
\t\t\t\t$current_version
\t\t\t)
\t\t);
\t\t$ok2 = $wpdb->query(
\t\t\t$wpdb->prepare(
\t\t\t\t\"UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,reviewer_note=%s,assigned_reviewer=0,applicant_version=%d,row_version=row_version+1,updated_at=%s WHERE user_id=%d AND status=%s\",
\t\t\t\t$new,
\t\t\t\t$note,
\t\t\t\t$next_applicant_version,
\t\t\t\t$now,
\t\t\t\t$user_id,
\t\t\t\t$old
\t\t\t)
\t\t);
\t\tif ( 1 !== $ok1 || 1 !== $ok2 ) {
\t\t\t$wpdb->query( 'ROLLBACK' );
\t\t\treturn false;
\t\t}
\t\t$wpdb->query( 'COMMIT' );
\t\tSMC_Security::audit(
\t\t\t'membership_' . $new,
\t\t\t$user_id,
\t\t\tarray(
\t\t\t\t'note'              => $note,
\t\t\t\t'applicant_version' => $next_applicant_version,
\t\t\t)
\t\t);
\t\treturn true;
\t}
"""
if old not in text:
    raise SystemExit('workflow transition block not found')
workflow.write_text(text.replace(old, new), encoding='utf-8')

privacy = root / 'source/sabri-membership-core/includes/class-smc-privacy.php'
text = privacy.read_text(encoding='utf-8')
old = """\t\tif ( is_wp_error( $dir ) ) {
\t\t\treturn array( 'items_removed' => false, 'items_retained' => true, 'messages' => array( $dir->get_error_message() ), 'done' => true );
\t\t}
"""
new = """\t\tif ( is_wp_error( $dir ) ) {
\t\t\treturn array(
\t\t\t\t'items_removed'  => false,
\t\t\t\t'items_retained' => true,
\t\t\t\t'messages'       => array( $dir->get_error_message() ),
\t\t\t\t'done'           => false,
\t\t\t\t'pending'        => true,
\t\t\t);
\t\t}
"""
if old not in text:
    raise SystemExit('privacy directory block not found')
text = text.replace(old, new)
old = """\t\tif ( ! $audit_ok ) {
\t\t\t$messages[] = __( 'Completion evidence could not be appended and requires operator review; the account remains fail-closed.', 'sabri-membership-core' );
\t\t}
\t\treturn array(
\t\t\t'items_removed'  => true,
\t\t\t'items_retained' => true,
\t\t\t'messages'       => $messages,
\t\t\t'done'           => true,
\t\t);
"""
new = """\t\tif ( ! $audit_ok ) {
\t\t\t$messages[] = __( 'Completion evidence could not be appended; the account remains fail-closed and the eraser will retry until completion evidence is recorded.', 'sabri-membership-core' );
\t\t\treturn array(
\t\t\t\t'items_removed'  => true,
\t\t\t\t'items_retained' => true,
\t\t\t\t'messages'       => $messages,
\t\t\t\t'done'           => false,
\t\t\t);
\t\t}
\t\treturn array(
\t\t\t'items_removed'  => true,
\t\t\t'items_retained' => true,
\t\t\t'messages'       => $messages,
\t\t\t'done'           => true,
\t\t);
"""
if old not in text:
    raise SystemExit('privacy audit block not found')
privacy.write_text(text.replace(old, new), encoding='utf-8')

contract = root / 'qa/completion-hardening-contract.mjs'
text = contract.read_text(encoding='utf-8')
marker = "const decryptPosition = workflow.indexOf"
insert = """check(workflow.includes('SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE'), 'resubmission locks the current application generation');
check(workflow.includes('applicant_version=%d,row_version=row_version+1'), 'resubmission advances verification applicant generation');
check(workflow.includes(\"'applicant_version' => $next_applicant_version\"), 'resubmission audit records the new applicant generation');
check(privacy.includes(\"'pending'        => true\"), 'private-storage failure keeps erasure retryable');
check(privacy.includes('the eraser will retry until completion evidence is recorded'), 'audit-evidence failure keeps erasure incomplete');

"""
if marker not in text or insert in text:
    raise SystemExit('completion contract insertion point invalid')
contract.write_text(text.replace(marker, insert + marker), encoding='utf-8')

runtime = root / 'qa/resubmission-generation-runtime.php'
runtime.write_text("""<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function current_time( $type, $gmt = false ) { unset( $type, $gmt ); return '2026-08-02 16:00:00'; }

final class SMC_Security {
\tpublic static array $audits = array();
\tpublic static function audit( $action, $user_id, $details = array() ) {
\t\tself::$audits[] = array( $action, $user_id, $details );
\t\treturn true;
\t}
}

final class SMC_Resubmission_DB {
\tpublic string $prefix = 'wp_';
\tpublic int $row_version = 7;
\tpublic array $queries = array();
\tpublic function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
\tpublic function get_row( $prepared, $mode ) {
\t\tunset( $mode );
\t\t$this->queries[] = $prepared;
\t\treturn array( 'row_version' => $this->row_version );
\t}
\tpublic function query( $prepared ) {
\t\tif ( is_string( $prepared ) ) {
\t\t\t$this->queries[] = array( 'query' => $prepared, 'args' => array() );
\t\t\treturn true;
\t\t}
\t\t$this->queries[] = $prepared;
\t\treturn 1;
\t}
}

$GLOBALS['wpdb'] = new SMC_Resubmission_DB();
require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-workflow.php';

$method = new ReflectionMethod( 'SMC_Workflow', 'transition_self_service' );
$method->setAccessible( true );
$result = $method->invoke( null, 42, 'more_information', 'resubmitted', 'Updated evidence supplied.' );
if ( true !== $result ) {
\tfwrite( STDERR, \"Resubmission transition failed.\\n\" );
\texit( 1 );
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
\tfwrite( STDERR, \"FAILED\\n- \" . implode( \"\\n- \", $failures ) . \"\\n\" );
\texit( 1 );
}
echo \"resubmission generation runtime: 4 PASS, 0 FAIL\\n\";
""", encoding='utf-8')

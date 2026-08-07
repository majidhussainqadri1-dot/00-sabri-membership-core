import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const failures = [];
let passed = 0;
const assert = (condition, label) => { if (condition) passed += 1; else failures.push(label); };

const plugin = read('source/sabri-membership-core/sabri-membership-core.php');
const workflow = read('source/sabri-membership-core/includes/class-smc-workflow.php');
const contracts = read('source/sabri-membership-core/includes/class-smc-contracts.php');
const installer = read('source/sabri-membership-core/includes/class-smc-installer.php');
const completion = read('source/sabri-membership-core/includes/class-smc-completion.php');
const events = read('source/sabri-membership-core/includes/class-smc-events.php');
const admin = read('source/sabri-membership-core/includes/class-smc-admin.php');
const privacy = read('source/sabri-membership-core/includes/class-smc-privacy.php');
const lifecycle = read('source/sabri-membership-core/includes/class-smc-lifecycle.php');
const functions = read('source/sabri-membership-core/includes/functions.php');
const js = read('source/sabri-membership-core/assets/membership.js');
const css = read('source/sabri-membership-core/assets/membership.css');
const registry = JSON.parse(read('qa/requirements-traceability.json'));

assert(plugin.includes('Version: 1.2.14') && plugin.includes("define( 'SMC_VERSION', '1.2.14' )"), 'Runtime version 1.2.14');
assert(plugin.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'Schema version 1.3.0');
assert(plugin.includes("require_once SMC_PATH . 'includes/class-smc-events.php'") && plugin.includes("require_once SMC_PATH . 'includes/class-smc-completion.php'"), 'Completion and events services load');
assert(plugin.includes('SMC_Events::init()') && plugin.includes('SMC_Completion::init()'), 'Completion and events services initialize');

assert((workflow.match(/data-smc-step="[1-7]"/g) || []).length === 7, 'Seven progressive application steps');
assert(workflow.includes('smc-upload-progress') && js.includes("request.upload.addEventListener('progress'"), 'Upload progress is executable');
assert(js.includes('smc_save_application_draft') && completion.includes("const DRAFT_META = '_smc_application_draft_v1'"), 'Encrypted server-side autosave/resume');
assert(completion.includes("SMC_Security::encrypt( wp_json_encode( $data ), 'application-draft'") && completion.includes("SMC_Security::decrypt( $receipt['envelope'], 'application-draft'"), 'Draft encryption round trip');
assert(!/localStorage|sessionStorage|indexedDB/.test(js), 'No sensitive browser persistence');
assert(workflow.includes('$submission_receipt_key') && workflow.includes('add_user_meta( $user_id, $submission_receipt_key') && workflow.includes("'status' => 'completed'"), 'Concurrent duplicate submission receipt');
assert(js.includes('failedSubmission') && js.includes("window.addEventListener('offline'") && js.includes("window.addEventListener('online'"), 'Network failure/recovery UX');
assert(workflow.includes('residence_country') && workflow.includes("SMC_Security::encrypt( $address, 'residential-address'") && installer.includes('address_enc longtext NULL'), 'Private country/city/address constitution');
assert(workflow.includes("name=\"terms\"") && workflow.includes("name=\"ethical\"") && workflow.includes("'membership_terms'") && workflow.includes("'ethical_use'"), 'Separate terms and ethical consent evidence');
assert(functions.includes('function smc_effective_minimum_age') && functions.includes("apply_filters( 'smc_jurisdiction_minimum_age'") && functions.includes('return max('), 'Jurisdiction rule can only raise baseline');

assert(installer.includes('CREATE TABLE {$p}smc_role_grants') && installer.includes('UNIQUE KEY user_type'), 'Canonical multiple-role grants table');
assert(contracts.includes('public static function approved_types') && contracts.includes('public static function replace_requested_types'), 'Multiple-role grant API');
assert(contracts.includes('foreach ( $desired as $role )') && contracts.includes('$user->add_role( $role )'), 'Multiple desired WordPress roles projected');
assert(!contracts.includes("1 === count( array_intersect( $roles, $allowed ) )"), 'Obsolete exact-single-role invariant removed');
assert(contracts.includes('Backward-compatible single-role mutation that preserves all other grants'), 'Legacy role mutation preserves other grants');
assert(privacy.includes('Requested membership roles') && privacy.includes('Approved membership roles'), 'Role grants included in privacy export');

assert(installer.includes('CREATE TABLE {$p}smc_application_repairs') && installer.includes('UNIQUE KEY trace_id'), 'Persistent repair registry');
assert(completion.includes('public static function record_repair') && completion.includes('public static function reconcile_applications'), 'Repair recording and reconciliation');
assert(completion.includes("$attempts >= 10 ? 'dead_letter' : 'retry'"), 'Bounded repair retries and dead letter');
assert(workflow.includes("'application_document_incomplete'") && workflow.includes("'application_submission_reconciliation'"), 'Partial document/submission failures create repair evidence');

assert(installer.includes('queue_type varchar') && installer.includes('conflict_status varchar') && installer.includes('sla_due_at datetime') && installer.includes('trace_id char(36)'), 'Reviewer queue/SLA/conflict/trace schema');
assert(admin.includes('smc_assign_review') && admin.includes('smc_declare_conflict'), 'Reviewer assignment and conflict actions');
assert(admin.includes('smc_review_reason_codes()') && admin.includes("'reason_code'"), 'Governed reason codes');
assert(admin.includes("'restore'") && admin.includes('smc_restore_membership') && admin.includes('independent'), 'Independent appeal restoration');
assert(admin.includes('SMC_Security::session_is_verified') && admin.includes('high-risk'), 'Fresh MFA for high-risk reviewer actions');
assert(admin.includes('sla_due_at') && admin.includes('overdue'), 'SLA and overdue visibility');

assert(completion.includes("header( 'X-Robots-Tag: noindex") && completion.includes("'Cache-Control'] = 'private, no-store") && completion.includes("define( 'DONOTCACHEPAGE', true )"), 'Private routes noindex/noarchive/no-store');
assert(completion.includes("add_filter( 'wp_robots'") && completion.includes("add_filter( 'wp_headers'"), 'WordPress robot/header integration');

assert(installer.includes('CREATE TABLE {$p}smc_event_outbox') && installer.includes('CREATE TABLE {$p}smc_event_inbox'), 'Durable outbox/inbox schema');
assert(events.includes("const VERSION = '1.0.0'") && events.includes('correlation_id') && events.includes('dedupe_hash'), 'Versioned correlated idempotent events');
assert(events.includes("$status = $attempts >= 10 ? 'dead_letter' : 'retry'") && events.includes('Recovered stale processing claim'), 'At-least-once retry/dead-letter/stale recovery');
assert(events.includes('public static function consume') && events.includes('INSERT IGNORE INTO') && events.includes("status='processed'"), 'Replay-safe inbox consumer');
assert(events.includes("has_filter( 'smc_deliver_event' )") && events.includes("apply_filters( 'smc_deliver_event', false"), 'No false delivery without consumer acknowledgment');
assert(!events.includes("'reason', 'scope'"), 'Free-text reviewer reason excluded from event payload allowlist');
assert(lifecycle.includes('smc_process_event_outbox') && lifecycle.includes('smc_reconcile_applications'), 'Lifecycle schedules event and repair workers');

assert(completion.includes('public static function safe_mode') && completion.includes('enforce_safe_mode'), 'Safe Mode enforcement');
assert(completion.includes('health_snapshot') && completion.includes('repair_backlog') && completion.includes('outbox_backlog') && completion.includes('review_overdue'), 'Privacy-safe health and backlog visibility');
assert(completion.includes('backup_manifest') && completion.includes('post_restore_reconcile'), 'Backup manifest and post-restore reconciliation');
assert(completion.includes('operational_owners') && completion.includes('service_levels'), 'Named operational owners and measurable SLO defaults');
assert(css.includes('.smc-step') && css.includes(':focus-visible') && css.includes('@media'), 'Progressive responsive accessible styling');

assert(registry.plugin_version === '1.2.11' && registry.database_version === '1.3.0', 'Historical three-plan traceability release identity remains immutable');
assert(registry.dual_plan_completion?.runtime_contract === 'qa/dual-plan-runtime-completion-contract.mjs', 'Registry points to executable runtime contract');
assert(registry.requirements.length === 100 && registry.requirements.every((r) => r.code_status === 'complete'), 'All 100 historical repository obligations remain mapped after runtime checks');
assert(registry.staging_accepted === false && registry.production_approved === false && registry.live_installation_authorized === false, 'External gates not overclaimed');

if (failures.length) {
  console.error(`dual-plan runtime completion: ${passed} PASS, ${failures.length} FAIL`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log(`dual-plan runtime completion: ${passed} PASS, 0 FAIL`);

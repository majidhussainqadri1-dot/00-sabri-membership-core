import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = process.env.SMC_PLUGIN_DIR
  ? path.resolve(process.env.SMC_PLUGIN_DIR)
  : path.join(root, 'source', 'sabri-membership-core');

const read = (name) => fs.readFileSync(path.join(plugin, 'includes', name), 'utf8');
const compat = read('class-smc-host-compat.php');
const workflow = read('class-smc-workflow.php');
const admin = read('class-smc-admin.php');
const completion = read('class-smc-completion.php');
const threePlan = read('class-smc-three-plan.php');
const security = read('class-smc-security.php');
const js = fs.readFileSync(path.join(plugin, 'assets', 'membership.js'), 'utf8');

const failures = [];
let passed = 0;
const check = (condition, label) => {
  if (condition) passed += 1;
  else failures.push(label);
};

const workflowActions = [
  'submit_application',
  'request_contact_otp',
  'verify_contact_otp',
  'revoke_session',
  'revoke_all_sessions',
  'resubmit',
  'appeal',
  'withdraw_guardian',
  'verify_guardian',
];
const adminActions = [
  'review_transition',
  'review_document',
  'assign_review',
  'declare_conflict',
  'save_founder',
];
const completionActions = [
  'retry_repair',
  'retry_outbox',
  'post_restore_reconcile',
  'download_backup_manifest',
  'create_retention_hold',
  'release_retention_hold',
];
const threePlanActions = ['save_institutional_ai'];
const streamingActions = ['private_document'];
const currentActions = [
  ...workflowActions,
  ...adminActions,
  ...completionActions,
  ...threePlanActions,
  ...streamingActions,
];
const retiredMfaActions = [
  'start_2fa',
  'finish_2fa',
  'challenge_2fa',
  'rotate_recovery',
  'ack_recovery_receipt',
];

for (const action of currentActions) {
  check(compat.includes(`'${action}'`), `Host compatibility registry covers ${action}`);
}
for (const action of retiredMfaActions) {
  check(!compat.includes(`'${action}'`), `Host compatibility registry excludes retired MFA action ${action}`);
  check(!workflow.includes(`'${action}'`), `Workflow registration excludes retired MFA action ${action}`);
  check(!workflow.includes(`function handle_${action}`), `Dead retired MFA handler is absent: ${action}`);
}

check(compat.includes("add_action( 'init', array( __CLASS__, 'protect_actions' ), PHP_INT_MAX )"), 'Protection is installed after component registration');
check(compat.includes("remove_action( $hook, $definition['callback'] )"), 'Native privileged callbacks are replaced rather than double-fired');
check(compat.includes("add_action( $hook, array( __CLASS__, 'dispatch' ), 10, 0 )"), 'Privileged button actions use the common dispatcher');
check(compat.includes("admin_post_nopriv_smc_"), 'Public guardian action is also protected');
check(compat.includes('catch ( Throwable $error )'), 'Dispatcher catches PHP Throwable failures');
check(compat.includes('smc_last_action_runtime_failure'), 'Runtime button failures retain bounded diagnostics');
check(compat.includes("if ( empty( $all[ $action ]['streaming'] ) )"), 'Unexpected handler returns avoid blank admin-post pages');
check(compat.includes("'smc_message' => 'provider'"), 'User-facing failures return a controlled message');

for (const action of workflowActions.filter((name) => name !== 'verify_guardian')) {
  check(workflow.includes(`'${action}'`), `Workflow registers ${action}`);
  check(workflow.includes(`function handle_${action}`), `Workflow implements handle_${action}`);
}
check(workflow.includes("admin_post_nopriv_smc_verify_guardian"), 'Guardian verification has a public WordPress action');
check(workflow.includes('function handle_verify_guardian'), 'Guardian verification handler exists');

for (const [action, method] of [
  ['review_transition', 'handle_transition'],
  ['review_document', 'handle_document'],
  ['assign_review', 'handle_assignment'],
  ['declare_conflict', 'handle_conflict'],
  ['save_founder', 'save_founder'],
]) {
  check(admin.includes(`admin_post_smc_${action}`), `Admin action exists: ${action}`);
  check(admin.includes(`function ${method}`), `Admin handler exists: ${method}`);
}
for (const action of completionActions) {
  check(completion.includes(`admin_post_smc_${action}`), `Completion action exists: ${action}`);
  check(completion.includes(`function ${action}`), `Completion handler exists: ${action}`);
}
check(threePlan.includes('admin_post_smc_save_institutional_ai'), 'Institutional AI settings action exists');
check(threePlan.includes('function save_institutional_ai'), 'Institutional AI settings handler exists');
check(security.includes('admin_post_smc_private_document'), 'Private document action exists');
check(security.includes('function serve_document'), 'Private document streaming handler exists');

check(js.includes("previous?.addEventListener('click'"), 'Application Previous button has a client-side handler');
check(js.includes("next?.addEventListener('click'"), 'Application Next button has a client-side handler');
check(
  js.includes("retryButton?.addEventListener('click', () => form.requestSubmit())") ||
    js.includes("retryButton?.addEventListener('click',()=>form.requestSubmit())"),
  'Application Retry button re-enters the native submission path'
);
check(js.includes("form.addEventListener('submit', prepareNativeSubmission)"), 'Application Submit button uses guarded native multipart submission');
check(js.includes('prepareNativeSubmission'), 'Application native-submit guard exists');
check(!js.includes('new XMLHttpRequest'), 'Application private evidence does not depend on XHR on shared hosting');
check(js.includes('form.checkValidity()') && js.includes('form.reportValidity()'), 'Native submission keeps browser validation and a recoverable invalid-form path');

console.log(`All-button action contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`All-button action contract assertions failed: ${failures.length}`);
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}
console.log('All-button action contract assertions failed: 0');

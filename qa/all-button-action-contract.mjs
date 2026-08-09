import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = process.env.SMC_PLUGIN_DIR
  ? path.resolve(process.env.SMC_PLUGIN_DIR)
  : path.join(root, 'source', 'sabri-membership-core');

const compat = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-host-compat.php'), 'utf8');
const workflow = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-workflow.php'), 'utf8');
const admin = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-admin.php'), 'utf8');
const security = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-security.php'), 'utf8');
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
  'start_2fa',
  'finish_2fa',
  'challenge_2fa',
  'rotate_recovery',
  'ack_recovery_receipt',
  'revoke_session',
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
const streamingActions = ['private_document'];
const allActions = [...workflowActions, ...adminActions, ...streamingActions];

for (const action of allActions) {
  check(compat.includes(`'${action}'`), `Host compatibility registry covers ${action}`);
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
  const method = `handle_${action}`;
  check(workflow.includes(`function ${method}`), `Workflow implements ${method}`);
}
check(workflow.includes("admin_post_nopriv_smc_verify_guardian"), 'Guardian verification has a public WordPress action');
check(workflow.includes('function handle_verify_guardian'), 'Guardian verification handler exists');

check(admin.includes("admin_post_smc_review_transition"), 'Reviewer transition action exists');
check(admin.includes("admin_post_smc_review_document"), 'Document review action exists');
check(admin.includes("admin_post_smc_assign_review"), 'Reviewer assignment action exists');
check(admin.includes("admin_post_smc_declare_conflict"), 'Conflict declaration action exists');
check(admin.includes("admin_post_smc_save_founder"), 'Founder settings action exists');
check(security.includes("admin_post_smc_private_document"), 'Private document action exists');

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

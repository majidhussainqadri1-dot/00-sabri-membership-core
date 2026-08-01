import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = process.env.SMC_PLUGIN_DIR
  ? path.resolve(process.env.SMC_PLUGIN_DIR)
  : path.join(root, 'source', 'sabri-membership-core');

const functions = fs.readFileSync(path.join(plugin, 'includes', 'functions.php'), 'utf8');
const contracts = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-contracts.php'), 'utf8');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const statusFunction = functions.match(/function smc_statuses\(\) \{[\s\S]*?\n\}/)?.[0] ?? '';
const stateFunction = functions.match(/function smc_membership_state\( \$user_id \) \{[\s\S]*?\n\}/)?.[0] ?? '';
const failures = [];
let passed = 0;

function assert(condition, name) {
  if (condition) passed += 1;
  else failures.push(name);
}

assert(main.includes('Version: 1.2.3'), 'Plugin header is 1.2.3');
assert(main.includes("define( 'SMC_VERSION', '1.2.3' )"), 'Runtime version is 1.2.3');
assert(main.includes("define( 'SMC_DB_VERSION', '1.2.0' )"), 'Database version remains 1.2.0');
assert(main.includes("define( 'SMC_CONTRACT_VERSION', '1.1.2' )"), 'Contract version is 1.1.2');
assert(stateFunction.length > 0, 'Explicit membership-state API exists');
assert(/\$institutional\s*=\s*\$is_founder\s*\|\|\s*\$is_admin/.test(stateFunction), 'Institutional authority is resolved explicitly');
assert(stateFunction.indexOf('if ( $institutional )') < stateFunction.indexOf('if ( $row && $status )'), 'Institutional authority is evaluated before ordinary application state');
assert(/\$hard_blocks\s*=\s*array\( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' \)/.test(stateFunction), 'Hard-block statuses remain explicit');
assert(stateFunction.includes("'application_status'    => $status"), 'Underlying application state remains observable');
assert(stateFunction.includes("'status'                => 'verified'"), 'Institutional compatibility status exists');
assert(stateFunction.includes("'status'                => 'not_enrolled'"), 'Non-enrolled state is distinct from draft');
assert(stateFunction.includes("'status'                => 'invalid_application'"), 'Corrupt application state is explicit and fail closed');
assert(stateFunction.includes("'application_exists'    => (bool) $row"), 'Institutional rows preserve application existence');
assert(stateFunction.includes("'account_class'         => $is_founder ? 'founder' : 'administrator'"), 'Institutional account class is explicit');
assert(functions.includes("return $state['status'];"), 'Legacy status API delegates to explicit state');
assert(!functions.includes("return $row && isset( smc_statuses()[ $row['status'] ] ) ? $row['status'] : 'draft';"), 'Absent applications no longer collapse into draft');
assert(!statusFunction.includes("'verified'"), 'Synthetic verified state is not a persistent workflow status');
assert(!statusFunction.includes("'not_enrolled'"), 'Synthetic not-enrolled state is not a persistent workflow status');
assert(!statusFunction.includes("'invalid_application'"), 'Synthetic invalid-application state is not a persistent workflow status');
assert(contracts.includes('$state   = smc_membership_state( $user_id );'), 'Assertions consume the explicit state contract');
assert(contracts.includes("'application_exists'     => (bool) $state['application_exists']"), 'Assertions expose application existence');
assert(contracts.includes("'institutional_account'  => (bool) $state['institutional_account']"), 'Assertions expose institutional account state');
assert(contracts.includes("'account_class'          => $state['account_class']"), 'Assertions expose account class');
assert(contracts.includes("$approved = (bool) $state['approved'];"), 'Assertions use the canonical approval decision');
assert(!contracts.includes("$status  = $row ? $row['status'] : 'draft';"), 'Assertions no longer fabricate draft status');

console.log(`Membership-state contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`Membership-state contract assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('Membership-state contract assertions failed: 0');

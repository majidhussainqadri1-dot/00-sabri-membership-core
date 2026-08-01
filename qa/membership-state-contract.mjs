import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = process.env.SMC_PLUGIN_DIR
  ? path.resolve(process.env.SMC_PLUGIN_DIR)
  : path.join(root, 'source', 'sabri-membership-core');

const functions = fs.readFileSync(path.join(plugin, 'includes', 'functions.php'), 'utf8');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const failures = [];
let passed = 0;

function assert(condition, name) {
  if (condition) passed += 1;
  else failures.push(name);
}

assert(main.includes('Version: 1.2.1'), 'Plugin header is 1.2.1');
assert(main.includes("define( 'SMC_VERSION', '1.2.1' )"), 'Runtime version is 1.2.1');
assert(main.includes("define( 'SMC_DB_VERSION', '1.2.0' )"), 'Database version remains 1.2.0');
assert(main.includes("define( 'SMC_CONTRACT_VERSION', '1.1.0' )"), 'Contract version is 1.1.0');
assert(functions.includes('function smc_membership_state( $user_id )'), 'Explicit membership-state API exists');
assert(functions.includes("'application_exists'    => true"), 'Existing applications are explicit');
assert(functions.includes("'application_exists'    => false"), 'Absent applications are explicit');
assert(functions.includes("'status'                => 'verified'"), 'Institutional account compatibility status exists');
assert(functions.includes("'status'                => 'not_enrolled'"), 'Non-enrolled state is distinct from draft');
assert(functions.includes("$is_founder = $user_id > 0 && smc_is_founder( $user_id )"), 'Canonical Founder is checked');
assert(functions.includes("$is_admin   = $user && user_can( $user, 'manage_options' )"), 'Administrator is checked through WordPress capability');
assert(functions.includes("'account_class'         => $is_founder ? 'founder' : 'administrator'"), 'Institutional account class is explicit');
assert(functions.includes("return $state['status'];"), 'Legacy status API delegates to explicit state');
assert(!functions.includes("return $row && isset( smc_statuses()[ $row['status'] ] ) ? $row['status'] : 'draft';"), 'Absent applications no longer collapse into draft');

console.log(`Membership-state contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`Membership-state contract assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('Membership-state contract assertions failed: 0');

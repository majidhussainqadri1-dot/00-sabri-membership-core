import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const auth = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-authorization.php'), 'utf8');
const failures = [];
let passed = 0;

function assert(condition, name) {
  if (condition) passed += 1;
  else failures.push(name);
}

assert(main.includes('Version: 1.2.13'), 'Plugin header is 1.2.13');
assert(main.includes("define( 'SMC_VERSION', '1.2.13' )"), 'Runtime version is 1.2.13');
assert(main.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'Database version is 1.3.0');
assert(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.0' )"), 'Contract version remains 1.2.0 for consumer compatibility');
assert(main.includes("require_once SMC_PATH . 'includes/class-smc-authorization.php'"), 'Authorization boundary is loaded');
assert(main.includes('SMC_Authorization::init()'), 'Authorization boundary is initialized');
assert(main.includes("$policy = smc_policy();"), 'Client policy derives from canonical server policy');

for (const symbol of [
  "remove_action( 'template_redirect', array( 'SMC_Contracts', 'enforce_frontend_state' ), 1 )",
  "remove_action( 'admin_init', array( 'SMC_Contracts', 'enforce_admin_state' ), 1 )",
  "remove_filter( 'rest_authentication_errors', array( 'SMC_Contracts', 'enforce_rest_state' ), 90 )",
  "remove_filter( 'user_has_cap', array( 'SMC_Contracts', 'filter_capabilities' ), 90 )",
  'smc_restricted_capabilities',
  'smc_hard_block_statuses',
  'smc_membership_recovery_actions',
  'smc_membership_recovery_rest_routes',
  'smc_rest_request_requires_membership',
  'effective_eligible',
  'guardian_verified',
  'email_verified',
  'phone_verified',
  'institutional_account',
  'session_two_factor',
  'smc_membership_hard_block',
  'Founder reassignment is locked',
]) {
  assert(auth.includes(symbol), `Authorization control: ${symbol}`);
}

assert(!auth.includes("0 === strpos( $action, 'sa_' )"), 'No broad sa_ recovery bypass exists');
assert(!auth.includes("0 === strpos( $action, 'smc_' ) ||"), 'No broad smc_ recovery bypass exists');
assert(auth.includes("in_array( $action, self::recovery_actions(), true )"), 'Recovery actions use exact allowlist matching');
assert(auth.includes("rest_is_recovery_route( $route, $method )"), 'REST recovery matching is route-and-method exact');
assert(auth.includes("if ( $current && $current !== $requested )"), 'Founder clearing and reassignment are both locked');
assert(auth.includes("array( 'GET', 'HEAD', 'OPTIONS' )"), 'Safe REST methods remain readable by default');
assert(auth.includes("isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] )"), 'REST route discovery guards the global WordPress object');
assert(auth.includes("array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' )"), 'Hard-block census is explicit and fail-closed');
assert(auth.includes("$allcaps[ $cap ] = false"), 'Restricted capabilities are explicitly denied');

if (failures.length) {
  console.error(`authorization boundary contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`authorization boundary contract: ${passed} PASS, 0 FAIL`);

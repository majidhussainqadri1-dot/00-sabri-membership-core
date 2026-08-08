import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const contract = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-cf01-contract.php'), 'utf8');
const failures = [];
let passed = 0;
function assert(condition, name) { if (condition) passed += 1; else failures.push(name); }

assert(main.includes('Version: 1.2.16'), 'Plugin header is 1.2.16');
assert(main.includes("define( 'SMC_VERSION', '1.2.16' )"), 'Runtime version is 1.2.16');
assert(main.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'Database version is 1.3.0');
assert(main.includes("define( 'SMC_CF01_CONTRACT_VERSION', '1.0.0' )"), 'CF-01 contract version is declared');
assert(main.includes("require_once SMC_PATH . 'includes/class-smc-cf01-contract.php'"), 'CF-01 provider is loaded');
assert(main.includes('SMC_CF01_Contract::init()'), 'CF-01 provider is initialized');
assert(contract.includes("const CONTRACT_NAME    = 'smc.cf01.membership-assurance'"), 'Named contract exists');
assert(contract.includes("'result'           => 'unknown'"), 'Assertion defaults fail-unknown');
assert(contract.includes("'key_recovery'           => false"), 'Membership never grants key recovery');
assert(/'platform_uuid'\s*=>\s*\$subject_uuid/.test(contract), 'Opaque platform UUID is returned');
assert(/'record_version'\s*=>\s*\$record_version/.test(contract), 'Source record version is returned');
assert(contract.includes("'guardian_required'"), 'Guardian state is explicit');
assert(contract.includes("'jurisdiction_context'"), 'Jurisdiction context is explicit');
assert(contract.includes("SMC_Security::decrypt( $encrypted, 'totp-secret'"), 'Second-factor secret stays inside File 00');
assert(!/'secret'\s*=>/.test(contract), 'No secret field is returned');
assert(contract.includes("SMC_Security::audit( 'cf01_step_up_verified'"), 'Successful step-up requires audit evidence');
assert(contract.includes("'audit_commit_failed'"), 'Audit failure denies assurance');

console.log(`CF-01 File 00 contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`CF-01 File 00 contract assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('CF-01 File 00 contract assertions failed: 0');

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const lifecycle = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-lifecycle.php'), 'utf8');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const readme = fs.readFileSync(path.join(plugin, 'README.txt'), 'utf8');
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
assert(main.includes("SMC_Lifecycle::repair_institutional_accounts()"), 'Release path invokes bounded institutional repair');
assert(main.includes("smc_institutional_repair_version"), 'Repair is version-bounded');
assert(lifecycle.includes('public static function repair_institutional_accounts()'), 'Institutional repair entry point exists');
assert(lifecycle.includes("self::AUTOMATED_AGE_REASON !== self::latest_restriction_reason"), 'Repair requires matching automated audit reason');
assert(lifecycle.includes("latest_restriction_reason"), 'Repair reads tamper-evident restriction context');
assert(lifecycle.includes("institutional_lifecycle_suspension_repaired"), 'Repair produces explicit audit evidence');
assert(lifecycle.includes("institutional_age_evidence_attention_required"), 'Missing institutional evidence produces an attention event');
assert(lifecycle.includes("if ( self::is_institutional_user( $user_id ) )"), 'Institutional guard precedes ordinary restriction');
assert(lifecycle.includes("self::restrict( $app, self::AUTOMATED_AGE_REASON )"), 'Ordinary accounts remain subject to age enforcement');
assert(lifecycle.includes("WHERE id=%d AND row_version=%d AND status='suspended'"), 'Repair uses optimistic and state-specific update');
assert(lifecycle.includes("$restored_status = in_array( $request_status, $restorable, true ) ? $request_status : 'draft'"), 'Repair restores only allowlisted non-disciplinary state');
assert(readme.includes('Stable tag: 1.2.3'), 'WordPress readme stable tag is 1.2.3');

if (failures.length) {
  console.error(`institutional lifecycle contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`institutional lifecycle contract: ${passed} PASS, 0 FAIL`);

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const contract = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-cf01-contract.php'), 'utf8');
const failures = [];
let passed = 0;
function assert(condition, name) { if (condition) passed += 1; else failures.push(name); }

assert(main.includes('Version: 1.2.36'), 'Plugin header is 1.2.36');
assert(main.includes("define( 'SMC_VERSION', '1.2.36' )"), 'Runtime version is 1.2.36');
assert(main.includes("define( 'SMC_DB_VERSION', '1.4.4' )"), 'Database version is 1.4.4');
assert(main.includes("define( 'SMC_CF01_CONTRACT_VERSION', '1.1.0' )"), 'CF-01 membership-only contract version is declared');
assert(main.includes("require_once SMC_PATH . 'includes/class-smc-cf01-contract.php'"), 'CF-01 provider is loaded');
assert(main.includes("array( 'SMC_CF01_Contract', 'init' )"), 'CF-01 provider is initialized');
assert(contract.includes("const CONTRACT_NAME    = 'smc.cf01.membership-assurance'"), 'Named membership-assurance contract exists');
assert(contract.includes("const CONTRACT_VERSION = '1.1.0'"), 'CF-01 contract implementation is 1.1.0');
assert(contract.includes("'authorization_scope'         => 'membership_prerequisite_only'"), 'CF-01 allow is explicitly limited to membership prerequisite');
assert(contract.includes("'authentication_assurance'    => 'not_owned_by_file00'"), 'Authentication assurance is explicitly outside File 00');
assert(contract.includes("'authentication_owner'        => 'file02_or_consumer'"), 'Authentication owner is delegated outside File 00');
assert(contract.includes("'file00_mfa_required'         => false"), 'CF-01 declares File 00 MFA retired');
assert(contract.includes("'mfa_required'       => false"), 'Membership envelope carries no File 00 MFA requirement');
assert(contract.includes("'mfa_owner'          => 'none'"), 'Membership envelope exposes no File 00 MFA owner');
assert(contract.includes("'key_recovery'           => false"), 'Membership never grants key recovery');
assert(/'platform_uuid'\s*=>\s*\$subject_uuid/.test(contract), 'Opaque platform UUID is returned');
assert(/'record_version'\s*=>\s*\$record_version/.test(contract), 'Source record version is returned');
assert(contract.includes("'guardian_required'"), 'Guardian state is explicit');
assert(contract.includes("'jurisdiction_context'"), 'Jurisdiction context is explicit');
assert(contract.includes('public static function verify_step_up'), 'Compatibility step-up method remains deterministic');
assert(contract.includes("'reason_code'       => 'authentication_assurance_not_owned_by_file00'"), 'Compatibility step-up fails unknown to the authentication owner');
assert(contract.includes('unset( $code );'), 'File 00 explicitly ignores compatibility factor input');
assert(!contract.includes("'_smc_totp_secret_enc'"), 'CF-01 does not read File 00 TOTP secrets');
assert(!contract.includes('verify_setup_code'), 'CF-01 does not verify TOTP codes');
assert(!contract.includes('consume_recovery_code_atomic'), 'CF-01 does not consume File 00 recovery codes');
assert(!contract.includes('smc_recovery_codes'), 'CF-01 does not access recovery-code storage');
assert(!contract.includes('cf01_step_up_verified'), 'CF-01 no longer emits File 00 second-factor success claims');
assert(!/'secret'\s*=>/.test(contract), 'No secret field is returned');

console.log(`CF-01 File 00 contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`CF-01 File 00 contract assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('CF-01 File 00 contract assertions failed: 0');

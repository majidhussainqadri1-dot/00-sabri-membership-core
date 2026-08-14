import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const main = fs.readFileSync(new URL('source/sabri-membership-core/sabri-membership-core.php', root), 'utf8');
const base = fs.readFileSync(new URL('source/sabri-membership-core/includes/class-smc-authentication-contract.php', root), 'utf8');
const v11 = fs.readFileSync(new URL('source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php', root), 'utf8');

for (const marker of [
  'Version: 1.2.44',
  "define( 'SMC_VERSION', '1.2.44' );",
  "define( 'SMC_DB_VERSION', '1.4.5' );",
  "define( 'SMC_CONTRACT_VERSION', '1.2.3' );",
  "define( 'SMC_AUTHENTICATION_CONTRACT_VERSION', '1.0.0' );",
  "define( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION', '1.1.0' );",
  "require_once SMC_PATH . 'includes/class-smc-authentication-contract.php';",
  "require_once SMC_PATH . 'includes/class-smc-authentication-contract-v11.php';",
  "array( 'SMC_Authentication_Contract_V11', 'init' )",
]) {
  assert.ok(main.includes(marker), `bootstrap missing ${marker}`);
}
assert.equal(main.includes("array( 'SMC_Authentication_Contract', 'init' )"), false, 'legacy v1 helper must not be initialized as an active contract');
assert.ok(main.includes("array( 'SMC_Authentication_Contract', 'register_exporter' )"), 'legacy receipt exporter was not preserved');
assert.ok(main.includes("array( 'SMC_Authentication_Contract', 'register_eraser' )"), 'legacy receipt eraser was not preserved');

for (const marker of [
  "CONTRACT_NAME    = 'smc.authentication-account'",
  "CONTRACT_VERSION = '1.0.0'",
  'register_account',
  'mark_email_verified',
  'get_completion_state',
]) {
  assert.ok(base.includes(marker), `internal v1 transaction helper missing ${marker}`);
}

for (const marker of [
  "CONTRACT_NAME        = 'smc.authentication-account'",
  "CONTRACT_VERSION     = '1.1.0'",
  'register_account',
  'mark_email_verified',
  'get_completion_state',
  'SMC_CF01_Contract::ensure_subject_uuid',
  "result['subject_uuid'] = $subject_uuid",
  "array_diff( $missing, array( 'two_factor' ) )",
  "SMC_Security::revoke_all_sessions( $user_id, 'registration_extra_initialization_failed' )",
  "'mfa_owner'        => 'none'",
]) {
  assert.ok(v11.includes(marker), `v1.1 provider missing ${marker}`);
}
assert.equal(v11.includes('wp_destroy_all_sessions'), false, 'v1.1 provider bypasses canonical File 00 session revocation');
assert.ok(v11.includes("result['contract_version'] = self::CONTRACT_VERSION"), 'v1.1 normalize does not preserve provider identity');
assert.ok(v11.includes("$allowed_types   = array_keys( smc_account_types() );"), 'v1.1 provider is not bound to File 00 canonical account taxonomy');
assert.equal(v11.includes("'clinic_staff'"), false, 'legacy clinic_staff alias remains hard-coded in provider');
assert.equal(v11.includes("'institution_representative'"), false, 'legacy institution_representative alias remains hard-coded in provider');

console.log('File 00 v1.2.44 -> File 02 account-contract source guard passed.');

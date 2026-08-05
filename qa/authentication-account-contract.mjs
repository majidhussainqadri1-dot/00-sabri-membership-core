import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const main = fs.readFileSync(new URL('source/sabri-membership-core/sabri-membership-core.php', root), 'utf8');
const provider = fs.readFileSync(new URL('source/sabri-membership-core/includes/class-smc-authentication-contract.php', root), 'utf8');

for (const marker of [
  "Version: 1.2.12",
  "define( 'SMC_VERSION', '1.2.12' );",
  "define( 'SMC_AUTHENTICATION_CONTRACT_VERSION', '1.0.0' );",
  "class-smc-authentication-contract.php",
  'SMC_Authentication_Contract::init()',
]) {
  assert.ok(main.includes(marker), `main bootstrap missing ${marker}`);
}

for (const marker of [
  "CONTRACT_NAME    = 'smc.authentication-account'",
  "CONTRACT_VERSION = '1.0.0'",
  'register_account',
  'mark_email_verified',
  'get_completion_state',
  'wp_insert_user',
  'smc_application',
  'SMC_Security::encrypt',
  'SMC_Security::blind_index',
  'guardian_required',
  'identity_collision',
  'phone_collision',
  'idempotent_replay',
  'invalid_application',
  'contact_verified',
  'two_factor_ready',
  'missing_steps',
  'next_route',
  'register_exporter',
  'register_eraser',
]) {
  assert.ok(provider.includes(marker), `provider missing ${marker}`);
}

for (const forbidden of [
  /add_role\s*\(/,
  /remove_role\s*\(/,
  /->\s*set_role\s*\(/,
  /SMC_Security::verify_totp/,
  /SMC_Security::consume_recovery_code/,
  /access_token/i,
  /refresh_token/i,
]) {
  assert.equal(forbidden.test(provider), false, `provider contains forbidden boundary: ${forbidden}`);
}

assert.ok(provider.includes("return self::response( 'unknown', 'provider_safe_mode' )"), 'safe mode is not fail closed');
assert.ok(provider.includes("hash_equals( strtolower( (string) $user->user_email ), strtolower( $email ) )"), 'email completion is not bound to the canonical account email');
assert.ok(provider.includes("SMC_Contracts::identity_documents_current"), 'identity completion truth is not delegated to File 00');
assert.ok(provider.includes("SMC_Contracts::guardian_verified"), 'guardian completion truth is not delegated to File 00');
assert.ok(provider.includes("SMC_Security::revoke_all_sessions"), 'quarantined accounts are not contained');

console.log('File 00 authentication-account contract architecture passed.');

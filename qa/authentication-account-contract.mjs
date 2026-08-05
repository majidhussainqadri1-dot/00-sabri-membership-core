import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const main = fs.readFileSync(new URL('source/sabri-membership-core/sabri-membership-core.php', root), 'utf8');
const provider = fs.readFileSync(new URL('source/sabri-membership-core/includes/class-smc-authentication-contract.php', root), 'utf8');
const providerV11 = fs.readFileSync(new URL('source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php', root), 'utf8');

for (const marker of [
  'Version: 1.2.11',
  "define( 'SMC_VERSION', '1.2.11' );",
  "define( 'SMC_AUTHENTICATION_CONTRACT_VERSION', '1.1.0' );",
  "define( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION', '1.1.0' );",
  'class-smc-authentication-contract.php',
  'class-smc-authentication-contract-v11.php',
  'SMC_Authentication_Contract::init()',
  'SMC_Authentication_Contract_V11::init()',
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
  'identity_type',
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
  assert.ok(provider.includes(marker), `base provider missing ${marker}`);
}

assert.match(providerV11, /const\s+CONTRACT_VERSION\s*=\s*'1\.1\.0';/, 'v1.1 provider contract version is missing');
for (const marker of [
  'city',
  'account_type',
  'ethical_conduct_version',
  'profile_photo_required',
  'authentication_method',
  'google_subject',
  'SMC_Security::encrypt',
  'SMC_Security::decrypt',
  'smc_profile_photo_complete',
  'smc_profile_completion_route',
  'registration_extra_quarantined',
  'register_exporter',
  'register_eraser',
]) {
  assert.ok(providerV11.includes(marker), `v1.1 provider missing ${marker}`);
}

for (const source of [provider, providerV11]) {
  for (const forbidden of [
    /add_role\s*\(/,
    /remove_role\s*\(/,
    /->\s*set_role\s*\(/,
    /SMC_Security::verify_totp/,
    /SMC_Security::consume_recovery_code/,
    /access_token/i,
    /refresh_token/i,
    /client_secret/i,
  ]) {
    assert.equal(forbidden.test(source), false, `provider contains forbidden boundary: ${forbidden}`);
  }
}

assert.ok(provider.includes("return self::response( 'unknown', 'provider_safe_mode' )"), 'safe mode is not fail closed');
assert.ok(provider.includes("hash_equals( strtolower( (string) $user->user_email ), strtolower( $email ) )"), 'email completion is not bound to the canonical account email');
assert.ok(provider.includes('SMC_Contracts::identity_documents_current'), 'identity completion truth is not delegated to File 00');
assert.ok(provider.includes('SMC_Contracts::guardian_verified'), 'guardian completion truth is not delegated to File 00');
assert.ok(provider.includes('SMC_Security::revoke_all_sessions'), 'base quarantined accounts are not contained');
assert.ok(providerV11.includes('wp_destroy_all_sessions'), 'v1.1 extra-field quarantine does not revoke sessions');
assert.ok(providerV11.includes("'ethical_conduct'"), 'ethical consent is not recorded separately');
assert.ok(providerV11.includes("'profile_photo'"), 'profile-photo completion is not represented');
assert.ok(providerV11.includes("'registration-city'"), 'city is not protected by a dedicated encryption purpose');

console.log('File 00 authentication-account contract v1.1 architecture passed.');

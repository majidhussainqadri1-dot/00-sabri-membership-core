import fs from 'node:fs';

const source = fs.readFileSync('source/sabri-membership-core/includes/class-smc-mfa-retirement.php', 'utf8');
const required = [
  'smc_submit_application',
  'smc_request_contact_otp',
  'smc_verify_contact_otp',
  'smc_revoke_session',
  'smc_revoke_all_sessions',
  'smc_resubmit',
  'smc_appeal',
  'smc_withdraw_guardian',
  'smc_verify_guardian',
];
for (const action of required) {
  if (!source.includes(`'${action}'`)) throw new Error(`missing retired-MFA recovery exemption: ${action}`);
}
if (!source.includes('in_array( $action, $recovery_actions, true )')) throw new Error('recovery actions are not exact-allowlist checked');
if (/0\s*===\s*strpos\(\s*\$action\s*,\s*['"]smc_['"]\s*\)/.test(source)) throw new Error('broad smc_* bypass detected');
if (!source.includes('public static function enforce_admin_state()')) throw new Error('admin-state gate missing');
console.log('PASS File 00 admin-post recovery routing regression');

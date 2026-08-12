import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = 'source/sabri-membership-core';
const bootstrap = fs.readFileSync(`${root}/sabri-membership-core.php`, 'utf8');
const retirement = fs.readFileSync(`${root}/includes/class-smc-mfa-retirement.php`, 'utf8');
const contracts = fs.readFileSync(`${root}/includes/class-smc-contracts.php`, 'utf8');
const schemaCompat = fs.readFileSync(`${root}/includes/class-smc-schema-compat.php`, 'utf8');
const hostCompat = fs.readFileSync(`${root}/includes/class-smc-host-compat.php`, 'utf8');
const workflow = fs.readFileSync(`${root}/includes/class-smc-workflow.php`, 'utf8');

const has = (source, needle, label = needle) => assert.ok(source.includes(needle), `missing MFA-retirement contract: ${label}`);
const lacks = (source, needle, label = needle) => assert.ok(!source.includes(needle), `retired MFA contract still present: ${label}`);

has(bootstrap, "Version: 1.2.43", 'plugin release 1.2.43');
has(bootstrap, "define( 'SMC_VERSION', '1.2.43' );", 'runtime release 1.2.43');
has(bootstrap, "define( 'SMC_CONTRACT_VERSION', '1.2.3' );", 'public contract 1.2.3');
has(bootstrap, "require_once SMC_PATH . 'includes/class-smc-mfa-retirement.php';", 'MFA retirement runtime loaded');
has(bootstrap, "array( 'SMC_MFA_Retirement', 'init' )", 'MFA retirement runtime initialized');
has(bootstrap, "require_once SMC_PATH . 'includes/class-smc-schema-compat.php';", 'live schema compatibility runtime loaded');
has(bootstrap, 'SMC_Schema_Compat::reconcile_verification_queue_index();', 'live-proven named-index preflight runs before migration');
has(bootstrap, 'SMC_Schema_Compat::assert_current_queue_indexes();', 'current critical indexes are read-back verified');
has(bootstrap, 'SMC_Schema_Compat::ORPHAN_BACKFILL_FAILURE', 'exact orphan backfill failure can be cleared only after DB promotion');
lacks(bootstrap, "class-smc-account-recovery.php", 'governed lost-factor recovery bootstrap');
lacks(bootstrap, "class-smc-account-recovery-lock.php", 'lost-factor recovery lock bootstrap');
assert.equal(fs.existsSync(`${root}/includes/class-smc-account-recovery.php`), false, 'lost-factor recovery source must be removed');
assert.equal(fs.existsSync(`${root}/includes/class-smc-account-recovery-lock.php`), false, 'lost-factor recovery lock source must be removed');

has(schemaCompat, "array( 'status', 'assigned_reviewer' )", 'exact live legacy queue signature is allowlisted');
has(schemaCompat, "array( 'status', 'queue_type', 'assigned_reviewer' )", 'current verification queue signature is explicit');
has(schemaCompat, 'DROP INDEX `queue`', 'only the known stale queue index is dropped before dbDelta');
has(schemaCompat, 'Unsupported verification queue index definition; automatic migration refused.', 'unknown queue index shapes fail closed');
has(schemaCompat, "array( 'status', 'next_attempt_at' )", 'file-job queue signature is verified without mutation');
has(schemaCompat, "array( 'request_id', 'decision' )", 'exact live legacy approval decision signature is allowlisted');
has(schemaCompat, "array( 'request_id', 'approval_generation', 'decision' )", 'current approval decision signature is explicit');
has(schemaCompat, 'DROP INDEX `decision`', 'only the known stale decision index is dropped before dbDelta');
has(schemaCompat, 'Unsupported approval decision index definition; automatic migration refused.', 'unknown decision index shapes fail closed');
has(schemaCompat, 'Current approval decision index could not be verified.', 'current decision index is read-back verified');
has(schemaCompat, "const ORPHAN_BACKFILL_FAILURE = 'Role-grant backfill failed.'", 'orphan compatibility is gated to exact live failure');
has(schemaCompat, "'legacy-users-to-1.2.0'", 'orphan compatibility requires completed live baseline');
has(schemaCompat, "'orphaned_application_missing_user'", 'missing principal gets explicit repair quarantine');
has(schemaCompat, "'orphaned_membership_application_quarantined'", 'orphan quarantine appends audit evidence');
has(schemaCompat, "SELECT id,user_id,repair_type,details", 'existing deterministic quarantine record is validated before reuse');
has(schemaCompat, "$audit_exists", 'quarantine checks for prior audit evidence');
has(schemaCompat, "if ( ! $audit_exists && ! SMC_Security::audit", 'missing quarantine audit evidence is retried after an interrupted insert');
has(schemaCompat, "SET status='suspended'", 'interrupted derivative orphan grants are quarantined');
has(schemaCompat, "if ( smc_privacy_erasure_lock( $user_id ) )", 'privacy erasure remains fail closed rather than orphan-skipped');
has(schemaCompat, "self::role_grant_checkpoint( 'running', $cursor )", 'orphan-safe backfill checkpoints per handled application');

for (const action of ['start_2fa', 'finish_2fa', 'challenge_2fa', 'rotate_recovery', 'ack_recovery_receipt']) {
  has(retirement, `remove_action( 'admin_post_smc_' . $action`, `runtime removal for ${action}`);
}
for (const action of ['start_2fa', 'finish_2fa', 'challenge_2fa', 'rotate_recovery', 'ack_recovery_receipt']) {
  lacks(hostCompat, `'${action}'`, `host fallback registry excludes retired ${action}`);
}
for (const handler of ['handle_start_2fa', 'handle_finish_2fa', 'handle_challenge_2fa', 'handle_rotate_recovery', 'handle_ack_recovery_receipt']) {
  lacks(workflow, `function ${handler}`, `dead retired handler removed: ${handler}`);
}
has(retirement, "remove_shortcode( 'smc_membership_recovery' );", 'recovery shortcode retired');
has(retirement, "File 00 no longer uses two-factor authentication", 'user-facing MFA retirement notice');
has(retirement, "'mfa_required'] = false", 'public contract declares MFA not required');
has(retirement, "'mfa_owner'] = 'none'", 'public contract has no MFA owner');
has(retirement, "file00_mfa_system_retired", 'retirement audit event');
has(retirement, "START TRANSACTION", 'legacy factor cleanup transaction');
has(retirement, "ROLLBACK", 'legacy factor cleanup rollback');
has(retirement, "DELETE FROM {$wpdb->usermeta}", 'obsolete MFA user-meta cleanup');
has(retirement, "DELETE FROM {$recovery_table}", 'obsolete recovery-code cleanup');
has(retirement, "DELETE FROM {$factor_table}", 'obsolete replay-state cleanup');
has(retirement, "SET two_factor_at=NULL,last_totp_slice=NULL", 'legacy session-factor state cleanup');
has(retirement, "post_status' => 'draft'", 'obsolete recovery page retired');

lacks(contracts, 'SMC_Security::two_factor_ready( $user_id )', 'membership eligibility must not depend on authenticator readiness');
lacks(contracts, 'SMC_Security::session_is_verified( $user_id )', 'membership contracts must not depend on File 00 MFA session');
lacks(contracts, "&& ! empty( $base['session_two_factor'] )", 'publishing/transfer must not depend on retired MFA assertion');
lacks(contracts, "! $a['session_two_factor']", 'authorization gates must not depend on retired MFA assertion');
has(contracts, "'mfa_required'           => false", 'canonical membership assertion says MFA is not required');
has(contracts, "'mfa_owner'              => 'none'", 'canonical membership assertion exposes no File 00 MFA owner');
has(contracts, "'can_message'            => $eligible", 'member actions are based on current eligibility without MFA');
has(contracts, "'can_book_appointment'   => $eligible", 'appointment assertion no longer requires MFA');
has(contracts, "'mfa_required'         => false", 'publishing/transfer subcontracts expose MFA retirement');

has(contracts, "if ( ! $user || smc_privacy_erasure_lock( $user_id ) )", 'missing user and privacy erasure remain role-sync failures');
has(contracts, "if ( user_can( $user, 'manage_options' ) ) {\n\t\t\treturn true;", 'Administrator protected role-sync no-op is successful');
lacks(contracts, "smc_privacy_erasure_lock( $user_id ) || user_can( $user, 'manage_options' )", 'Administrator protection is not conflated with role-sync failure');

console.log('mfa-retirement-contract: PASS');

lacks(workflow, 'Enable two-factor authentication in Membership Security.', 'status UI must not require retired File 00 MFA');
lacks(workflow, "'Two-factor security'", 'status UI must not present retired MFA as required');
has(workflow, 'File 00 MFA retired', 'status UI names File 02 authentication ownership');

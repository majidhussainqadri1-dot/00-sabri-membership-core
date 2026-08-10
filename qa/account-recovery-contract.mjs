import fs from 'node:fs';
import assert from 'node:assert/strict';

const recovery = fs.readFileSync('source/sabri-membership-core/includes/class-smc-account-recovery.php', 'utf8');
const bootstrap = fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php', 'utf8');

const has = (needle, label = needle) => assert.ok(recovery.includes(needle), `missing recovery contract: ${label}`);

assert.ok(
  bootstrap.includes("require_once SMC_PATH . 'includes/class-smc-account-recovery.php';"),
  'bootstrap must load governed recovery implementation'
);
assert.ok(
  bootstrap.includes("array( 'SMC_Account_Recovery', 'init' )"),
  'bootstrap must initialize governed recovery implementation'
);

has("const REPAIR_TYPE      = 'lost_factor_recovery';", 'durable recovery case type');
has("add_shortcode( 'smc_membership_recovery'", 'recovery UI shortcode');
has("admin_post_smc_account_recovery_request", 'request handler');
has("admin_post_smc_account_recovery_cancel", 'cancel handler');
has("admin_post_smc_account_recovery_complete", 'completion handler');
has("admin_post_smc_account_recovery_approve", 'independent approval handler');
has("admin_post_smc_account_recovery_reject", 'rejection handler');
assert.ok(!recovery.includes('admin_post_nopriv_smc_account_recovery'), 'recovery mutation must never be unauthenticated');

has('wp_check_password', 'File 02/current credential reauthentication');
has('account-recovery-request|', 'request rate limiting');
has('account-recovery-complete|', 'completion rate limiting');
has('SMC_Installer::audit_infrastructure_ready()', 'audit readiness fail-closed gate');
has('FOR UPDATE', 'database row serialization');
has('old_contact_hash', 'old-contact continuity binding');
has('contact_is_unchanged', 'contact-change fail-closed check');

has('$default_seconds = $privileged ? DAY_IN_SECONDS : HOUR_IN_SECONDS;', 'risk-tiered cooling period');
has('$default_approvals = $privileged ? 2 : 1;', 'dual approval for Founder/Administrator');
has("$actor_id === absint( $case['user_id'] )", 'self-approval prohibition');
has("$actor_id === absint( $approval['actor_id']", 'duplicate approver prohibition');
has("hash_equals( (string) ( $approval['reference_hash']", 'duplicate evidence prohibition');
has('SMC_Security::session_is_verified( $actor_id )', 'MFA verified approver session');
has("'reference_hash'   => (string) $reference_hash", 'hashed evidence reference storage');
assert.ok(!recovery.includes("'evidence_reference' => $reference"), 'raw evidence reference must not be persisted');

const revokeIndex = recovery.indexOf("SMC_Security::revoke_all_sessions( $user_id, 'lost_factor_recovery' )");
const resetIndex = recovery.indexOf('self::clear_old_factor_state( $user_id )');
assert.ok(revokeIndex > 0 && resetIndex > revokeIndex, 'all sessions must be revoked before factor reset');

has('DELETE FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d', 'old recovery codes invalidated');
has('DELETE FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d', 'global TOTP replay state invalidated');
has("'_smc_totp_secret_enc'", 'old TOTP secret removed');
has("'_smc_2fa_enabled'", 'old factor enabled flag removed');
has("'_smc_totp_pending_enc'", 'new factor enrollment staged, not bypassed');
has("'_smc_revalidation_required_at'", 'fresh revalidation requirement');
has('wp_clear_auth_cookie()', 'browser session cleared');
has("wp_login_url( smc_page_url( 'security'", 'fresh login then authenticator enrollment');

has('account_recovery_requested', 'immutable request audit');
has('account_recovery_approval_recorded', 'immutable approval audit');
has('account_recovery_rejected', 'immutable rejection audit');
has('account_recovery_cancelled', 'immutable cancellation audit');
has('account_recovery_factor_reset_completed', 'immutable completion audit');
has('AccountRecoveryRequested', 'durable notification/integration event');
has('AccountRecoveryApproved', 'approval integration event');
has('AccountRecoveryFactorResetCompleted', 'completion integration event');
has('AccountRecoveryRejected', 'rejection integration event');
has('AccountRecoveryCancelled', 'cancellation integration event');

assert.ok(!/\bwp_mail\s*\(/.test(recovery), 'File 00 must not bypass File 19 by sending mail directly');
assert.ok(!/\bmail\s*\(/.test(recovery), 'File 00 must not call PHP mail directly');

console.log('account-recovery-contract: PASS');

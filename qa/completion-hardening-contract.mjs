import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', 'source', 'sabri-membership-core');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const admin = read('includes/class-smc-admin.php');
const privacy = read('includes/class-smc-privacy.php');
const functions = read('includes/functions.php');
const contracts = read('includes/class-smc-contracts.php');
const workflow = read('includes/class-smc-workflow.php');
const security = read('includes/class-smc-security.php');
const mfaRetirement = read('includes/class-smc-mfa-retirement.php');
const events = read('includes/class-smc-events.php');
const main = read('sabri-membership-core.php');
const readme = read('README.txt');
const failures = [];
let passed = 0;
function check(c, n) { if (c) passed++; else failures.push(n); }

check(main.includes('Version: 1.2.40'), 'plugin header 1.2.40');
check(main.includes("define( 'SMC_VERSION', '1.2.40' )"), 'runtime version 1.2.40');
check(main.includes("define( 'SMC_DB_VERSION', '1.4.5' )"), 'schema is 1.4.5');
check(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.3' )"), 'contract is 1.2.3 after Founder-approved MFA retirement');
check(readme.includes('Stable tag: 1.2.40'), 'readme stable tag');

check(admin.includes('private static function approval_gate'), 'approval gate helper exists');
check(admin.includes("'pending_senior'"), 'senior pending state exists');
check(admin.includes('approval_generation=%s AND decision=\'approve\'') && admin.includes('approval_snapshot_hash=%s'), 'vote count is bound to an immutable evidence-snapshot generation');
check(admin.includes("'applicant_version' => (int) $request['applicant_version']"), 'snapshot uses submitted applicant generation');
check(admin.includes("'version' => (int) $document['version']"), 'snapshot includes document version');
check(admin.includes("'sha256'  => (string) $document['plain_sha256']"), 'snapshot includes document hash');
check(admin.includes("ON DUPLICATE KEY UPDATE decision='approve'"), 're-vote refreshes current snapshot');
check(!admin.includes('A senior reviewer must finalize a professional membership after two independent votes.'), 'second non-senior vote is not rolled back');
check(admin.includes("$a['email_verified'] ?"), 'review UI uses canonical email assertion');
check(admin.includes("$a['phone_verified'] ?"), 'review UI uses canonical phone assertion');

check(functions.includes('function smc_privacy_erasure_lock'), 'persistent erasure lock helper exists');
check(functions.includes("'status'                => 'erasure_pending'"), 'erasure lock maps to hard block');
check(contracts.includes('smc_privacy_erasure_lock( $user_id ) || smc_application'), 'registration cannot resurrect erased membership');
check(contracts.includes('if ( ! $user || smc_privacy_erasure_lock( $user_id )'), 'role mutation denied while erased');
check(privacy.includes('private static function lock_for_erasure'), 'erasure locks before deletion');
check(privacy.includes("SMC_Security::revoke_all_sessions( $user_id, 'privacy_erasure_locked' )"), 'erasure revokes sessions');
check(privacy.includes("$wpdb->query( 'START TRANSACTION' )"), 'record deletion starts transaction');
check(/\$wpdb->query\(\s*'ROLLBACK'\s*\)/.test(privacy), 'record deletion rollback exists');
check(privacy.includes("$wpdb->query( 'COMMIT' )"), 'record deletion commit exists');
check(!privacy.includes("$wpdb->delete( $wpdb->prefix . 'smc_audit_log'"), 'audit chain rows are not deleted');
check(privacy.includes('unchanged tamper-evident security audit evidence'), 'retained audit evidence disclosed');

check(workflow.includes('SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE'), 'resubmission locks the current application generation');
check(workflow.includes('applicant_version=%d,row_version=row_version+1'), 'resubmission advances verification applicant generation');
check(workflow.includes("'applicant_version' => $next_applicant_version"), 'resubmission audit records the new applicant generation');
check(privacy.includes("'pending'        => true"), 'private-storage failure keeps erasure retryable');
check(privacy.includes('Erasure completed, but completion audit evidence requires retry.') && privacy.includes("'done'=>false"), 'audit-evidence failure keeps erasure incomplete');

/*
 * Founder change-control dated 10 August 2026 retired File 00 MFA. Completion
 * hardening must therefore prove that obsolete recovery/challenge behavior is
 * absent from the active workflow, rather than requiring dormant MFA helpers
 * to survive merely to satisfy historical tests. Historical audit/crypto
 * compatibility remains governed elsewhere by migration-specific regressions.
 */
check(main.includes("includes/class-smc-mfa-retirement.php"), 'Founder-approved MFA retirement runtime is loaded');
check(mfaRetirement.includes("remove_mfa_runtime_hooks"), 'retirement runtime removes legacy MFA action hooks');
check(mfaRetirement.includes("remove_shortcode( 'smc_membership_recovery' )"), 'retirement runtime removes the legacy recovery surface');
check(mfaRetirement.includes("$merged['mfa_required'] = false") && mfaRetirement.includes("$merged['mfa_owner'] = 'none'"), 'public assertions cannot claim File 00 MFA');
check(!workflow.includes("SMC_Security::decrypt( $receipt['envelope'], 'recovery-receipt'"), 'retired v2 recovery receipt workflow is absent');
check(!workflow.includes('verify_two_factor_challenge'), 'retired two-factor challenge is absent from the active workflow');
check(workflow.includes('$subject_hash = SMC_Security::subject_hash( $user_id );') && !workflow.includes("blind_index( (string) absint( $user_id ), 'audit-subject'"), 'security-event history queries the canonical audit subject hash');

check(!events.includes("'age', 'type'"), 'cross-file event payload does not allow exact age');
check(events.includes("'age_band', 'type'"), 'cross-file event payload allows privacy-minimal age_band');

if (failures.length) {
  console.error(`completion hardening contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const f of failures) console.error(`- ${f}`);
  process.exit(1);
}
console.log(`completion hardening contract: ${passed} PASS, 0 FAIL`);

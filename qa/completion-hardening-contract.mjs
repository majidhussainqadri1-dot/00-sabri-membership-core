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
const main = read('sabri-membership-core.php');
const readme = read('README.txt');
const failures = [];
let passed = 0;
function check(c, n) { if (c) passed++; else failures.push(n); }

check(main.includes('Version: 1.2.39'), 'plugin header 1.2.39');
check(main.includes("define( 'SMC_VERSION', '1.2.39' )"), 'runtime version 1.2.39');
check(main.includes("define( 'SMC_DB_VERSION', '1.4.5' )"), 'schema is 1.4.5');
check(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.2' )"), 'contract is 1.2.2 after Founder-approved MFA retirement');
check(readme.includes('Stable tag: 1.2.39'), 'readme stable tag');

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

/* Historical MFA helpers remain covered as dormant migration/regression code; v1.2.39 removes their active routes and public requirement. */
const decryptPosition = workflow.indexOf("SMC_Security::decrypt( $receipt['envelope'], 'recovery-receipt'");
const deletePosition = workflow.indexOf("self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' )", decryptPosition);
check(decryptPosition >= 0 && deletePosition > decryptPosition, 'historical v2 recovery receipt code remains internally safe while dormant');
check(security.includes("if ($old_enabled) {update_user_meta($user_id,'_smc_2fa_enabled',$old_enabled);} else {delete_user_meta($user_id,'_smc_2fa_enabled');}"), 'historical incomplete 2FA helper restores the prior enabled flag');
check(security.includes("if ( $old_secret ) { update_user_meta($user_id,'_smc_totp_secret_enc',$old_secret); } else { delete_user_meta($user_id,'_smc_totp_secret_enc'); }"), 'historical incomplete 2FA helper restores or removes the encrypted TOTP secret');
check(security.includes("revoke_all_sessions( $user_id, 'two_factor_changed' )") && security.indexOf("$wpdb->query( 'COMMIT' )") < security.indexOf("revoke_all_sessions( $user_id, 'two_factor_changed' )"), 'historical committed 2FA helper revokes sessions after atomic write');
check(workflow.includes("$challenge = $user ? SMC_Security::verify_two_factor_challenge") && workflow.includes('true !== $challenge'), 'historical 2FA challenge helper checks result exactly while its active handler is retired');
check(workflow.includes('$subject_hash = SMC_Security::subject_hash( $user_id );') && !workflow.includes("blind_index( (string) absint( $user_id ), 'audit-subject'"), 'security-event history queries the canonical audit subject hash');

if (failures.length) {
  console.error(`completion hardening contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const f of failures) console.error(`- ${f}`);
  process.exit(1);
}
console.log(`completion hardening contract: ${passed} PASS, 0 FAIL`);

if (events.includes("'age', 'type'")) throw new Error('cross-file event payload must not allow exact age');
if (!events.includes("'age_band', 'type'")) throw new Error('cross-file event payload must allow privacy-minimal age_band');

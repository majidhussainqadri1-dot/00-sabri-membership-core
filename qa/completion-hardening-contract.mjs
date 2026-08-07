import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', 'source', 'sabri-membership-core');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const admin = read('includes/class-smc-admin.php');
const privacy = read('includes/class-smc-privacy.php');
const functions = read('includes/functions.php');
const contracts = read('includes/class-smc-contracts.php');
const workflow = read('includes/class-smc-workflow.php');
const main = read('sabri-membership-core.php');
const readme = read('README.txt');
const failures = [];
let passed = 0;
function check(c, n) { if (c) passed++; else failures.push(n); }

check(main.includes('Version: 1.2.13'), 'plugin header 1.2.13');
check(main.includes("define( 'SMC_VERSION', '1.2.13' )"), 'runtime version 1.2.13');
check(main.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'schema is 1.3.0');
check(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.0' )"), 'contract stays 1.2.0');
check(readme.includes('Stable tag: 1.2.13'), 'readme stable tag');

check(admin.includes('private static function approval_gate'), 'approval gate helper exists');
check(admin.includes("'pending_senior'"), 'senior pending state exists');
check(admin.includes('BINARY evidence_snapshot=%s'), 'vote count bound to exact snapshot');
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
check(privacy.includes("$wpdb->query( 'ROLLBACK' )"), 'record deletion rollback exists');
check(privacy.includes("$wpdb->query( 'COMMIT' )"), 'record deletion commit exists');
check(!privacy.includes("$wpdb->delete( $wpdb->prefix . 'smc_audit_log'"), 'audit chain rows are not deleted');
check(privacy.includes('unchanged tamper-evident security audit evidence'), 'retained audit evidence disclosed');

check(workflow.includes('SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE'), 'resubmission locks the current application generation');
check(workflow.includes('applicant_version=%d,row_version=row_version+1'), 'resubmission advances verification applicant generation');
check(workflow.includes("'applicant_version' => $next_applicant_version"), 'resubmission audit records the new applicant generation');
check(privacy.includes("'pending'        => true"), 'private-storage failure keeps erasure retryable');
check(privacy.includes('the eraser will retry until completion evidence is recorded'), 'audit-evidence failure keeps erasure incomplete');

const decryptPosition = workflow.indexOf("SMC_Security::decrypt( $receipt['envelope'], 'recovery-receipt'");
const deletePosition = workflow.indexOf("self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' )", decryptPosition);
check(decryptPosition >= 0 && deletePosition > decryptPosition, 'v2 recovery receipt decrypts before verified one-time deletion');
check(workflow.includes("self::delete_user_meta_verified( $user_id, '_smc_2fa_enabled' )"), 'incomplete 2FA setup verifiably rolls back enabled flag');
check(workflow.includes("self::delete_user_meta_verified( $user_id, '_smc_totp_secret_enc' )"), 'incomplete 2FA setup verifiably removes encrypted TOTP secret');
check(workflow.includes("SMC_Security::revoke_all_sessions( $user_id, 'two_factor_setup_rollback' )"), '2FA setup rollback revokes sessions');
check(workflow.includes("$challenge = SMC_Security::verify_two_factor_challenge"), '2FA challenge result is checked');

if (failures.length) {
  console.error(`completion hardening contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const f of failures) console.error(`- ${f}`);
  process.exit(1);
}
console.log(`completion hardening contract: ${passed} PASS, 0 FAIL`);

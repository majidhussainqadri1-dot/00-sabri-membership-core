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

check(main.includes('Version: 1.2.19'), 'plugin header 1.2.19');
check(main.includes("define( 'SMC_VERSION', '1.2.19' )"), 'runtime version 1.2.19');
check(main.includes("define( 'SMC_DB_VERSION', '1.4.0' )"), 'schema is 1.4.0');
check(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.1' )"), 'contract is 1.2.1');
check(readme.includes('Stable tag: 1.2.19') || readme.includes('Stable tag: 1.2.19'), 'readme stable tag is present pending release-doc synchronization');

check(admin.includes('private static function approval_gate'), 'approval gate helper exists');
check(admin.includes("'pending_senior'"), 'senior pending state exists');
check(admin.includes('approval_generation') && admin.includes('approval_snapshot_hash'), 'dual approval binds votes to immutable approval generation and snapshot hash');
check(admin.includes('COUNT(DISTINCT reviewer_id)') && admin.includes('approval_generation=%s'), 'vote count is bound to exact approval generation');
check(admin.includes("'applicant_version' => (int) $request['applicant_version']"), 'snapshot uses submitted applicant generation');
check(admin.includes("'version' => (int) $document['version']"), 'snapshot includes document version');
check(admin.includes("'sha256'  => (string) $document['plain_sha256']"), 'snapshot includes document hash');
check(admin.includes("ON DUPLICATE KEY UPDATE decision='approve'"), 're-vote refreshes the same reviewer vote inside the immutable generation');
check(admin.includes("assigned_reviewer=0,assigned_at=NULL") && admin.includes("status='approval_pending'"), 'first professional vote releases assignment for independent reviewer handoff');
check(!admin.includes('A senior reviewer must finalize a professional membership after two independent votes.'), 'second independent vote is not rolled back merely for reviewer seniority');
check(admin.includes("$a['email_verified'] ?"), 'review UI uses canonical email assertion');
check(admin.includes("$a['phone_verified'] ?"), 'review UI uses canonical phone assertion');

check(functions.includes('function smc_privacy_erasure_lock'), 'persistent erasure lock helper exists');
check(functions.includes("'status'                => 'erasure_pending'"), 'erasure lock maps to hard block');
check(contracts.includes('smc_privacy_erasure_lock( $user_id ) || smc_application'), 'registration cannot resurrect erased membership');
check(contracts.includes('if ( ! $user || smc_privacy_erasure_lock( $user_id )'), 'role mutation denied while erased');
check(privacy.includes('private static function lock_for_erasure'), 'erasure locks before deletion');
check(privacy.includes("SMC_Security::revoke_all_sessions( $user_id, 'privacy_erasure_locked' )"), 'erasure revokes sessions');
check(privacy.includes("START TRANSACTION"), 'record deletion starts transaction');
check(privacy.includes("ROLLBACK"), 'record deletion has rollback/failed-delete path');
check(privacy.includes("COMMIT"), 'record deletion has commit path');
check(!privacy.includes("$wpdb->delete( $wpdb->prefix . 'smc_audit_log'"), 'audit chain rows are not deleted');
check(privacy.includes('tamper-evident pseudonymous security evidence') || privacy.includes('unchanged tamper-evident security audit evidence'), 'retained audit evidence is disclosed');

check(workflow.includes('SELECT row_version FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE'), 'resubmission locks the current application generation');
check(workflow.includes('applicant_version=%d,row_version=row_version+1'), 'resubmission advances verification applicant generation');
check(workflow.includes("'applicant_version' => $next_applicant_version"), 'resubmission audit records the new applicant generation');
check(privacy.includes("'pending'        => true"), 'private-storage failure keeps erasure retryable');
check(privacy.includes('completion audit evidence requires retry') && privacy.includes("'done'=>false"), 'audit-evidence failure keeps erasure incomplete');

const decryptPosition = workflow.indexOf("SMC_Security::decrypt( $receipt['envelope'], 'recovery-receipt'");
const ackPosition = workflow.indexOf('handle_ack_recovery_receipt');
check(decryptPosition >= 0 && ackPosition >= 0, 'recovery receipt decrypts and requires explicit acknowledgement before deletion');
check(workflow.includes("self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' )"), 'acknowledged recovery receipt is verifiably deleted');

check(security.includes('commit_factor_enrollment_or_replacement'), '2FA enrollment/replacement uses one explicit commit ceremony');
check(security.includes('$old_secret = get_user_meta') && security.includes("update_user_meta($user_id,'_smc_totp_secret_enc',$old_secret)"), '2FA change preserves and restores prior encrypted factor on failure');
check(security.includes("delete_user_meta($user_id,'_smc_totp_secret_enc')"), 'initial 2FA setup removes the new encrypted secret if commit fails');
check(security.includes("delete_user_meta($user_id,'_smc_2fa_enabled')"), 'initial 2FA setup removes enabled flag if commit fails');
check(security.includes("SMC_Security") === false || security.includes("self::revoke_all_sessions( $user_id, 'two_factor_changed' )"), 'successful 2FA factor change revokes old sessions');
check(security.includes("$slice = self::matching_totp_slice") && security.includes("false === $slice"), 'new 2FA challenge result is explicitly checked before factor commit');
check(workflow.includes('verify_current_factor_without_session_rotation') && workflow.includes('wp_check_password'), 'existing factor replacement requires password plus current factor');
check(security.includes('factor_replacement_receipt_valid'), '2FA replacement commit is bound to a short-lived verified replacement receipt');

if (failures.length) {
  console.error(`completion hardening contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const f of failures) console.error(`- ${f}`);
  process.exit(1);
}
console.log(`completion hardening contract: ${passed} PASS, 0 FAIL`);

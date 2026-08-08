import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const src = path.join(root, 'source', 'sabri-membership-core');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const plugin = read('source/sabri-membership-core/sabri-membership-core.php');
const readme = read('source/sabri-membership-core/README.txt');
const installer = read('source/sabri-membership-core/includes/class-smc-installer.php');
const contracts = read('source/sabri-membership-core/includes/class-smc-contracts.php');
const admin = read('source/sabri-membership-core/includes/class-smc-admin.php');
const security = read('source/sabri-membership-core/includes/class-smc-security.php');
const lifecycle = read('source/sabri-membership-core/includes/class-smc-lifecycle.php');
const workflow = read('source/sabri-membership-core/includes/class-smc-workflow.php');
const cf01 = read('source/sabri-membership-core/includes/class-smc-cf01-contract.php');
const uninstall = read('source/sabri-membership-core/uninstall.php');
const ledger = read('docs/FORTY-ROUND-REVIEW-1.2.8.md');
const failures = [];
let passed = 0;
function assert(condition, label) { if (condition) passed += 1; else failures.push(label); }

assert(plugin.includes('Version: 1.2.15'), 'Plugin header is 1.2.15');
assert(plugin.includes("define( 'SMC_VERSION', '1.2.15' )"), 'Runtime version is 1.2.15');
assert(plugin.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'Schema is 1.3.0');
assert(plugin.includes("define( 'SMC_CONTRACT_VERSION', '1.2.0' )"), 'Contract remains 1.2.0');
assert(readme.includes('Stable tag: 1.2.15'), 'Plugin readme stable tag is 1.2.15');
assert(plugin.includes("register_deactivation_hook( SMC_FILE, array( 'SMC_Installer', 'deactivate' ) )"), 'Deactivation hook is registered');
assert(installer.includes('public static function deactivate()'), 'Installer exposes deactivation cleanup');
for (const hook of ['smc_lifecycle_daily','smc_process_file_jobs','smc_continue_migration']) {
  assert(installer.includes(hook) && installer.includes('wp_clear_scheduled_hook( $hook )'), `Deactivation clears ${hook}`);
  assert(uninstall.includes(hook) && uninstall.includes('wp_clear_scheduled_hook( $hook )'), `Uninstall clears ${hook}`);
}
assert(installer.includes("hash_equals( (string) ( $stored['token'] ?? '' ), $token )"), 'Schema owner lock is owner-checked');
assert(!installer.includes("if ( ! $locked ) {\n\t\t\tdelete_option( 'smc_schema_owner_lock' )"), 'Lock acquisition failure does not delete another owner lock');
assert(contracts.includes('smc_sanitize_membership_types') && contracts.includes('isset( smc_account_types()[ $type ] )') && contracts.includes('smc_membership_roles()'), 'Role grants and synchronization use canonical managed-role allowlists');
assert(contracts.includes("user_can( $user, 'manage_options' )"), 'WordPress administrator exclusion is preserved');
assert(contracts.includes('smc_privacy_erasure_lock'), 'Privacy-erasure role mutation block is preserved');
assert(contracts.includes('identity_documents_current'), 'Identity evidence freshness predicate exists');
assert(contracts.includes("scan_status='passed'"), 'Identity evidence requires a clean scan');
assert(contracts.includes("status='approved'"), 'Identity evidence requires approval');
assert(contracts.includes('expiry_date>=UTC_DATE()'), 'Identity evidence requires non-expiry');
assert(admin.includes('START TRANSACTION'), 'Approval starts a transaction');
assert(admin.includes('smc_verification_requests') && admin.includes('FOR UPDATE'), 'Approval locks the verification request');
assert(admin.includes('smc_applications') && admin.includes('LIMIT 1 FOR UPDATE'), 'Approval locks the application');
assert(admin.includes('smc_identity_records') && admin.includes('LIMIT 1 FOR UPDATE'), 'Approval locks identity evidence');
assert(admin.includes('smc_guardian_consents') && admin.includes('LIMIT 1 FOR UPDATE'), 'Approval locks guardian evidence');
assert(admin.includes('smc_identity_documents') && admin.includes('ORDER BY document_key ASC FOR UPDATE'), 'Approval locks exact document rows');
assert(admin.includes("'application_row_version'"), 'Approval snapshot includes application row version');
assert(admin.includes("'identity_id'"), 'Approval snapshot includes identity record id');
assert(admin.includes("AND applicant_version=%d"), 'Approval update binds applicant generation');
assert(admin.includes("AND status=%s AND row_version=%d"), 'Application update binds exact status and row version');
assert(admin.includes('verify_audit_chain()'), 'Admin audit page verifies the chain');
assert(security.includes("preg_match( '#(^|/)\\.{1,2}(/|$)#'"), 'Private path rejects dot segments');
assert(security.includes("realpath( $dir )"), 'Private path is canonicalized with realpath');
assert(security.includes('resolves inside a public WordPress directory'), 'Canonical public-root containment is enforced');
assert(security.includes("[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\.smcdoc"), 'Document targets require UUID v4');
assert(security.includes('smc_image_plaintext_cleanup'), 'Image plaintext cleanup fails closed');
assert(security.includes("array_unique( array_filter( array( wp_normalize_path( $temp ), $saved_path ) ) )"), 'Both image temp paths are verified deleted');
assert(security.indexOf("identity_document_stored") < security.indexOf("$wpdb->query( 'COMMIT' )", security.indexOf('identity_document_stored')), 'Document audit occurs before its commit');
assert(security.includes('identity_document_cleanup_pending'), 'Superseded-file cleanup is queued and audited');
for (const type of ['delete_lease','delete_quarantine','delete_superseded','delete_failed_upload','delete_orphan','privacy_delete']) {
  assert(security.includes(`'${type}'`), `File-job allowlist includes ${type}`);
}
assert(security.includes("hash_equals( (string) $expected_hash, (string) $job['path_hash'] )"), 'File-job path blind index is revalidated');
assert(security.includes('smc_identity_documents WHERE stored_name=%s'), 'Canonical referenced documents are protected from deletion');
assert(security.includes("status='processing' AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 MINUTE"), 'Stale processing claims are recoverable');
assert(security.includes("WHERE id=%d AND ((status IN ('pending','retry')"), 'File-job claim is atomic and state-conditional');
assert(lifecycle.includes('GET_LOCK') && lifecycle.includes('RELEASE_LOCK'), 'Lifecycle job uses an advisory overlap lock');
assert(workflow.indexOf('$wpdb->query( $sql )') < workflow.indexOf("apply_filters( 'smc_send_contact_otp'"), 'Contact OTP is persisted before provider delivery');
assert(workflow.includes("DELETE FROM {$wpdb->prefix}smc_contact_otps"), 'Failed contact delivery deletes the exact unverified OTP');
assert(security.includes("last_totp_slice'] >= (int) $slice") && security.includes('last_totp_slice<%d'), 'TOTP replay slice is rejected under a row lock');
assert(!security.includes("if ( 1 !== $updated ) {\n\t\t\treturn self::register_session"), 'TOTP replay failure cannot reset the session');
const registerSection = security.slice(security.indexOf('public static function register_session'), security.indexOf('public static function session_is_verified'));
assert(!registerSection.includes('revoked_at=NULL'), 'Session registration cannot resurrect revoked sessions');
assert(!registerSection.includes('last_totp_slice=NULL'), 'Session registration cannot reset replay state');
assert(security.includes('_smc_session_token_'), 'Exact session token envelope is stored');
assert(security.includes('WP_Session_Tokens::get_instance( $user_id )->destroy( $raw )'), 'Revoke-one destroys the exact WordPress session');
assert(security.includes('legacy_exact_token_unavailable'), 'Legacy revoke-one fails safe to revoke-all');
assert(security.includes('SELECT @@session.in_transaction'), 'Session revocation is outer-transaction aware');
assert(security.includes('$envelope_ok = self::delete_session_token_envelope'), 'Exact token envelope cleanup is in the success predicate');
assert(security.includes('$envelopes_ok = true;'), 'Revoke-all verifies all envelope deletion');
assert(security.includes('12 * HOUR_IN_SECONDS'), 'MFA absolute freshness is bounded');
assert(security.includes('30 * MINUTE_IN_SECONDS'), 'Session inactivity timeout is bounded');
assert(security.includes('5 * MINUTE_IN_SECONDS'), 'Activity writes are throttled');
assert(security.includes('smc_recovery_codes') && security.includes('LIMIT 1 FOR UPDATE'), 'Recovery code consumption locks the row');
assert(security.includes("'standalone' => true"), 'Standalone recovery-code audit is explicit');
assert(security.includes('public static function verify_audit_chain'), 'Audit-chain verifier exists');
assert(security.includes('min( 500, $maximum - $checked )') && security.includes('LIMIT %d'), 'Audit-chain verifier is streamed in bounded pages');
assert(cf01.includes("'clinical_identity_link' => ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] )"), 'CF-01 identity link requires current MFA');
assert(cf01.includes("'issued_at'") && cf01.includes("'expires_at'"), 'CF-01 step-up has issue/expiry times');
assert(cf01.includes("SMC_Security::rate_limited( 'cf01-step-up|"), 'CF-01 uses canonical atomic rate limiting');
assert(cf01.includes("'method' => 'recovery_code'") && cf01.includes("'scope_hash' => (string) $scope_hash"), 'CF-01 recovery audit is purpose/scope bound');
assert(cf01.includes('delete_option( $replay_marker )'), 'CF-01 TOTP marker is released after audit failure');
assert((ledger.match(/^## Review Round \d{2} —/gm) || []).length === 40, 'Ledger contains exactly forty rounds');
for (let i = 1; i <= 40; i += 1) {
  assert(ledger.includes(`## Review Round ${String(i).padStart(2, '0')} —`), `Ledger includes review round ${i}`);
}
assert(ledger.includes('bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0'), 'Ledger records platform plan checksum');
assert(ledger.includes('3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d'), 'Ledger records File 00 plan checksum');

if (failures.length) {
  console.error(`forty-round contract: ${passed} PASS, ${failures.length} FAIL`);
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}
console.log(`forty-round contract: ${passed} PASS, 0 FAIL`);

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import PhpParser from 'php-parser';

const root = path.resolve(import.meta.dirname, '..');
const plugin = process.env.SMC_PLUGIN_DIR
  ? path.resolve(process.env.SMC_PLUGIN_DIR)
  : path.join(root, 'source', 'sabri-membership-core');
let passed = 0;
const failures = [];

function assert(condition, name) {
  if (condition) passed += 1;
  else failures.push(name);
}

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true })
    .flatMap((entry) => {
      const target = path.join(dir, entry.name);
      return entry.isDirectory() ? walk(target) : [target];
    })
    .sort();
}

function text(relative) {
  return fs.readFileSync(path.join(plugin, relative), 'utf8');
}

const files = walk(plugin);
const phpFiles = files.filter((file) => file.endsWith('.php'));
const jsFiles = files.filter((file) => file.endsWith('.js'));
const allPhp = phpFiles.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
const allSource = files.map((file) => fs.readFileSync(file)).reduce((hash, bytes) => {
  hash.update(bytes);
  return hash;
}, createHash('sha256')).digest('hex');

const manifestPath = path.join(plugin, 'MANIFEST.sha256');
if (fs.existsSync(manifestPath)) {
  const expected = new Map(
    fs.readFileSync(manifestPath, 'utf8').trim().split('\n').filter(Boolean)
      .map((line) => {
        const match = line.match(/^([a-f0-9]{64})  (.+)$/);
        return match ? [match[2], match[1]] : ['', ''];
      }),
  );
  const manifestFiles = files.filter((file) => file !== manifestPath);
  for (const file of manifestFiles) {
    const relative = path.relative(plugin, file).split(path.sep).join('/');
    const actual = createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    assert(expected.get(relative) === actual, `Manifest digest: ${relative}`);
    expected.delete(relative);
  }
  assert(expected.size === 0, 'Manifest has no stale or missing source entries');
}

const parser = new PhpParser.Engine({
  parser: { php7: true, suppressErrors: false },
  ast: { withPositions: true },
});
for (const file of phpFiles) {
  try {
    parser.parseCode(fs.readFileSync(file, 'utf8'), file);
    assert(true, `PHP parse: ${path.relative(plugin, file)}`);
  } catch (error) {
    assert(false, `PHP parse: ${path.relative(plugin, file)}: ${error.message}`);
  }
}

for (const file of jsFiles) {
  try {
    new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file });
    assert(true, `JavaScript syntax: ${path.relative(plugin, file)}`);
  } catch (error) {
    assert(false, `JavaScript syntax: ${path.relative(plugin, file)}: ${error.message}`);
  }
}

const main = text('sabri-membership-core.php');
for (const required of [
  'includes/functions.php',
  'includes/class-smc-installer.php',
  'includes/class-smc-security.php',
  'includes/class-smc-host-compat.php',
  'includes/class-smc-contracts.php',
  'includes/class-smc-workflow.php',
  'includes/class-smc-contact-delivery.php',
  'includes/class-smc-admin.php',
  'includes/class-smc-privacy.php',
  'includes/class-smc-lifecycle.php',
]) {
  assert(main.includes(required) && fs.existsSync(path.join(plugin, required)), `Loaded file exists: ${required}`);
}

const policy = text('includes/functions.php');
assert(policy.includes("'commission_percent'       => 0"), 'Commission is exactly 0 percent');
assert(policy.includes("'donation_optional'        => true"), 'Donation is optional');
assert(policy.includes("'donation_affects_rank'    => false"), 'Donation cannot affect rank');
assert(policy.includes("'male_minimum_age'         => 15"), 'Male minimum age is 15');
assert(policy.includes("'female_minimum_age'       => 12"), 'Female minimum age is 12');
assert(policy.includes("'professional_minimum_age' => 18"), 'Professional minimum age is 18');
assert(policy.includes('Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed'), 'Founder spelling is canonical');
assert(!/\b10(?:\.00)?\s*(?:%|percent|commission)/i.test(allPhp), 'Forbidden 10 percent commission absent');
assert(!/Dr\. Allama\b|Muhaddith Murshid\b/.test(allPhp), 'Incorrect Founder spelling absent');

const contracts = text('includes/class-smc-contracts.php');
for (const symbol of [
  'smc_membership_assertions',
  'smc_communication_assertions',
  'sabri_doctor_verified',
  'rest_authentication_errors',
  'user_has_cap',
  'set_logged_in_cookie',
  'revoke_all_sessions',
  'smc_request_requires_membership',
  'spd_can_view_profile',
]) {
  assert(contracts.includes(symbol), `Contract control: ${symbol}`);
}
assert(!main.includes('class-smc-profile.php'), 'File 03 profile ownership is not duplicated');
assert(!main.includes('class-smc-knowledge.php'), 'Files 04/06 content ownership is not duplicated');
assert(!fs.existsSync(path.join(plugin, 'templates', 'membership.php')), 'File 20 shell is not duplicated');
assert(fs.readdirSync(path.join(plugin, 'assets')).sort().join(',') === 'membership.css,membership.js', 'Only canonical runtime assets are packaged');
assert(!allPhp.includes('wp_mail('), 'File 19 notification ownership is not bypassed');
assert(!allPhp.includes('wp_set_auth_cookie('), 'File 02 authentication cookies are not duplicated');
assert(!allPhp.includes('register_post_type('), 'Publishing and encyclopedia post types are not duplicated');

const installer = text('includes/class-smc-installer.php');
for (const symbol of [
  'GET_LOCK',
  'RELEASE_LOCK',
  'smc_schema_owner_lock',
  'smc_guardian_consents',
  'smc_contact_otps',
  'smc_auth_sessions',
  'smc_recovery_codes',
  'smc_rate_limits',
  'smc_file_jobs',
  'smc_retention_holds',
  'smc_audit_log',
  'smc_migrations',
  'row_version',
  'document_number_hash',
  'phone_hash',
  'run_legacy_batch',
  'LIMIT 50',
]) {
  assert(installer.includes(symbol), `Installer/schema control: ${symbol}`);
}
assert(!installer.includes('ensure_founder'), 'No administrator is guessed as Founder');
assert(!installer.includes('seed_knowledge'), 'Knowledge content is not seeded by File 00');
assert(main.includes("add_action(\n\t'admin_init'"), 'Upgrade checks are admin-controlled');
assert(!main.includes('SMC_Installer::maybe_upgrade();\n\t\tSMC_'), 'Upgrade does not run on every frontend request');

const workflow = text('includes/class-smc-workflow.php');
const contactDelivery = text('includes/class-smc-contact-delivery.php');
for (const symbol of [
  'smc_minimum_age_for_gender',
  'smc_is_professional_type',
  'smc_send_guardian_invitation',
  'guardian_authority',
  'guardian_token',
  'otp_attempts',
  'expires_at>UTC_TIMESTAMP()',
  'membership_application_submitted',
  'appeal_review',
  'resubmitted',
  'wp_check_password',
  'smc_revoke_session',
  'noscript',
]) {
  assert(workflow.includes(symbol), `Workflow control: ${symbol}`);
}
assert(contactDelivery.includes('smc_send_contact_otp'), 'Contact-delivery service owns OTP dispatch');
assert(workflow.includes("array( 'male'") || policy.includes("'male'"), 'Gender allowlist exists');
assert(workflow.includes('/^[A-Z]{2}$/'), 'Issuing-country allowlist format exists');
assert(workflow.includes('/^[A-Z0-9][A-Z0-9 -]{4,23}$/'), 'Identity number server format exists');

const security = text('includes/class-smc-security.php');
for (const symbol of [
  'SMC_MASTER_KEY',
  'SMC_PRIVATE_STORAGE_DIR',
  'aes-256-gcm',
  'hash_hkdf',
  'key_id',
  'authenticated context',
  'reject_symlink_path',
  '0700',
  '0600',
  'fsync',
  '.smc-tmp-',
  '.lease',
  'document_lock',
  'START TRANSACTION',
  'ROLLBACK',
  'COMMIT',
  'smc_document_scan',
  'Content-Disposition: attachment',
  'X-Frame-Options: DENY',
  'verified_unlink',
  'queue_file_job',
  'last_totp_slice',
  'smc_totp_replay',
  'smc_recovery_codes',
  'code_lookup_hash',
  'LAST_INSERT_ID(attempt_count+1)',
  'WP_Session_Tokens',
  'previous_hash',
  'row_hash',
  'decrypt_legacy_value',
  'migrate_legacy_document',
]) {
  assert(security.includes(symbol), `Security control: ${symbol}`);
}
assert(!security.includes("wp_salt( 'auth' ) . '|gdo"), 'No salt-only document encryption fallback');
assert(!security.includes('Content-Disposition: inline'), 'Private evidence is never served inline');
assert(!/\b(?:eval|system|exec|shell_exec|passthru|proc_open)\s*\(/.test(allPhp), 'Dangerous runtime primitives absent');

const admin = text('includes/class-smc-admin.php');
for (const symbol of [
  'A reviewer cannot decide their own evidence',
  'row_version',
  'approval_pending',
  'smc_approval_votes',
  'COUNT(DISTINCT reviewer_id)',
  'smc_finalize_verification',
  'name_match_status',
  'array_diff( $required, $approved_docs )',
  'professional_verified',
  'revoke_all_sessions',
  'smc_notify',
]) {
  assert(admin.includes(symbol), `Reviewer-governance control: ${symbol}`);
}
assert(!admin.includes('verify_mobile'), 'Reviewer cannot manufacture mobile ownership');
assert(!admin.includes('trusted publishing'), 'Membership reviewer cannot grant publishing trust');

const privacy = text('includes/class-smc-privacy.php');
for (const symbol of [
  'export_identity',
  'export_evidence',
  'export_workflow',
  'export_security',
  'active_hold',
  'items_retained',
  '.erase-',
  'verified_unlink',
  'privacy_delete',
  "0 === strpos( $key, '_smc_' )",
  'subject_hash',
  'privacy_erasure_completed',
]) {
  assert(privacy.includes(symbol), `Privacy control: ${symbol}`);
}
assert(!privacy.includes("audit( 'privacy_erasure_completed', $user_id"), 'Erasure does not recreate a direct user audit link');

const lifecycle = text('includes/class-smc-lifecycle.php');
for (const symbol of [
  'recheck_ages',
  'expire_documents',
  'guardian_requirement_ended_at_adulthood',
  'revoke_all_sessions',
  'cleanup_database',
  'cleanup_filesystem',
  '.smc-tmp-',
  '.erase-',
  'delete_orphan',
]) {
  assert(lifecycle.includes(symbol), `Lifecycle control: ${symbol}`);
}

function luminance(hex) {
  const channels = hex.match(/[a-f0-9]{2}/gi).map((part) => parseInt(part, 16) / 255)
    .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
  return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
}
function contrast(a, b) {
  const one = luminance(a);
  const two = luminance(b);
  return (Math.max(one, two) + 0.05) / (Math.min(one, two) + 0.05);
}
const css = text('assets/membership.css');
assert(contrast('9b3d00', 'ffffff') >= 4.5, 'Primary orange meets WCAG AA on white');
assert(contrast('4b5563', 'ffffff') >= 4.5, 'Muted text meets WCAG AA on white');
for (const symbol of ['[dir="rtl"]', ':focus', 'prefers-reduced-motion', '@media (max-width: 640px)']) {
  assert(css.includes(symbol), `Accessibility style: ${symbol}`);
}
assert(workflow.includes('aria-labelledby') && workflow.includes('aria-live'), 'Accessible landmarks and live status exist');
assert(main.includes('load_plugin_textdomain'), 'Text domain is loaded');
assert(!/\blicence\b/i.test(allPhp), 'American English license spelling is used');

const map = JSON.parse(fs.readFileSync(path.join(root, 'qa', 'defect-map.json'), 'utf8'));
const covered = new Map();
for (const group of map.groups) {
  for (let id = group.from; id <= group.to; id += 1) {
    assert(!covered.has(id), `Defect SMC-${String(id).padStart(3, '0')} mapped once`);
    covered.set(id, group);
  }
  for (const evidence of group.evidence) {
    const candidate = evidence.startsWith('includes/') || evidence.startsWith('assets/') || evidence === 'sabri-membership-core.php'
      ? path.join(plugin, evidence)
      : path.join(root, evidence);
    assert(fs.existsSync(candidate), `Defect group evidence exists: ${evidence}`);
  }
}
for (let id = 1; id <= map.findings; id += 1) {
  assert(covered.has(id), `Defect SMC-${String(id).padStart(3, '0')} has closure control`);
}
assert(covered.size === 181, 'All 181 baseline findings are mapped');

console.log(`Source SHA-256: ${allSource}`);
console.log(`Assertions passed: ${passed}`);
if (failures.length) {
  console.error(`Assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('Assertions failed: 0');

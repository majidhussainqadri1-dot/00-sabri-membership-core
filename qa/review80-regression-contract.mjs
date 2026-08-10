import fs from 'node:fs';

const root = 'source/sabri-membership-core';
const read = (p) => fs.readFileSync(`${root}/${p}`, 'utf8');
const main = read('sabri-membership-core.php');
const workflow = read('includes/class-smc-workflow.php');
const host = read('includes/class-smc-host-compat.php');
const admin = read('includes/class-smc-admin.php');
const contracts = read('includes/class-smc-contracts.php');
const events = read('includes/class-smc-events.php');
const completion = read('includes/class-smc-completion.php');
const installer = read('includes/class-smc-installer.php');
const schema = read('includes/class-smc-schema-compat.php');
const lifecycle = read('includes/class-smc-lifecycle.php');
const privacy = read('includes/class-smc-privacy.php');
const advanced = read('includes/class-smc-advanced-trust-2026.php');

const failures = [];
let passed = 0;
const check = (ok, label) => { if (ok) { passed++; } else { failures.push(label); } };
const has = (s, n, l=n) => check(s.includes(n), l);
const lacks = (s, n, l=n) => check(!s.includes(n), l);

has(main, 'Version: 1.2.40', 'runtime 1.2.40');
has(main, "define( 'SMC_DB_VERSION', '1.4.5' );", 'DB contract 1.4.5');
has(main, "define( 'SMC_CONTRACT_VERSION', '1.2.3' );", 'public contract 1.2.3');

for (const action of ['start_2fa','finish_2fa','challenge_2fa','rotate_recovery','ack_recovery_receipt']) {
  lacks(host, `'${action}'`, `host fallback excludes retired ${action}`);
  lacks(workflow, `function handle_${action}`, `dead retired handler absent ${action}`);
}
lacks(workflow, 'Enable two-factor authentication in Membership Security.', 'status has no retired MFA blocker');
lacks(workflow, "'Two-factor security'", 'status has no retired MFA required row');
lacks(admin, 'session_is_verified', 'reviewer/admin decisions do not use File 00 MFA session');

const authStart = advanced.indexOf('public static function authentication_assurance');
const authEnd = advanced.indexOf('/** F00-EXT-003', authStart);
const authBlock = authStart >= 0 && authEnd > authStart ? advanced.slice(authStart, authEnd) : '';
has(authBlock, "'owner' => 'none'", 'missing auth assurance baseline owner none');
has(authBlock, "'file02' === $owner", 'only File02 can elevate authentication assurance');
has(authBlock, "'owner' => 'file02'", 'accepted authentication claim identifies File02');
lacks(authBlock, "'owner' => 'file00'", 'File00 never owns authentication claim');

has(contracts, '1 !== $deleted', 'role replacement verifies grant deletion');
has(contracts, '$stored_types !== $expected', 'role replacement read-back is exact');
has(contracts, 'private static function age_context', 'privacy-minimal age projection exists');
lacks(contracts, "'age_years'", 'cross-file contracts expose no exact age');
has(contracts, "'minor_restrictions'", 'File17 minor restrictions explicit');
has(contracts, 'clinical_commerce_assertions', 'File08/18 clinical-commerce projection explicit');
has(contracts, "'emergency_online_case_allowed' => false", 'emergency online clinical case is prohibited');
has(contracts, "'platform_commission_percent' => 0", 'clinical-commerce contract preserves zero commission');

has(events, "'age_band', 'type'", 'durable events use age band');
lacks(events, "'age', 'type'", 'durable events disallow exact age');
has(events, "strlen( $event_type ) > 80", 'event type length bounded');
has(events, "strlen( $consumer ) > 80", 'consumer length bounded');
has(events, "[1-5][0-9a-f]{3}-[89ab]", 'event inbox requires canonical UUID version/variant');

has(completion, 'Repair adapter raised an exception; retry is required.', 'repair callback Throwable is contained');
has(completion, "'database_version' => (string) get_option( 'smc_db_version', '' )", 'backup manifest reports actual DB marker');
has(completion, "'target_database_version' => SMC_DB_VERSION", 'backup manifest reports DB target separately');
has(completion, 'post_restore_reconciliation_persistence_failed', 'restore persistence failure has explicit evidence');
has(completion, 'status record was rolled back', 'restore status rolls back if audit append fails');
has(completion, 'evidence_reference_digest', 'restore audit avoids raw operator evidence reference');

has(installer, 'KEY expires_at (expires_at)', 'session expiry cleanup indexed');
has(installer, 'KEY revoked_at (revoked_at)', 'session revocation cleanup indexed');
has(schema, 'Current session cleanup index could not be verified', 'session indexes are post-migration verified');

has(lifecycle, 'INSTITUTIONAL_REPAIR_CURSOR_OPTION', 'institutional repair has persistent cursor');
has(lifecycle, "status='suspended' AND id>%d ORDER BY id ASC LIMIT 500", 'institutional repair keyset pagination');
has(lifecycle, 'count( $rows ) < 500 ? 0 : $cursor', 'institutional repair wraps after full sweep');

has(privacy, 'Legacy retired File 00 MFA residue present', 'privacy export labels old MFA as residue');
has(privacy, 'Sabri Authentication (File 02)', 'privacy text names File02 authentication owner');
lacks(privacy, "__( 'Two-factor enabled'", 'privacy export does not describe retired File00 MFA as active');

console.log(`review80 regression static: ${passed} PASS / ${failures.length} FAIL`);
if (failures.length) {
  failures.forEach((f) => console.error(`- ${f}`));
  process.exit(1);
}

import fs from 'node:fs';
const root = new URL('../', import.meta.url);
const load = p => fs.readFileSync(new URL(p, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const functions = load('source/sabri-membership-core/includes/functions.php');
const contracts = load('source/sabri-membership-core/includes/class-smc-contracts.php');
const security = load('source/sabri-membership-core/includes/class-smc-security.php');
const events = load('source/sabri-membership-core/includes/class-smc-events.php');
const latest = load('source/sabri-membership-core/includes/class-smc-latest-central-2026.php');
const css = load('source/sabri-membership-core/assets/membership.css');
const doc = load('docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md');
const checks = [
  ['runtime 1.2.12', plugin.includes("define( 'SMC_VERSION', '1.2.12' );")],
  ['latest central layer loaded', plugin.includes('class-smc-latest-central-2026.php') && plugin.includes('SMC_Latest_Central_2026::init()')],
  ['F00-CEN-01 single free tier', functions.includes("'single_free_tier'        => true") && functions.includes("'paid_unlocks_enabled'    => false") && functions.includes("'legacy_pricing_enabled'  => false")],
  ['F00-CEN-02 donor neutral', functions.includes("'donation_affects_rank'    => false") && functions.includes("'donation_affects_entitlement' => false") && functions.includes("'donation_affects_support'     => false")],
  ['zero commission', functions.includes("'commission_percent'       => 0")],
  ['exact Sabri Green fallback', css.includes('#087A4E') && !css.includes('var(--ssp-primary, #166534)')],
  ['00-26 ownership', functions.includes("'numbered_file_max'       => 26") && functions.includes("'search_discovery_owner'  => 'file26'") && latest.includes("'search_discovery_owner'     => 'file26'")],
  ['File 26 projection without search backend', latest.includes('smc_file26_membership_projection_v1') && latest.includes("'consumer'              => 'file26'") && !latest.includes('CREATE TABLE') && !latest.includes('WP_Query')],
  ['File 26 projection is donor/payment neutral', latest.includes("'donation_rank_signal'  => false") && latest.includes("'paid_rank_signal'      => false")],
  ['File 26 projection fail closed', latest.includes("'indexable'             => false") && latest.includes("$approved && $eligible && ! $suspended && $public")],
  ['File 26 projection uses opaque platform UUID', latest.includes("'platform_uuid'") && latest.includes('SMC_CF01_Contract::ensure_subject_uuid') && !latest.includes("'user_id'" )],
  ['no sensitive projection fields', !latest.includes("'date_of_birth'") && !latest.includes("'phone'") && !latest.includes("'address'") && !latest.includes("'guardian_email'") && !latest.includes("'document_number'")],
  ['File 09 canonical claim', contracts.includes('smc_file09_doctor_verification_claim_v1') && contracts.includes('Never infer professional truth from stale display/user-meta') && !contracts.includes("return 'verified' === get_user_meta( $user_id, '_spd_verification_status', true );")],
  ['mandatory audit guard bridge', events.includes("apply_filters( 'smc_audit_record_guard', true") && events.includes('if ( true !== $guard_ok )') && latest.includes("add_filter( 'smc_audit_record_guard'")],
  ['audit observer remains after mandatory guard', events.indexOf("apply_filters( 'smc_audit_record_guard'") < events.indexOf("do_action( 'smc_audit_recorded'")],
  ['F00-CEN-03 marker cannot be satisfied by same-second old challenge', latest.includes('$stamp = max( time() + 1, $previous + 1 );') && security.includes("'_smc_revalidation_required_at'") && security.includes('max( $base_cutoff, $required_after )')],
  ['successful TOTP clears revalidation marker before audit', security.includes('private static function clear_revalidation_requirement') && security.includes('$revalidation_ok = 1 === $updated && self::clear_revalidation_requirement( $user_id );') && security.includes("$audit_ok = $revalidation_ok && self::audit( 'two_factor_passed'")],
  ['successful recovery challenge clears revalidation marker', security.includes('$revalidation_ok = 1 === $code_updated && 1 === $session_updated && self::clear_revalidation_requirement( $user_id );') && security.includes("$audit_ok = $revalidation_ok && self::audit( 'recovery_code_used'")],
  ['security changes invalidate File 26 projection', latest.includes("do_action(\n\t\t\t'smc_file26_projection_invalidated'")],
  ['traceability maps latest requirements', doc.includes('F00-CEN-01') && doc.includes('F00-CEN-02') && doc.includes('F00-CEN-03') && doc.includes('File 26') && doc.includes('AJ-25') && doc.includes('CV-280')],
  ['external gates remain separate', doc.includes('Staging-Accepted | pending') && doc.includes('Live-Deployed | pending') && doc.includes('Operational | pending')],
];
let failed = 0;
for (const [name, ok] of checks) { console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}`); if (!ok) failed++; }
if (failed) process.exit(1);
console.log(`${checks.length}/${checks.length} latest-central static assertions passed.`);

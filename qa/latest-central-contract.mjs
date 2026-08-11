import fs from 'node:fs';
const root = new URL('../', import.meta.url);
const load = p => fs.readFileSync(new URL(p, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const functions = load('source/sabri-membership-core/includes/functions.php');
const contracts = load('source/sabri-membership-core/includes/class-smc-contracts.php');
const events = load('source/sabri-membership-core/includes/class-smc-events.php');
const latest = load('source/sabri-membership-core/includes/class-smc-latest-central-2026.php');
const retirement = load('source/sabri-membership-core/includes/class-smc-mfa-retirement.php');
const css = load('source/sabri-membership-core/assets/membership.css');
const doc = load('docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md');
const checks = [
  ['runtime 1.2.41', plugin.includes("define( 'SMC_VERSION', '1.2.41' );")],
  ['latest central layer loaded', plugin.includes('class-smc-latest-central-2026.php') && plugin.includes("array( 'SMC_Latest_Central_2026', 'init' )")],
  ['current central constitution reflects MFA retirement', latest.includes("const CONSTITUTION_VERSION = '2026-08-10-v1.1'") && latest.includes("'mfa_owner'                  => 'none'") && latest.includes("'file00_mfa_required'        => false")],
  ['F00-CEN-01 single free tier', functions.includes("'single_free_tier'        => true") && functions.includes("'paid_unlocks_enabled'    => false") && functions.includes("'legacy_pricing_enabled'  => false")],
  ['F00-CEN-02 donor neutral', functions.includes("'donation_affects_rank'    => false") && functions.includes("'donation_affects_entitlement' => false") && functions.includes("'donation_affects_support'     => false")],
  ['zero commission', functions.includes("'commission_percent'       => 0")],
  ['exact Sabri Green fallback', css.includes('#087A4E') && !css.includes('var(--ssp-primary, #166534)')],
  ['00-26 ownership', functions.includes("'numbered_file_max'       => 26") && functions.includes("'search_discovery_owner'  => 'file26'") && latest.includes("'search_discovery_owner'     => 'file26'")],
  ['File 26 projection without search backend', latest.includes('function smc_file26_membership_projection') && latest.includes("'consumer'              => 'file26'") && !latest.includes('CREATE TABLE') && !latest.includes('WP_Query')],
  ['File 26 projection is canonical, not filter-mediated', latest.includes('return SMC_Latest_Central_2026::file26_projection( absint( $user_id ) );') && !latest.includes("add_filter( 'smc_file26_membership_projection_v1'")],
  ['central constitution is canonical, not filter-mediated', latest.includes('return SMC_Latest_Central_2026::constitution();') && !latest.includes("add_filter( 'smc_latest_central_constitution_v1'")],
  ['File 26 projection is donor/payment neutral', latest.includes("'donation_rank_signal'  => false") && latest.includes("'paid_rank_signal'      => false")],
  ['File 26 projection fail closed', latest.includes("'indexable'             => false") && latest.includes("$approved && $eligible && ! $suspended && $public")],
  ['File 26 projection uses opaque platform UUID', latest.includes("'platform_uuid'") && latest.includes('SMC_CF01_Contract::ensure_subject_uuid') && !latest.includes("'user_id'" )],
  ['no sensitive projection fields', !latest.includes("'date_of_birth'") && !latest.includes("'phone'") && !latest.includes("'address'") && !latest.includes("'guardian_email'") && !latest.includes("'document_number'")],
  ['File 09 canonical claim', contracts.includes('smc_file09_doctor_verification_claim_v1') && contracts.includes('Never infer professional truth from stale display/user-meta') && !contracts.includes("return 'verified' === get_user_meta( $user_id, '_spd_verification_status', true );")],
  ['mandatory audit guard bridge', events.includes("apply_filters( 'smc_audit_record_guard', true") && events.includes('if ( true !== $guard_ok )') && latest.includes("add_filter( 'smc_audit_record_guard'")],
  ['audit observer remains after mandatory guard', events.indexOf("apply_filters( 'smc_audit_record_guard'") < events.indexOf("do_action( 'smc_audit_recorded'")],
  ['projection invalidation baseline cannot be removed by filter', latest.includes('array_merge(\n\t\t\t\t\tself::$projection_invalidation_actions,') && latest.includes("apply_filters( 'smc_projection_invalidation_audit_actions', self::$projection_invalidation_actions )")],
  ['retired TOTP revalidation marker is not written', !latest.includes("REVALIDATION_META") && !latest.includes("update_user_meta( $user_id, self::REVALIDATION_META") && !latest.includes("smc_revalidation_audit_actions")],
  ['Founder retirement cleanup removes legacy marker', retirement.includes("'_smc_revalidation_required_at'") && retirement.includes('retire_legacy_factor_state')],
  ['all guardian-state changes still invalidate File 26 projection', latest.includes("'guardian_consent_verified'") && latest.includes("'guardian_consent_withdrawn'") && latest.includes("'guardian_requirement_ended_at_adulthood'")],
  ['security changes invalidate File 26 projection', latest.includes("do_action(\n\t\t\t'smc_file26_projection_invalidated'")],
  ['historical traceability maps prior latest requirements', doc.includes('F00-CEN-01') && doc.includes('F00-CEN-02') && doc.includes('F00-CEN-03') && doc.includes('File 26') && doc.includes('AJ-25') && doc.includes('CV-280')],
  ['historical external gates remain separate', doc.includes('Staging-Accepted | pending') && doc.includes('Live-Deployed | pending') && doc.includes('Operational | pending')],
];
let failed = 0;
for (const [name, ok] of checks) { console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}`); if (!ok) failed++; }
if (failed) process.exit(1);
console.log(`${checks.length}/${checks.length} latest-central static assertions passed for current File 00 1.2.41; historical 1.2.12 evidence remains provenance only.`);

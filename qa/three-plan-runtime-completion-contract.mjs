import fs from 'node:fs';
const root = new URL('../', import.meta.url);
const load = p => fs.readFileSync(new URL(p, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const functions = load('source/sabri-membership-core/includes/functions.php');
const contracts = load('source/sabri-membership-core/includes/class-smc-contracts.php');
const ai = load('source/sabri-membership-core/includes/class-smc-three-plan.php');
const css = load('source/sabri-membership-core/assets/membership.css');
const trace = JSON.parse(load('qa/requirements-traceability.json'));
const checks = [
  ['runtime 1.2.18', plugin.includes("define( 'SMC_VERSION', '1.2.18' );")],
  ['contract 1.2.0', plugin.includes("define( 'SMC_CONTRACT_VERSION', '1.2.0' );")],
  ['three-plan class loaded', plugin.includes('class-smc-three-plan.php') && plugin.includes('SMC_Three_Plan::init()')],
  ['free baseline', functions.includes("'free_baseline'           => true") && functions.includes("'paid_unlocks_enabled'    => false")],
  ['legacy pricing disabled', functions.includes("'legacy_pricing_enabled'  => false")],
  ['donation no entitlement/capability', functions.includes("'donation_affects_entitlement' => false") && functions.includes("'donation_affects_capability'  => false")],
  ['AI institutional identity', functions.includes('institutional_ai_teacher') && functions.includes('institutional_ai_publisher')],
  ['AI four posts and no doctor claim', functions.includes("'daily_post_limit'      => 4") && functions.includes("'doctor_verification'   => false")],
  ['AI 30-day review', functions.includes("'human_review_days'     => 30") && ai.includes('30-day review period')],
  ['admin publishing authority', contracts.includes("$is_admin ? 'administrator'") && contracts.includes('$is_founder || $is_admin')],
  ['AI publishing disclosure', contracts.includes("'ai_generated_disclosure_required'")],
  ['transfer 1GB', contracts.includes("'max_file_bytes'       => 1073741824")],
  ['transfer fail closed', contracts.includes("'relationship_authorized'") && contracts.includes("'consent_authorized'") && contracts.includes("'public_url_allowed'   => false")],
  ['green visual token', css.includes('--smc-brand:') && css.includes('#087A4E') && !css.includes('--smc-orange:')],
  ['three governing artifacts', !!trace.platform_master_plan && !!trace.file00_master_plan && !!trace.all_chats_recovered_directives],
  ['recovered directives mapped', Array.isArray(trace.recovered_directives) && trace.recovered_directives.length >= 7],
  ['external gates stay pending', trace.staging_accepted === false && trace.production_approved === false && trace.live_installation_authorized === false],
];
let failed=0;
for (const [name,ok] of checks){console.log(`${ok?'PASS':'FAIL'}: ${name}`); if(!ok) failed++;}
if(failed) process.exit(1);
console.log(`${checks.length}/${checks.length} three-plan completion assertions passed.`);

import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';

/**
 * V12 123-item source inventory and 12 DISTINCT review lenses.
 *
 * CAUTION: static source signals and historical registers are not independent
 * requirement-level test passes, deployed evidence, or release approval.
 */
const root=path.resolve(import.meta.dirname, '..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const file=p=>fs.existsSync(path.join(root,p));
const sha=s=>createHash('sha256').update(s).digest('hex');
const legacy=JSON.parse(read('qa/requirements-traceability.json'));
const extended=JSON.parse(read('qa/advanced-trust-traceability.json'));
const names=Array.from({length:100},(_,i)=>'F00-R'+String(i+1).padStart(3,'0'));
const cen=['F00-CEN-01','F00-CEN-02','F00-CEN-03'];
const ext=Array.from({length:20},(_,i)=>'F00-EXT-'+String(i+1).padStart(3,'0'));
const ids=[...names,...cen,...ext];
const src='source/sabri-membership-core/includes/';
const plugin=read('source/sabri-membership-core/sabri-membership-core.php');
const adv=read(src+'class-smc-advanced-trust-2026.php');
const contracts=read(src+'class-smc-contracts.php');
const auth=read(src+'class-smc-authorization.php');
const central=read(src+'class-smc-latest-central-2026.php');
const security=read(src+'class-smc-security.php');
const installer=read(src+'class-smc-installer.php');
const lifecycle=read(src+'class-smc-lifecycle.php');
const cf=read(src+'class-smc-cf01-contract.php');
const workflow=read(src+'class-smc-workflow.php');
const mfa=read(src+'class-smc-mfa-retirement.php');
const qa=read('qa/advanced-trust-runtime.php');
const pkg=JSON.parse(read('package.json'));
const failures=[];
const review=(title,signals)=>{
 const missing=signals.filter(([_,ok])=>!ok).map(([name])=>name);
 if(missing.length)failures.push({title,missing});
 return {pass:title,static_signals:signals.length,source_check_failed:missing.length,
     finding:missing.length?'SOURCE SIGNAL MISSING: '+missing.join('; '):'source signals present; external acceptance not inferred'};
};
const traces=legacy.requirements||[];
const enhancements=extended.requirements||[];
const exactSequence=(got,expected)=>JSON.stringify(got)===JSON.stringify(expected);
const lenses=[
 review('01 — 123 canonical requirement IDs',[
  ['legacy R001–R100',exactSequence(traces.map(x=>x.id),names)],
  ['central 3 distinct IDs',new Set(cen).size===3],
  ['extensions 20 stable IDs',exactSequence(enhancements.map(x=>x.id),ext)],
  ['no 123 IDs duplicated',ids.length===123&&new Set(ids).size===123],
 ]),
 review('02 — governance & single-free-tier boundary',[
  ['free-only constitution',central.includes('single_free_tier')&&central.includes('paid_unlocks_enabled')],
  ['no donor unlock in constitutional policy',central.includes('donation')&&central.includes('commission_percent')],
  ['historical plan register exists',file('qa/requirements-traceability.json')],
 ]),
 review('03 — actor identity and authorization',[
  ['actor function exists',adv.includes('function actor_is_current')],
  ['anonymous actor explicitly denied',adv.includes('get_current_user_id() <= 0')],
  ['real 8-case runtime negative test wired',pkg.scripts.test.includes('qa/v12-actor-binding-runtime.php')],
  ['File01 typed bridge',auth.includes('file01_authorization_claim')],
  ['untyped system reverification rejected',adv.includes('system_reverification_authorized') && !adv.includes("(bool) apply_filters( 'smc_system_reverification_authorized'")],
 ]),
 review('04 — File02 assurance ownership',[
  ['File02 account owner',central.includes("'authentication_owner'")],
  ['typed authentication assurance',adv.includes('smc_file02_authentication_assurance_v1')],
  ['MFA retired without silent legacy factor',mfa.includes('retire_mfa_assertions')],
  ['File08 canonical UUID resolver available',cf.includes('resolve_user_id_by_subject_uuid') && read(src+'functions.php').includes('smc_get_user_id_by_subject_uuid')],
  ['File04 never trusts existing caller UUID',cf.includes('function file04_platform_uuid') && cf.includes('unset( $existing, $context )')],
  ['File04 reserved author collision guarded',cf.includes("'_smc_legacy_author_placeholder_v1'") && cf.includes("'_smc_login_disabled_v1'") && cf.includes('function block_placeholder_login')],
  ['T38 and File04 negative runtime suites wired',pkg.scripts.test.includes('qa/t38-file08-inverse-subject-runtime.php') && pkg.scripts.test.includes('qa/v12-file04-authorship-guard-runtime.php')],
 ]),
 review('05 — age, guardian and revalidation',[
  ['age/guardian reconciliation',lifecycle.includes('recheck_ages')],
  ['guardian succession consent',adv.includes('complete_guardian_succession')],
  ['critical identity revocation',adv.includes('critical_identity_change')&&adv.includes('revoke_all_sessions')],
  ['periodic verification bounded to one year',adv.includes('function reverification_interval_seconds') && adv.includes('min( YEAR_IN_SECONDS, $requested )')],
  ['stored future due value clamped',adv.includes('min( $stored_due_at, $policy_due_at )')],
 ]),
 review('06 — privacy and minimum disclosure',[
  ['privacy-safe claim generator',adv.includes('minimal_assertions')],
  ['selective proofs verified',adv.includes('verify_selective_disclosure_proof')],
  ['consent graph baseline',adv.includes('consent_dependency_graph')],
  ['cross-subject timeline has purpose-bound claim',adv.includes('trust_timeline_cross_subject_authorized') && adv.includes('smc_trust_timeline_access_v1')],
  ['private identity encryption',security.includes('aes-256-gcm')],
 ]),
 review('07 — revocation and time constraints',[
  ['60-second propagation',adv.includes('REVOCATION_PROPAGATION_SLA = 60')],
  ['monotonic epoch',adv.includes('revocation_epoch( $user_id ) + 1')],
  ['periodic reverification',adv.includes('daily_reverification_sweep')],
 ]),
 review('08 — duplicate, merge and integrity',[
  ['merge proposal',adv.includes('propose_account_merge')],
  ['independent merge approval',adv.includes('approve_account_merge')],
  ['audited state',adv.includes('merge_request_is_active')],
 ]),
 review('09 — scoped delegation and break-glass',[
  ['90-day cap',adv.includes('90 * DAY_IN_SECONDS')],
  ['one-time break-glass',adv.includes('consume_break_glass')],
  ['dual control',adv.includes('break_glass_approvals_current')],
 ]),
 review('10 — schema, locking and recovery',[
  ['schema installer',installer.includes('install_schema')||installer.includes('dbDelta')],
  ['reconciliation workflow',workflow.includes('transition_self_service')],
  ['audited security lock',security.includes('document_lock')],
 ]),
 review('11 — source, packaging and QA',[
  ['PHP 7.4 matrix',read('.github/workflows/cf01-contract.yml').includes("'7.4'")],
  ['PHP 8.3 matrix',read('.github/workflows/cf01-contract.yml').includes("'8.3'")],
  ['locked package dependency',file('package-lock.json')],
  ['runtime test harness',qa.includes('SMC_Advanced_Trust_2026')],
 ]),
 review('12 — external truth and independent approval',[
  ['historical registration preserved',traces.length===100],
  ['historical code status cannot decide live',legacy.staging_accepted===false && legacy.live_installation_authorized===false],
  ['advanced trust is historical',extended.release!=='1.2.44'],
 ])
];
const result={
 baseline:'Master Plan v12 File 00 R001–R100, CEN01–03, EXT001–020',
 catalog_count:ids.length, historical_base_requirements:traces.length,
 historical_extensions:enhancements.length, central_requirements:cen,
 review_lenses:lenses, static_source_signals_failed:failures.length,
 legacy_code_complete_claim_is_historical:true,
 individual_v12_ci_attested:0, staging_accepted:false, live_deployed:false,
 unmerged_relevant_prs:[52,54],
 code_fingerprint:{advanced_trust_sha256:sha(adv),authorization_sha256:sha(auth),cf01_sha256:sha(cf)},
 source_commit_verification:'separate exact-HEAD GitHub CI required',
 caveat:'12 distinct static review lenses, NOT 12 independently full end-to-end reviews; no live or 123/123 acceptance claim.'
};
console.log(JSON.stringify(result,null,2));
if(failures.length){process.exitCode=1;}

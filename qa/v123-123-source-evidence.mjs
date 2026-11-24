/**
 * V12.3: 123 per-ID source evidence on THIS checkout only.
 * No source-marker, static test or CI color is end-to-end acceptance.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
const root=path.resolve(import.meta.dirname,'..');
const file=p=>path.join(root,p);
const read=p=>fs.readFileSync(file(p),'utf8');
const json=p=>JSON.parse(read(p));
const sha=p=>crypto.createHash('sha256').update(fs.readFileSync(file(p))).digest('hex');
const inventory=json('docs/FILE00-V123-123-TRACEABILITY-BASELINE.json');
const r80=json('build/file00-v12-r80-source.json');
const rMap=new Map(r80.rows.map(x=>[x.id,x]));
const src={
  contracts:'source/sabri-membership-core/includes/class-smc-contracts.php',
  advanced:'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php',
  lifecycle:'source/sabri-membership-core/includes/class-smc-lifecycle.php'
};
const extMethods=[
  'assurance_profile','authentication_assurance','step_up_requirement',
  'reverification_status','mark_critical_identity_change','claims_envelope',
  'consent_dependency_graph','begin_guardian_succession','propose_account_merge',
  'containment_state','bump_revocation_epoch','negotiate_contract',
  'minimal_assertions','selective_disclosure_proof','verifiable_credentials',
  'grant_delegated_authority','open_break_glass','subject_kind',
  'continuity_state','trust_timeline'
];
const cen={
 'F00-CEN-01':{paths:[src.contracts],tokens:['financial_baseline','single_free_tier','paid_unlocks_enabled']},
 'F00-CEN-02':{paths:[src.contracts],tokens:['donation_affects_entitlement','donation_affects_capability','donation_affects_visibility','donation_affects_support']},
 'F00-CEN-03':{paths:[src.lifecycle,src.contracts,src.advanced],tokens:['recheck_ages','guardian_verified','consent_dependencies_satisfied','protected_actions_allowed']}
};
const errors=[],rows=[];
for(const item of inventory.requirements){
 let evidence=[],isSourceVerified=false,granularity='governance_or_release_gate_not_implementation_proven';
 if(rMap.has(item.id)){
   const q=rMap.get(item.id);
   evidence=q.evidence.map(e=>({path:e.path,sha256:e.sha256,symbol:e.symbol,verified:e.verified}));
   isSourceVerified=evidence.length===2&&evidence.every(e=>e.verified);
   granularity='two_requirement_specific_source_entrypoints';
 }else if(item.id.startsWith('F00-EXT-')){
   const n=Number(item.id.slice(-3))-1,method=extMethods[n],p=src.advanced;
   const present=!!method&&new RegExp('\\bfunction\\s+'+method+'\\s*\\(').test(read(p))&&read(p).includes(item.id);
   evidence=[{path:p,sha256:sha(p),symbol:'SMC_Advanced_Trust_2026::'+method,verified:present}];
   isSourceVerified=present;granularity='specific_advanced_trust_method_and_id';
 }else if(Object.hasOwn(cen,item.id)){
   const x=cen[item.id],text=x.paths.map(read).join('\n');
   evidence=x.paths.map(p=>({path:p,sha256:sha(p)}));
   evidence.push(...x.tokens.map(token=>({token,verified:text.includes(token)})));
   isSourceVerified=x.tokens.every(token=>text.includes(token));granularity='central_policy_source_tokens';
 }
 if(!isSourceVerified&&granularity!=='governance_or_release_gate_not_implementation_proven'){
   errors.push(item.id+' current checkout source evidence missing');
 }
 rows.push({
   id:item.id,requirement:item.title,granularity,source_signal_verified:isSourceVerified,
   evidence,positive_negative_behavioral_acceptance:'UNVERIFIED',
   cross_file_installed_acceptance:'UNVERIFIED',
   staging_acceptance:'UNVERIFIED',live_deployed_acceptance:'UNVERIFIED',
   source_only_not_completion:true
 });
}
const ids=rows.map(x=>x.id);
if(rows.length!==123||new Set(ids).size!==123)errors.push('Incomplete or duplicated 123 ID inventory');
const signalCount=rows.filter(x=>x.source_signal_verified).length;
const result={
 schema:'file00.v123.source-evidence/1',
 source_main_baseline:inventory.source_main_sha,
 scope:'current checkout; SOURCE signals only; never signed deployment',
 summary:{
  ids:rows.length,
  r80_source_pairs_verified:rows.filter(x=>x.id.startsWith('F00-R')&&x.source_signal_verified).length,
  central_source_tokens_verified:rows.filter(x=>x.id.startsWith('F00-CEN')&&x.source_signal_verified).length,
  extension_source_methods_verified:rows.filter(x=>x.id.startsWith('F00-EXT')&&x.source_signal_verified).length,
  aggregate_source_signals_verified:signalCount,
  independently_accepted_behavioral_requirements:0,
  staging_accepted_requirements:0,live_accepted_requirements:0
 },rows
};
fs.mkdirSync(file('build'),{recursive:true});
fs.writeFileSync(file('build/file00-v123-123-source-evidence.json'),JSON.stringify(result,null,2)+'\n');
console.log('File00 V12.3 exact source evidence: '+signalCount+'/123 signals; 0/123 independent acceptance; R80 / CEN3 / EXT20 separate');
if(errors.length){console.error(errors.join('\n'));process.exit(1);}

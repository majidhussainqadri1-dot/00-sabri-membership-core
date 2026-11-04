/**
 * File00 Master Plan v12: one record per one of the 123 canonical requirements.
 * Reads exact source bytes; distinguishes historical group pointers from actual
 * requirement-specific source signatures and individually executed tests.
 * Never self-attests completed CI or approval.
 */
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
const root=path.resolve(import.meta.dirname,'..');
const file=p=>path.join(root,p);
const exists=p=>fs.existsSync(file(p))&&fs.statSync(file(p)).isFile();
const read=p=>fs.readFileSync(file(p),'utf8');
const hash=p=>createHash('sha256').update(fs.readFileSync(file(p))).digest('hex');
const legacy=JSON.parse(read('qa/requirements-traceability.json'));
const oldExt=JSON.parse(read('qa/advanced-trust-traceability.json'));
const requirementIds=Array.from({length:100},(_,i)=>'F00-R'+String(i+1).padStart(3,'0'));
const cenIds=['F00-CEN-01','F00-CEN-02','F00-CEN-03'];
const extensionIds=Array.from({length:20},(_,i)=>'F00-EXT-'+String(i+1).padStart(3,'0'));
const expected=[...requirementIds,...cenIds,...extensionIds];
const basePath='source/sabri-membership-core/includes/';
const baseRead=name=>read(basePath+name);
const source={
 contracts:basePath+'class-smc-contracts.php',
 advanced:basePath+'class-smc-advanced-trust-2026.php',
 lifecycle:basePath+'class-smc-lifecycle.php',
 authorization:basePath+'class-smc-authorization.php',
 cf01:basePath+'class-smc-cf01-contract.php',
};
const cen=[
 {id:'F00-CEN-01',title:'Single-free-tier entitlement only; no paid renewal or donor privilege',
  source_files:[source.contracts],tokens:['financial_baseline','single_free_tier','paid_unlocks_enabled'],
  test_files:['qa/latest-central-contract.mjs','qa/run-tests.mjs']},
 {id:'F00-CEN-02',title:'Donations do not change membership, capabilities, ranking, verification or support priority',
  source_files:[source.contracts],tokens:['donation_affects_entitlement','donation_affects_capability','donation_affects_visibility','donation_affects_support'],
  test_files:['qa/latest-central-contract.mjs','qa/run-tests.mjs']},
 {id:'F00-CEN-03',title:'Age, guardian, consent changes rechecked for next protected action; session/cache invalidation',
  source_files:[source.lifecycle,source.contracts,source.advanced],
  tokens:['recheck_ages','guardian_verified','consent_dependencies_satisfied','protected_actions_allowed'],
  test_files:['qa/membership-state-runtime.php','qa/advanced-trust-runtime.php']},
];
const extMethods=[
 'assurance_profile','authentication_assurance','step_up_requirement',
 'reverification_status','mark_critical_identity_change','claims_envelope',
 'consent_dependency_graph','begin_guardian_succession','propose_account_merge',
 'containment_state','bump_revocation_epoch','negotiate_contract',
 'minimal_assertions','selective_disclosure_proof','verifiable_credentials',
 'grant_delegated_authority','open_break_glass','subject_kind','continuity_state','trust_timeline',
];
const code=baseRead('class-smc-advanced-trust-2026.php');
const all=legacy.requirements||[], groups=legacy.groups||[], old=oldExt.requirements||[];
const centralStaticContract=read('qa/latest-central-contract.mjs');
const advancedStaticContract=read('qa/advanced-trust-contract.mjs');
const fail=[];
const idEquals=(actual,anticipated)=>JSON.stringify(actual)===JSON.stringify(anticipated);
if(!idEquals(all.map(x=>x.id),requirementIds))fail.push('F00-R ID sequence malformed');
if(!idEquals(old.map(x=>x.id),extensionIds))fail.push('F00-EXT ID sequence malformed');
if(new Set(expected).size!==123)fail.push('Expected 123 unique IDs');
const rows=[];
const pointer=p=>({path:p,exists:exists(p),sha256:exists(p)?hash(p):null});
for(const req of all){
 const group=groups.find(g=>g.group===req.group);
 if(!group){fail.push('Historical requirement lacks group: '+req.id);continue}
 const evidence=(group.evidence||[]).filter(p=>typeof p==='string').map(pointer);
 const sources=evidence.filter(e=>e.path.startsWith('source/')||e.path==='ARCHITECTURE.md'||e.path==='SECURITY.md');
 const tests=evidence.filter(e=>e.path.startsWith('qa/')||e.path.startsWith('.github/workflows/'));
 const external=(req.id>='F00-R051'&&req.id<='F00-R060')||
    (req.id>='F00-R061'&&req.id<='F00-R070')||
    (req.id>='F00-R091');
 rows.push({
  id:req.id,requirement:req.id==='F00-R001'?
    'v12 governs R001-R100 stable IDs, 100-row register, source IDs, owners, test/evidence classes and acceptance linkage':req.title,
  plan_origin:'Sabri-Website-Master-Plan-v12.docx',
  owner:group.owner, evidence_class:group.test_class,
  source_evidence:sources, test_file_pointers:tests,
  source_granularity:'historical_group_pointer_only',
  individual_static_check_present:false,
  direct_v12_behavior_test_attested:false,
  historical_code_status:req.code_status,
  historical_acceptance_status:req.acceptance_status,
  current_source_signal_verified:sources.length>0&&sources.every(e=>e.exists),
  current_test_files_present:tests.length>0&&tests.every(e=>e.exists),
  cross_repo_or_external_gate:external?'independent_acceptance_pending':null,
  accepted:false, reason:'Historical group pointers/CI are not individual v12 acceptance or consumer/staging proof',
 });
}
for(const c of cen){
 const files=c.source_files.map(pointer), tests=c.test_files.map(pointer);
 const text=c.source_files.map(read).join('\n');
 const tokens=c.tokens.map(token=>({token,present:text.includes(token)}));
 rows.push({id:c.id,requirement:c.title,plan_origin:'Sabri-Website-Master-Plan-v12.docx',
   owner:'File00 canonical identity/entitlement owner',evidence_class:'source assertions plus runtime and consumer acceptance',
   source_evidence:files,test_file_pointers:tests,source_tokens:tokens,
   source_granularity:'requirement_specific_static_signals',
   individual_static_check_present:centralStaticContract.split('\n').some(line=>line.includes("['"+c.id+" ")),
   current_source_signal_verified:files.every(e=>e.exists)&&tokens.every(t=>t.present),
   current_test_files_present:tests.every(e=>e.exists),
   direct_v12_behavior_test_attested:false,cross_repo_or_external_gate:'consumer verification pending',
   accepted:false,reason:'Source policy and test-file existence do not establish individually attested negative consumer tests'});
}
for(let i=0;i<old.length;i++){
 const req=old[i],method=extMethods[i],direct=new RegExp('\\bfunction\\s+'+method+'\\s*\\(').test(code);
 const codeId=code.includes(req.id),contract=read('qa/advanced-trust-contract.mjs');
 const directStatic=contract.includes(req.id), evidence=[pointer(source.advanced)];
 const suffix=String(i+1).padStart(3,'0');
 const namedCheck=advancedStaticContract.split('\n').some(line=>line.includes("['EXT-"+suffix+" ") && line.includes("'"+req.id+"'"));
 const tests=[pointer('qa/advanced-trust-contract.mjs'),pointer('qa/advanced-trust-runtime.php'),
   pointer('qa/advanced-trust-review-hardening-runtime.php')];
 if(req.id==='F00-EXT-020')tests.push(pointer('qa/v12-actor-binding-runtime.php'));
 if(req.id==='F00-EXT-004')tests.push(pointer('qa/v12-actor-binding-runtime.php'));
 rows.push({id:req.id,requirement:req.name,plan_origin:'Sabri-Website-Master-Plan-v12.docx',
   owner:'File00 advanced trust',evidence_class:'function-level source and static/runtime regression',
   source_evidence:evidence,test_file_pointers:tests,source_symbol:'SMC_Advanced_Trust_2026::'+method,
   source_granularity:'requirement_specific_method',
   method_exists:direct,requirement_id_present:codeId,static_test_marker_present:directStatic,
   individual_static_check_present:namedCheck,
   current_source_signal_verified:direct&&codeId,
   current_test_files_present:tests.every(e=>e.exists),
   direct_v12_behavior_test_attested:false,cross_repo_or_external_gate:'independent per-ID behavioral attestation pending',
   historical_code_status:req.coded?'historically_coded':null,
   historical_test_status:req.tested?'historically_tested':null,
   accepted:false,reason:'Tests are shared; existence and ID marker do not prove requirement-specific behavior'});
}
if(rows.length!==123||!idEquals(rows.map(r=>r.id),expected))fail.push('One or more of 123 rows missing or out of order');
// Execute the requirement-named static checks, rather than assuming that a
// label present in a file means the test actually ran. Distinguish this
// checkout's static contract result from a completed, independent CI run.
const exercised=new Set();
const staticEvidence=[
 {file:'qa/latest-central-contract.mjs',regex:/^PASS:\s+(F00-CEN-0[12])\b/gm,ids:['F00-CEN-01','F00-CEN-02']},
 {file:'qa/v12-current-age-guardian-runtime.php',regex:/^(F00-CEN-03) current-age\/guardian runtime 7\/7 PASS$/gm,
  ids:['F00-CEN-03']},
 {file:'qa/advanced-trust-contract.mjs',regex:/^PASS\s+EXT-(\d{3})\b/gm,
  ids:Array.from({length:20},(_,i)=>'F00-EXT-'+String(i+1).padStart(3,'0'))},
 {file:'qa/v12-r80-source-implementation.mjs',regex:/^PASS\s+(F00-R\d{3})\s+explicit implementation source/gm,
  ids:Array.from({length:80},(_,i)=>'F00-R'+String(i+11).padStart(3,'0'))},
];
for(const entry of staticEvidence){
 try{
  const runner=entry.file.endsWith('.php')?'php':process.execPath;
  const stdout=execFileSync(runner,[file(entry.file)],{cwd:root,encoding:'utf8',maxBuffer:1024*1024});
  const matches=[...stdout.matchAll(entry.regex)].map(m=>entry.file.includes('advanced-trust')?'F00-EXT-'+m[1]:m[1]);
  const passed=new Set(matches);
  for(const id of entry.ids){
   if(!passed.has(id))fail.push('Missing individually executed static assertion: '+id);
   else exercised.add(id);
  }
 }catch(e){fail.push('Static contract execution failed: '+entry.file+': '+(e.message||String(e)).slice(0,160));}
}
// Promote R011–R090 from old group-only pointers to specifically identified,
// locally checksum-verified PHP/UX implementation entrypoint evidence.
const r80Report=JSON.parse(read('build/file00-v12-r80-source.json'));
const specific=new Map((r80Report.rows||[]).map(r=>[r.id,r]));
if(specific.size!==80 || (r80Report.summary||{}).source_entrypoints_verified!==80){
 fail.push('80 per-R source implementation references not independently verified');
}
for(const row of rows){
 if(specific.has(row.id)){
  const exact=specific.get(row.id);
  row.historical_group_source_evidence=row.source_evidence;
  row.source_evidence=exact.evidence.map(e=>({
   path:e.path,exists:true,sha256:e.sha256,source_signal:e.symbol,signal_type:e.source_kind
  }));
  row.source_granularity='requirement_specific_source_entrypoints';
  row.current_source_signal_verified=exact.source_entrypoints_verified===true;
  row.individual_static_check_present=true;
 }
 row.static_contract_executed_and_passed=exercised.has(row.id);
 if(row.id==='F00-CEN-03'){
  row.individual_static_check_present=true;
  row.local_behavior_fixture_executed_passed=exercised.has(row.id);
  row.local_behavior_fixture_ref='qa/v12-current-age-guardian-runtime.php';
  row.local_behavior_fixture_scope='mock WordPress data path, not production/companion installation';
 }
 row.static_contract_evidence_source=row.static_contract_executed_and_passed
    ? (row.id.startsWith('F00-EXT')?'qa/advanced-trust-contract.mjs':row.id.startsWith('F00-R')?'qa/v12-r80-source-implementation.mjs':row.id==='F00-CEN-03'?'qa/v12-current-age-guardian-runtime.php':'qa/latest-central-contract.mjs'):null;
 row.static_contract_result_scope='checkout_only_not_independent_CI_or_behavioral_acceptance';
}
let head='unknown';
try{head=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8',stdio:['ignore','pipe','ignore']}).trim()}catch{}
const count=k=>rows.filter(r=>r[k]===true).length;
const summary={
 requirement_total:rows.length, r:100,cen:3,ext:20,
 source_signal_verified_count:count('current_source_signal_verified'),
 test_file_presence_count:count('current_test_files_present'),
 individual_behavioral_acceptances:count('accepted'),
 externally_accepted:0,
 historical_group_pointer_count:rows.filter(r=>r.source_granularity==='historical_group_pointer_only').length,
 requirement_specific_static_source_count:rows.filter(r=>r.source_granularity!=='historical_group_pointer_only'&&r.current_source_signal_verified).length,
 named_individual_static_assertion_source_count:count('individual_static_check_present'),
 requirement_named_static_checks_executed_passed:count('static_contract_executed_and_passed'),
 individual_mock_behavior_fixtures_executed_passed:count('local_behavior_fixture_executed_passed'),
 per_family_source_signals:{
   historical_R_group_pointer:rows.filter(r=>r.id.startsWith('F00-R')&&r.current_source_signal_verified).length,
   central_static_tokens:rows.filter(r=>r.id.startsWith('F00-CEN')&&r.current_source_signal_verified).length,
   advanced_extension_methods:rows.filter(r=>r.id.startsWith('F00-EXT')&&r.current_source_signal_verified).length,
 },
 static_checks_executed_result_verified:false,
 fail_closed_gate:true,
 plan_artifact:'Sabri-Website-Master-Plan-v12.docx',
 uploaded_plan_sha256:'dad203567cb5d20209ac08614955a4f15856c4708055b8fcac564c8e7473b25a',
 source_tree_head:head,
 commit_verified_by_independent_ci:false,
 source_companion_parity_verified:false,
 staging_or_live_accepted:false,
 note:'Historical coded=true and filenames are pointers, not 123 independent accepted tests. Cannot approve release from this artifact.',
};
const report={summary,rows};
if(!fs.existsSync(file('build')))fs.mkdirSync(file('build'),{recursive:true});
fs.writeFileSync(file('build/file00-v12-123-evidence.json'),JSON.stringify(report,null,2)+'\n');
console.log('File00 v12 evidence: '+rows.length+'/123 individually inventoried; source signals '+summary.source_signal_verified_count+
  '; referenced test files present '+summary.test_file_presence_count+'; individual acceptances '+summary.individual_behavioral_acceptances);
console.log('Named per-ID static checks present: '+summary.named_individual_static_assertion_source_count+
 '; CI outcomes not independently certified by this scanner.');
console.log('Requirement-named checkout static assertions executed and passed: '+summary.requirement_named_static_checks_executed_passed+'/123; separate CI and behavior pending.');
console.log('Acceptance cannot be claimed from this evidence scanner alone.');
if(fail.length){console.error(fail.join('\n'));process.exit(1)}

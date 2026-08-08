import fs from 'node:fs';
const manifest=JSON.parse(fs.readFileSync('qa/hostinger-staging-acceptance-manifest.json','utf8'));
const required=['00','01','02','03','08','09','12','17','18','19','20','21','22','23','24','25'];
const planExpected=['02','03','08','09','12','17','18','19','20','21','22','23','24','25'];
const actual=Object.keys(manifest.candidate_matrix).map(k=>k.replace(/^file/,'')).sort((a,b)=>Number(a)-Number(b));
const expected=[...required].sort((a,b)=>Number(a)-Number(b));
const fail=m=>{console.error('FAIL '+m);process.exitCode=1}; const ok=m=>console.log('PASS '+m);
JSON.stringify(actual)===JSON.stringify(expected)?ok('exact staging candidate set'):fail('candidate set mismatch '+actual.join(','));
for(const id of required){const c=manifest.candidate_matrix['file'+id];if(!c){fail('missing '+id);continue;}if(!/^[0-9a-f]{40}$/.test(c.sha||''))fail('invalid SHA '+id);if(c.required!==true)fail('not mandatory '+id);}
const plan=[...(manifest.required_file00_plan_integrations||[])].sort((a,b)=>Number(a)-Number(b));
JSON.stringify(plan)===JSON.stringify(planExpected.sort((a,b)=>Number(a)-Number(b)))?ok('plan integration set complete'):fail('plan integration set stale');
const allowed=new Set(['pending','pass','fail','blocked']);for(const [k,v] of Object.entries(manifest.gates||{})){if(!allowed.has(v))fail('invalid gate state '+k+': '+v);}
const ext=manifest.external_status||{};if(ext.staging_accepted||ext.live_deployed||ext.operational)fail('repository packet cannot self-assert external acceptance');else ok('external status evidence gated');
const f02=manifest.candidate_matrix.file02;
if(f02.governing_plan_version!=='2.3'||f02.governing_target_runtime!=='1.3.2'||f02.governing_authentication_assurance_v2_contract!=='2.0.0')fail('File02 governing evidence stale');else ok('File02 v2.3/1.3.2 governing evidence current');
if(f02.current_plan_github_exact_head_synced!==false||f02.staging_usable_for_final_integrated_acceptance!==false)fail('File02 repository freshness must fail closed');else ok('File02 current-plan blocker encoded');
if(!(manifest.mandatory_unpinned_companions||[]).includes('02-current-plan-1.3.2-exact-head'))fail('missing current-plan unpinned blocker');
if(String(manifest.candidate_pinning_status||'').startsWith('complete'))fail('false source pinning completeness');else ok('candidate pinning truthfully partial/blocked');
if(manifest.package_pinning_status==='complete'){for(const id of required){if(!manifest.candidate_matrix['file'+id].package_sha256)fail('false package-complete '+id);}}else ok('partial package pinning represented truthfully');
if(!/^blocked_/.test(manifest.preflight_status||''))fail('preflight must remain blocked');
const doc=`docs/HOSTINGER-STAGING-ACCEPTANCE-${manifest.release}.md`;
if(!fs.existsSync(doc))fail('release-matched staging doc missing');
if(process.exitCode)process.exit(1);

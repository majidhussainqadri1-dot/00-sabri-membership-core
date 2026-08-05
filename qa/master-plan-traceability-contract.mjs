import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const failures = [];
let passed = 0;
const assert = (condition, label) => { if (condition) passed += 1; else failures.push(label); };
const data = JSON.parse(read('qa/requirements-traceability.json'));
assert(data.platform_master_plan.sha256 === 'bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0', 'Platform plan checksum');
assert(data.file00_master_plan.sha256 === '3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d', 'File 00 plan checksum');
assert(data.all_chats_recovered_directives?.version === '2.1', 'All-Chats directives v2.1 registered');
assert(data.plugin_version === '1.2.11', 'Registry version 1.2.11');
assert(data.contract_version === '1.2.0' && data.database_version === '1.3.0', 'Contract/schema identity');
assert(Array.isArray(data.requirements) && data.requirements.length === 100, '100 requirements');
const expected = Array.from({length:100}, (_,i)=>`F00-R${String(i+1).padStart(3,'0')}`);
assert(JSON.stringify(data.requirements.map((r)=>r.id)) === JSON.stringify(expected), 'Contiguous stable IDs');
assert(data.requirements.every((r)=>r.code_status === 'complete'), 'Repository obligations mapped complete');
assert(data.requirements.every((r)=>typeof r.acceptance_status === 'string' && r.acceptance_status), 'Acceptance status explicit');
assert(data.requirements[98].acceptance_status === 'hostinger_staging_acceptance_pending', 'R099 staging pending');
assert(data.requirements[99].acceptance_status === 'founder_production_approval_pending', 'R100 Founder approval pending');
assert(data.three_plan_completion?.release === '1.2.11', 'Three-plan completion record');
assert(fs.existsSync(path.join(root, data.three_plan_completion.record)), 'Three-plan completion record exists');
assert(fs.existsSync(path.join(root, data.three_plan_completion.runtime_contract)), 'Three-plan runtime contract exists');
assert(fs.existsSync(path.join(root, data.three_plan_completion.runtime_test)), 'Three-plan runtime test exists');
assert(data.staging_accepted === false && data.production_approved === false && data.live_installation_authorized === false, 'External gates not overclaimed');
const evidence = data.runtime_evidence || {};
if (evidence.conclusion === 'success') {
  const verifiedHead = evidence.verified_source_head || evidence.head_sha || '';
  const verifiedRun = evidence.three_plan_workflow_run_id || evidence.workflow_run_id || 0;
  assert(/^[0-9a-f]{40}$/.test(verifiedHead), 'Verified source head SHA');
  assert(Number.isInteger(verifiedRun) && verifiedRun > 0, 'Verified three-plan workflow run');
  assert(Number.isInteger(evidence.contract_workflow_run_id) && evidence.contract_workflow_run_id > 0, 'Verified contract workflow run');
  assert(/^[0-9a-f]{64}$/.test(evidence.package_sha256), 'Verified package checksum');
  assert(Number.isInteger(evidence.artifact_id) && evidence.artifact_id > 0, 'Verified artifact ID');
} else {
  assert(evidence.conclusion === 'pending', 'Pre-CI evidence pending');
  assert((evidence.head_sha ?? '') === '' && (evidence.workflow_run_id ?? 0) === 0 && evidence.package_sha256 === '', 'Pending evidence blank');
}
const plugin = read('source/sabri-membership-core/sabri-membership-core.php');
assert(plugin.includes('Version: 1.2.11') && plugin.includes("define( 'SMC_DB_VERSION', '1.3.0' )"), 'Runtime identity');
const master = read('docs/FILE-00-MASTER-PLAN-2026.md');
assert(master.includes('Runtime implementation release: `1.2.11`'), 'Master index current');
const human = read('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.11.md');
assert((human.match(/\| F00-R\d{3} \|/g) || []).length === 100, 'Human matrix 100 rows');
assert(read('package.json').includes('three-plan-runtime-completion-contract.mjs'), 'Package test includes executable three-plan contract');
if (failures.length) {
  console.error(`master-plan traceability contract: ${passed} PASS, ${failures.length} FAIL`);
  failures.forEach((failure)=>console.error(`- ${failure}`));
  process.exit(1);
}
console.log(`master-plan traceability contract: ${passed} PASS, 0 FAIL`);

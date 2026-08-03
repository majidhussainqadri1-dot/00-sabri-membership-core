import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const data = JSON.parse(read('qa/requirements-traceability.json'));
const failures = [];
let passed = 0;
function assert(condition, label) { if (condition) passed += 1; else failures.push(label); }
assert(data.platform_master_plan.sha256 === 'bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0', 'Platform plan checksum');
assert(data.file00_master_plan.sha256 === '3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d', 'File 00 plan checksum');
assert(data.plugin_version === '1.2.8', 'Registry current version 1.2.8');
assert(data.contract_version === '1.1.2' && data.database_version === '1.2.0', 'Contract/schema compatibility');
assert(Array.isArray(data.requirements) && data.requirements.length === 100, '100 explicit requirements');
const expected = Array.from({length:100}, (_,i)=>`F00-R${String(i+1).padStart(3,'0')}`);
assert(JSON.stringify(data.requirements.map(r=>r.id)) === JSON.stringify(expected), 'Contiguous requirement IDs');
assert(data.requirements.every(r=>r.code_status === 'complete'), 'All repository code obligations complete');
assert(data.requirements.every(r=>typeof r.acceptance_status === 'string' && r.acceptance_status), 'Every requirement has acceptance status');
assert(data.requirements[98].acceptance_status === 'hostinger_staging_acceptance_pending', 'R099 staging remains pending');
assert(data.requirements[99].acceptance_status === 'founder_production_approval_pending', 'R100 Founder approval remains pending');
assert(data.forty_round_review && data.forty_round_review.rounds === 40, 'Forty-round registry exists');
assert(data.forty_round_review.ledger === 'docs/FORTY-ROUND-REVIEW-1.2.8.md', 'Forty-round ledger path');
assert(data.staging_accepted === false && data.production_approved === false && data.live_installation_authorized === false, 'External gates are not overclaimed');
const evidence = data.runtime_evidence || {};
if (evidence.conclusion === 'success') {
  assert(/^[0-9a-f]{40}$/.test(evidence.head_sha), 'Verified head SHA');
  assert(Number.isInteger(evidence.workflow_run_id) && evidence.workflow_run_id > 0, 'Verified workflow run');
  assert(/^[0-9a-f]{64}$/.test(evidence.package_sha256), 'Verified package checksum');
} else {
  assert(evidence.conclusion === 'pending', 'Pre-CI evidence is truthfully pending');
  assert(evidence.head_sha === '' && evidence.workflow_run_id === 0 && evidence.package_sha256 === '', 'Pending evidence is blank');
}
const plugin = read('source/sabri-membership-core/sabri-membership-core.php');
assert(plugin.includes('Version: 1.2.8') && plugin.includes("define( 'SMC_VERSION', '1.2.8' )"), 'Runtime source 1.2.8');
const master = read('docs/FILE-00-MASTER-PLAN-2026.md');
assert(master.includes('Runtime implementation release: `1.2.8`'), 'Master index 1.2.8');
const human = read('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.8.md');
assert((human.match(/\| F00-R\d{3} \|/g) || []).length === 100, 'Human matrix has 100 rows');
assert(fs.existsSync(path.join(root,'docs/FORTY-ROUND-REVIEW-1.2.8.md')), 'Forty-round ledger exists');
assert(fs.existsSync(path.join(root,'qa/forty-round-contract.mjs')), 'Forty-round contract exists');
if (failures.length) {
  console.error(`master-plan traceability contract: ${passed} PASS, ${failures.length} FAIL`);
  failures.forEach(f=>console.error(`- ${f}`));
  process.exit(1);
}
console.log(`master-plan traceability contract: ${passed} PASS, 0 FAIL`);

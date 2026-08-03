import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const registryPath = path.join(root, 'qa', 'requirements-traceability.json');
const masterIndexPath = path.join(root, 'docs', 'FILE-00-MASTER-PLAN-2026.md');
const humanPath = path.join(root, 'docs', 'FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.7.md');
const completionPath = path.join(root, 'docs', 'FINAL-PLAN-COMPLETION-1.2.7.md');
const finalWorkflowPath = path.join(root, '.github', 'workflows', 'file00-final-dual-plan-qa.yml');
const obsoleteWorkflowPath = path.join(root, '.github', 'workflows', 'file00-1.2.1-qa.yml');
const readmePath = path.join(root, 'README.md');
const statusPath = path.join(root, 'STATUS.md');
const runtimePath = path.join(root, 'source', 'sabri-membership-core', 'sabri-membership-core.php');
const packagePath = path.join(root, 'package.json');
const failures = [];
let passed = 0;
function assert(condition, name) { if (condition) passed += 1; else failures.push(name); }

for (const p of [registryPath, masterIndexPath, humanPath, completionPath, finalWorkflowPath, readmePath, statusPath, runtimePath, packagePath]) {
  assert(fs.existsSync(p), `${path.relative(root, p)} exists`);
}
assert(!fs.existsSync(obsoleteWorkflowPath), 'Obsolete 1.2.1-named workflow is absent');
const data = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
assert(data.platform_master_plan.sha256 === 'bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0', 'Platform master-plan checksum is exact');
assert(data.file00_master_plan.sha256 === '3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d', 'File 00 master-plan checksum is exact');
assert(data.plugin_version === '1.2.7', 'Registry plugin version is 1.2.7');
assert(data.contract_version === '1.1.2', 'Contract version remains 1.1.2');
assert(data.database_version === '1.2.0', 'Database version remains 1.2.0');
assert(data.runtime_evidence.workflow_run_id === 30828349841 && data.runtime_evidence.conclusion === 'success', 'Exact runtime CI evidence is recorded');
assert(data.runtime_evidence.head_sha === '0434d79e65eeca336833f102ad03c1453f2205dd', 'Exact runtime head is recorded');
assert(data.runtime_evidence.merge_sha === 'ebc66a3782ee846437fe14628dfe7b2a9bc31671', 'Main runtime merge is recorded');
assert(data.runtime_evidence.package_sha256 === '2383aa9dcf79ddad9da29ec7bbbd01e62d62185ae0fe900979b955d461c8cdb9', 'Deterministic package checksum is recorded');
assert(data.repository_code_completion_percent === 100, 'Repository code completion is 100 percent');
assert(data.repository_known_unresolved_defects === 0, 'No known unresolved repository defects are recorded');
assert(data.staging_installation_candidate === true, 'Release is a staging installation candidate');
assert(data.staging_accepted === false && data.production_approved === false && data.live_installation_authorized === false, 'External acceptance is not overclaimed');
assert(Array.isArray(data.requirements) && data.requirements.length === 100, 'Exactly 100 requirements are explicit');
const ids = data.requirements.map((r) => r.id);
const expectedIds = Array.from({ length: 100 }, (_, i) => `F00-R${String(i + 1).padStart(3, '0')}`);
assert(JSON.stringify(ids) === JSON.stringify(expectedIds), 'Requirement IDs are contiguous F00-R001 through F00-R100');
assert(new Set(ids).size === 100, 'Requirement IDs are unique');
assert(data.requirements.every((r) => typeof r.title === 'string' && r.title.trim()), 'Every requirement has a title');
assert(Array.isArray(data.groups) && data.groups.length === 10, 'Ten requirement groups are explicit');
assert(data.groups.every((g, i) => g.group === i + 1 && g.from === i * 10 + 1 && g.to === i * 10 + 10), 'Group ranges are exact and contiguous');
assert(data.groups.every((g) => typeof g.owner === 'string' && g.owner.trim()), 'Every group has an owner');
assert(data.groups.every((g) => typeof g.sources === 'string' && g.sources.trim()), 'Every group has sources');
assert(data.groups.every((g) => typeof g.test_class === 'string' && g.test_class.trim()), 'Every group has a test class');
assert(data.groups.every((g) => Array.isArray(g.evidence) && g.evidence.length >= 3), 'Every group has evidence paths');
assert(data.requirements.every((r) => Number.isInteger(r.group) && r.group >= 1 && r.group <= 10), 'Every requirement resolves to a canonical group');
assert(data.requirements.every((r, i) => r.group === Math.floor(i / 10) + 1), 'Every requirement inherits the correct owner/source/test/evidence group');
assert(data.requirements.every((r) => r.code_status === 'complete'), 'All 100 repository code obligations are complete');
assert(data.requirements.every((r) => typeof r.acceptance_status === 'string' && r.acceptance_status.trim()), 'Every requirement has an acceptance status');
for (const group of data.groups) {
  for (const evidence of group.evidence) {
    assert(fs.existsSync(path.join(root, evidence)), `Group ${group.group} evidence exists: ${evidence}`);
  }
}
assert(data.requirements[98].acceptance_status === 'hostinger_staging_acceptance_pending', 'R099 remains a truthful staging gate');
assert(data.requirements[99].acceptance_status === 'founder_production_approval_pending', 'R100 remains a truthful Founder approval gate');
const masterIndex = fs.readFileSync(masterIndexPath, 'utf8');
assert(masterIndex.includes('Runtime implementation release: `1.2.7`'), 'Master-plan index is reconciled to runtime 1.2.7');
assert(masterIndex.includes(data.platform_master_plan.sha256) && masterIndex.includes(data.file00_master_plan.sha256), 'Master-plan index records both exact governing checksums');
assert(masterIndex.includes('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.7.md'), 'Master-plan index points to current traceability');
assert(!masterIndex.includes('Runtime audit release: `1.2.4`') && !masterIndex.includes('IMPLEMENTATION-TRACEABILITY-1.2.4'), 'Master-plan index has no obsolete 1.2.4 current-state identity');
const human = fs.readFileSync(humanPath, 'utf8');
assert((human.match(/\| F00-R\d{3} \|/g) || []).length === 100, 'Human traceability contains 100 requirement rows');
assert(human.includes('Repository code completion: **100%**'), 'Human traceability records repository completion');
const completion = fs.readFileSync(completionPath, 'utf8');
assert(completion.includes('post-merge evidence audit found stale 1.2.5'), 'First final evidence defect and correction are recorded');
assert(completion.includes('Fresh evidence round 2') && completion.includes('file00-1.2.1-qa.yml'), 'Second fresh evidence review and workflow correction are recorded');
const workflow = fs.readFileSync(finalWorkflowPath, 'utf8');
assert(workflow.includes('name: File 00 Version 1.2.7 Final Dual-Plan QA'), 'Final workflow has current release identity');
assert(workflow.includes('file00-final-dual-plan-qa.yml') && !workflow.includes('dist/00-sabri-membership-core-1.2.5.zip'), 'Final workflow enforces current path and package identity');
const readme = fs.readFileSync(readmePath, 'utf8');
const status = fs.readFileSync(statusPath, 'utf8');
const runtime = fs.readFileSync(runtimePath, 'utf8');
const pkg = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
assert(readme.includes('Version: `1.2.7`') && !readme.includes('Current corrective release\n\n- Version: `1.2.5`'), 'README is reconciled to 1.2.7');
assert(status.includes('File 00 1.2.7') && !status.includes('Completed in 1.2.5'), 'STATUS is reconciled to 1.2.7');
assert(runtime.includes('Version: 1.2.7') && runtime.includes("define( 'SMC_VERSION', '1.2.7' )"), 'Runtime is 1.2.7');
assert(pkg.version === '1.2.7' && pkg.scripts.test.includes('master-plan-traceability-contract.mjs'), 'Package QA includes the strengthened plan contract');

if (failures.length) {
  console.error(`master-plan traceability contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log(`master-plan traceability contract: ${passed} PASS, 0 FAIL`);

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const registryPath = path.join(root, 'qa', 'requirements-traceability.json');
const humanPath = path.join(root, 'docs', 'FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.5.md');
const expectedMasterPlanSha = '3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d';
const failures = [];
let passed = 0;
function assert(condition, name) { if (condition) passed += 1; else failures.push(name); }

assert(fs.existsSync(registryPath), 'Machine-readable traceability exists');
const data = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
assert(data.master_plan_sha256 === expectedMasterPlanSha, 'Registry records the exact DOCX checksum');
assert(data.master_plan_artifact.endsWith('Four-Round-Reviewed-Final.docx'), 'Registry names the normative project artifact');
assert(data.plugin_version === '1.2.5', 'Registry plugin version is 1.2.5');
assert(data.contract_version === '1.1.2', 'Registry contract version remains 1.1.2');
assert(data.database_version === '1.2.0', 'Database version remains 1.2.0');
assert(data.github_actions_verified === false, 'Pre-CI registry does not claim verification');
assert(data.verified_correction_head_sha === '' && data.verified_pr_merge_ref_sha === '' && data.merged_main_sha === '', 'Unknown commit evidence remains blank');
assert(data.workflow_run_id === 0 && data.workflow_job_id === 0, 'Unknown workflow evidence remains zero');
assert(data.package_sha256 === '', 'Unknown package checksum remains blank');
assert(data.staging_installation_candidate === false, 'Pre-CI source is not yet a staging candidate');
assert(data.staging_accepted === false, 'Registry does not overclaim staging acceptance');
assert(data.production_approved === false, 'Registry does not overclaim production approval');
assert(data.ids.prefix === 'F00-R' && data.ids.from === 1 && data.ids.to === 100, 'Canonical ID range is F00-R001 through F00-R100');
assert(Array.isArray(data.groups) && data.groups.length === 10, 'Ten canonical requirement groups are recorded');
assert(typeof data.status_codes === 'string' && data.status_codes.length === 100, 'Exactly 100 requirement statuses are encoded');
assert(/^[CIGP]{100}$/.test(data.status_codes), 'Only approved status codes are used');
assert(Object.keys(data.status_legend).sort().join('') === 'CGIP', 'Status legend is complete');
assert(data.status_legend.C === 'corrected-1.2.5-ci-pending', 'Corrected requirements accurately say CI pending');
assert(data.status_codes[99] === 'P', 'Definition of Done remains pending environment acceptance');
const human = fs.readFileSync(humanPath, 'utf8');
assert(human.includes('R001') && human.includes('R100'), 'Human map spans R001 through R100');
assert(human.includes(expectedMasterPlanSha), 'Human map records the exact DOCX checksum');
assert(human.includes('Exact-head CI: **Pending**'), 'Human map does not overclaim CI');
assert(human.includes('Professional dual-review votes persist'), 'Human map records completion hardening');

if (failures.length) {
  console.error(`master-plan traceability contract: ${passed} PASS, ${failures.length} FAIL`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log(`master-plan traceability contract: ${passed} PASS, 0 FAIL`);

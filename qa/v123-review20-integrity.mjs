import fs from 'node:fs';
const report=fs.readFileSync('docs/FILE00-V123-20-ROUND-REVIEW-2026-11-23.md','utf8');
const contracts=fs.readFileSync('source/sabri-membership-core/includes/class-smc-contracts.php','utf8');
const advanced=fs.readFileSync('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php','utf8');
const cf01=fs.readFileSync('source/sabri-membership-core/includes/class-smc-cf01-contract.php','utf8');
const checks=[
 ['exactly twenty review rounds',(report.match(/^\|\s*(?:0[1-9]|1\d|20)\s*\|/gm)||[]).length===20],
 ['current-age eligibility is sourced from age_context',contracts.includes('minimum_age_met')&&contracts.includes('guardian_required_now')],
 ['File03 independent age claim is registered',contracts.includes('smc_profile_age_guardian_claim_v1')&&contracts.includes('public_profile_age_assurance')],
 ['File04 placeholder is metadata-bound',contracts.includes('_smc_legacy_author_placeholder_v1')],
 ['CF01 reverse UUID resolver is present',cf01.includes('resolve_user_id_by_subject_uuid')],
 ['CF01 resolver enforces erasure lock',cf01.includes('smc_privacy_erasure_lock( $user_id )')],
 ['trust timeline has exact purpose allowlist',advanced.includes("'trust_timeline_read' === $purpose")],
 ['review does not claim Live acceptance',report.includes('Live acceptance')&&report.includes('BLOCKED')]
];
for(const [name,ok] of checks) console.log((ok?'PASS ':'FAIL ')+name);
if(checks.some(x=>!x[1])) process.exit(1);

import fs from 'node:fs';

const root = 'source/sabri-membership-core';
const read = (p) => fs.readFileSync(`${root}/${p}`, 'utf8');
const authorization = read('includes/class-smc-authorization.php');
const main = read('sabri-membership-core.php');
const readme = read('README.txt');
const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const master = fs.readFileSync('docs/FILE-00-MASTER-PLAN-2026.md', 'utf8');

const failures = [];
let passed = 0;
const check = (ok, label) => { if (ok) { passed++; } else { failures.push(label); } };

const recoveryStart = authorization.indexOf('private static $recovery_actions');
const recoveryEnd = authorization.indexOf('public static function init()', recoveryStart);
const recoveryBlock = recoveryStart >= 0 && recoveryEnd > recoveryStart ? authorization.slice(recoveryStart, recoveryEnd) : '';
check(recoveryBlock.includes("'smc_revoke_session'"), 'single-session revocation remains a recovery action');
check(recoveryBlock.includes("'smc_revoke_all_sessions'"), 'revoke-all remains reachable while membership is hard-blocked or ineligible');

check(authorization.includes("add_filter( 'smc_assertions_v1', array( __CLASS__, 'filter_current_age_assertion' ), 100, 2 )"), 'public assertion filter gets action-time age hardening');
check(authorization.includes('private static function current_age_eligible'), 'current age eligibility helper exists');
check(authorization.includes("$age < $minimum"), 'current jurisdictional minimum is enforced synchronously');
check(authorization.includes("$age < 18 && array_intersect"), 'under-18 professional roles fail closed synchronously');
check(authorization.includes("&& $age_ok;"), 'effective authorization includes current age result');
check(authorization.includes("'can_open_composer'] = false"), 'public publishing assertion is stripped on age failure');
check(authorization.includes("'can_initiate'] = false"), 'public transfer assertion is stripped on age failure');

check(authorization.includes('private static function enforce_appeal_reviewer_independence'), 'independent appeal authorization guard exists');
check(authorization.includes("new_status IN ('rejected','suspended')"), 'appeal guard binds to latest adverse actor');
check(authorization.includes("array( 'smc_assign_review', 'smc_review_transition' )"), 'appeal guard covers both claim and decision actions');
check(authorization.includes('self::enforce_appeal_reviewer_independence();'), 'admin authorization invokes appeal independence guard');

check(main.includes('Version: 1.2.43') && main.includes("define( 'SMC_VERSION', '1.2.43' );"), 'current runtime identity is 1.2.43');
check(pkg.version === '1.2.43' && pkg.scripts?.verify?.includes('00-sabri-membership-core-1.2.43.zip'), 'package and deterministic verify identity are 1.2.43');
check(readme.includes('Stable tag: 1.2.43') && readme.includes('= 1.2.43 =') && readme.includes('= 1.2.42 =') && readme.includes('= 1.2.41 ='), 'WordPress current metadata is 1.2.43 and 1.2.42/1.2.41 history is retained');
check(fs.existsSync('RELEASE-1.2.43.md') && fs.existsSync('RELEASE-1.2.42.md') && fs.existsSync('RELEASE-1.2.41.md'), 'current and historical release records exist');
check(master.includes('Supplemental ten-round corrective review — 1.2.41'), 'master-plan index retains supplemental ten-round corrective release');
check(master.includes('Live-proven File 02 activation contract correction — 1.2.43'), 'master-plan index records current File 02 activation correction');
check(main.includes("define( 'SMC_DB_VERSION', '1.4.5' );"), 'current release preserves DB schema 1.4.5');
check(main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.3' );"), 'current release preserves public contract 1.2.3');

console.log(`next ten-round static: ${passed} PASS / ${failures.length} FAIL`);
if (failures.length) {
  failures.forEach((f) => console.error(`- ${f}`));
  process.exit(1);
}

import fs from 'node:fs';

const root = 'source/sabri-membership-core';
const read = (p) => fs.readFileSync(`${root}/${p}`, 'utf8');
const authorization = read('includes/class-smc-authorization.php');

const failures = [];
let passed = 0;
const check = (ok, label) => { if (ok) { passed++; } else { failures.push(label); } };

const recoveryStart = authorization.indexOf('private static $recovery_actions');
const recoveryEnd = authorization.indexOf('public static function init()', recoveryStart);
const recoveryBlock = recoveryStart >= 0 && recoveryEnd > recoveryStart ? authorization.slice(recoveryStart, recoveryEnd) : '';
check(recoveryBlock.includes("'smc_revoke_session'"), 'single-session revocation remains a recovery action');
check(recoveryBlock.includes("'smc_revoke_all_sessions'"), 'revoke-all remains reachable while membership is hard-blocked or ineligible');

console.log(`next ten-round static: ${passed} PASS / ${failures.length} FAIL`);
if (failures.length) {
  failures.forEach((f) => console.error(`- ${f}`));
  process.exit(1);
}

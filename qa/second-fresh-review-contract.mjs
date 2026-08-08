import fs from 'node:fs';
let failed=0; const pass=(n,c)=>{console.log(`${c?'PASS':'FAIL'} ${n}`); if(!c)failed++;};
const auth=fs.readFileSync('source/sabri-membership-core/includes/class-smc-authorization.php','utf8');
const completion=fs.readFileSync('source/sabri-membership-core/includes/class-smc-completion.php','utf8');
pass('restricted capability baseline cannot be removed by filter', auth.includes('array_merge( self::$restricted_caps, $filtered )'));
pass('hard-block baseline is unioned back after filtering', auth.includes('array_merge( $baseline, $filtered )'));
for (const state of ['rejected','suspended','expired','appeal_review','erasure_pending','invalid_application']) pass(`mandatory hard block retained: ${state}`, auth.includes(`'${state}'`));
pass('safe-mode declaration cannot be filtered off', completion.includes('return $declared || $filtered;'));
console.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);

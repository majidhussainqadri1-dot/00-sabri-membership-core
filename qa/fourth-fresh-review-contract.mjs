import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const auth=read('source/sabri-membership-core/includes/class-smc-authorization.php');
const fn=read('source/sabri-membership-core/includes/functions.php');
const sec=read('source/sabri-membership-core/includes/class-smc-security.php');
const adv=read('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php');
const main=read('source/sabri-membership-core/sabri-membership-core.php');
const readme=read('source/sabri-membership-core/README.txt');
const qa=read('qa/advanced-trust-runtime.php');
const checks=[
 ['runtime 1.2.40',main.includes('Version: 1.2.40')&&main.includes("SMC_VERSION', '1.2.40")],
 ['institutional expired hard block',fn.includes("'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending'")],
 ['recovery baseline cannot be filtered away',auth.includes('array_merge( self::$recovery_actions, $filtered )')],
 ['administrator no longer bypasses ordinary admin gate',!auth.includes('if ( $is_admin && ! $is_file00 )')],
 ['assurance profile uses opaque subject',/assurance_profile[\s\S]{0,1800}'subject'\s*=>\s*self::subject_reference/.test(adv)],
 ['assurance profile omits local user id',!/assurance_profile[\s\S]{0,1800}'user_id'\s*=>/.test(adv)],
 ['MFA provenance reads actual session time',sec.includes('public static function session_verified_at')&&sec.includes('SELECT two_factor_at FROM')&&adv.includes('SMC_Security::session_verified_at')],
 ['new encryption key ID documented',readme.includes('define SMC_MASTER_KEY with at least 256 bits of entropy and SMC_MASTER_KEY_ID as a stable non-secret key identifier')],
 ['advanced runtime fixture preserves prior 1.2.34 compatibility',qa.includes("define('SMC_VERSION', '1.2.34');")],
 ['historical release lineage retained through retirement boundary',readme.includes('= 1.2.34 =')&&readme.includes('= 1.2.33 =')&&readme.includes('superseded by the Founder-approved File 00 MFA retirement in 1.2.35')],
 ['public membership contract current',main.includes("SMC_CONTRACT_VERSION', '1.2.3")],
 ['advanced trust contract preserved',main.includes("SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0")],
];
let fail=0; for(const [n,ok] of checks){console.log(`${ok?'PASS':'FAIL'} ${n}`);if(!ok)fail++;}
console.log(`Fourth fresh static: ${checks.length-fail} PASS / ${fail} FAIL`); if(fail)process.exit(1);

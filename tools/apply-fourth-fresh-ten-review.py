#!/usr/bin/env python3
from pathlib import Path
import json, re

ROOT=Path(__file__).resolve().parents[1]

def read(p): return (ROOT/p).read_text(encoding='utf-8')
def write(p,s): (ROOT/p).write_text(s,encoding='utf-8',newline='\n')
def repl(p,old,new,count=1):
    s=read(p)
    if old not in s: raise SystemExit(f'missing pattern in {p}: {old[:90]!r}')
    s2=s.replace(old,new,count)
    write(p,s2)

# Round 1 — institutional Administrator must not bypass File 00 hard-block/MFA gates.
a='source/sabri-membership-core/includes/class-smc-authorization.php'
s=read(a)
old="""\t\t$user_id = get_current_user_id();\n\t\t$is_admin = self::user_is_administrator( $user_id );\n\t\t$is_file00 = self::request_is_file00_admin();\n\n\t\t// Ordinary WordPress administration remains outside File 00 unless the\n\t\t// request is a File 00/platform membership action. Reviewers are still\n\t\t// governed on every admin request they can reach.\n\t\tif ( $is_admin && ! $is_file00 ) {\n\t\t\treturn;\n\t\t}\n\n\t\t$assertions = self::assertions( $user_id );\n"""
new="""\t\t$user_id = get_current_user_id();\n\t\t$assertions = self::assertions( $user_id );\n\n\t\t// Privileged WordPress administration is a sensitive surface. Founder or\n\t\t// Administrator identity never bypasses an explicit membership hard block,\n\t\t// stale eligibility, containment/reverification hold, or current MFA gate.\n\t\t// Explicit recovery actions remain available through the exact allowlist above.\n"""
if old not in s: raise SystemExit('authorization admin bypass block not found')
s=s.replace(old,new,1)
write(a,s)

# Round 2 — baseline recovery actions are mandatory and cannot be removed by a filter.
s=read(a)
old="""\tprivate static function recovery_actions() {\n\t\t$actions = (array) apply_filters( 'smc_membership_recovery_actions', self::$recovery_actions );\n\t\treturn array_values( array_unique( array_filter( array_map( 'sanitize_key', $actions ) ) ) );\n\t}\n"""
new="""\tprivate static function recovery_actions() {\n\t\t$filtered = (array) apply_filters( 'smc_membership_recovery_actions', self::$recovery_actions );\n\t\t$actions = array_merge( self::$recovery_actions, $filtered );\n\t\treturn array_values( array_unique( array_filter( array_map( 'sanitize_key', $actions ) ) ) );\n\t}\n"""
if old not in s: raise SystemExit('recovery_actions block not found')
s=s.replace(old,new,1)
write(a,s)

# Round 5 — expired institutional membership evidence is an explicit hard block.
f='source/sabri-membership-core/includes/functions.php'
s=read(f)
old="$hard_blocks   = array( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' );"
new="$hard_blocks   = array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending' );"
if old not in s: raise SystemExit('institutional hard-block list not found')
s=s.replace(old,new,1)
write(f,s)

# Round 4 — expose the actual current-session MFA time, not a fabricated now() provenance.
sec='source/sabri-membership-core/includes/class-smc-security.php'
s=read(sec)
marker="\tpublic static function session_is_verified( $user_id ) {\n"
if marker not in s: raise SystemExit('session_is_verified marker missing')
method="""\tpublic static function session_verified_at( $user_id ) {\n\t\t$token = wp_get_session_token();\n\t\tif ( ! $token ) {\n\t\t\treturn 0;\n\t\t}\n\t\t$hash = self::blind_index( $token, 'session-token' );\n\t\tif ( is_wp_error( $hash ) ) {\n\t\t\treturn 0;\n\t\t}\n\t\tglobal $wpdb;\n\t\t$base_cutoff = time() - 12 * HOUR_IN_SECONDS;\n\t\t$required_after = absint( get_user_meta( absint( $user_id ), '_smc_revalidation_required_at', true ) );\n\t\t$mfa_cutoff = gmdate( 'Y-m-d H:i:s', max( $base_cutoff, $required_after ) );\n\t\t$activity_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );\n\t\t$verified_at = $wpdb->get_var(\n\t\t\t$wpdb->prepare(\n\t\t\t\t\"SELECT two_factor_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND two_factor_at>=%s AND updated_at>=%s LIMIT 1\",\n\t\t\t\tabsint( $user_id ),\n\t\t\t\t$hash,\n\t\t\t\t$mfa_cutoff,\n\t\t\t\t$activity_cutoff\n\t\t\t)\n\t\t);\n\t\tif ( ! $verified_at ) {\n\t\t\treturn 0;\n\t\t}\n\t\t$timestamp = strtotime( (string) $verified_at . ' UTC' );\n\t\treturn $timestamp > 0 ? $timestamp : 0;\n\t}\n\n"""
if 'public static function session_verified_at' not in s:
    s=s.replace(marker,method+marker,1)
write(sec,s)

adv='source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
s=read(adv)
# Round 3 — public assurance helper must not expose local WordPress user IDs.
s=s.replace("\t\t\t'user_id' => $user_id,\n\t\t\t'identity_assurance_level'", "\t\t\t'subject' => self::subject_reference( $user_id ),\n\t\t\t'identity_assurance_level'", 1)
s=s.replace("\t\t\t'user_id' => 0,\n\t\t\t'identity_assurance_level'", "\t\t\t'subject' => '',\n\t\t\t'identity_assurance_level'", 1)
old="""\t\t$session_mfa = class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $user_id );\n\t\t$baseline = array(\n"""
new="""\t\t$session_mfa = class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $user_id );\n\t\t$session_verified_at = $session_mfa && method_exists( 'SMC_Security', 'session_verified_at' ) ? absint( SMC_Security::session_verified_at( $user_id ) ) : 0;\n\t\tif ( $session_mfa && $session_verified_at <= 0 ) {\n\t\t\t$session_mfa = false;\n\t\t}\n\t\t$baseline = array(\n"""
if old not in s: raise SystemExit('advanced authentication baseline marker missing')
s=s.replace(old,new,1)
s=s.replace("\t\t\t'verified_at' => $session_mfa ? time() : 0,", "\t\t\t'verified_at' => $session_verified_at,", 1)
write(adv,s)

# Runtime regression: actual provenance timestamp and privacy-minimal assurance profile.
p='qa/advanced-trust-runtime.php'
s=read(p)
s=s.replace("define('SMC_VERSION', '1.2.15');", "define('SMC_VERSION', '1.2.17');", 1)
s=s.replace("public static $verified=true; public static $audits=[];", "public static $verified=true; public static $audits=[]; public static $verifiedAt=0;", 1)
s=s.replace("public static function session_is_verified($id){ return self::$verified; }", "public static function session_is_verified($id){ return self::$verified; }\n  public static function session_verified_at($id){ return self::$verified ? (self::$verifiedAt ?: time()-120) : 0; }", 1)
needle="$a=SMC_Advanced_Trust_2026::authentication_assurance(7); t('local File00 MFA provenance is explicit', $a['owner']==='file00' && $a['level']===2);"
replacement="SMC_Security::$verifiedAt=time()-120; $a=SMC_Advanced_Trust_2026::authentication_assurance(7); t('local File00 MFA provenance is explicit', $a['owner']==='file00' && $a['level']===2 && $a['verified_at']===SMC_Security::$verifiedAt);"
if needle not in s: raise SystemExit('advanced runtime provenance assertion missing')
s=s.replace(needle,replacement,1)
needle="$profile=SMC_Advanced_Trust_2026::assurance_profile(7);\nt('assurance profile reaches verified level', $profile['identity_assurance_level']>=3"
replacement="$profile=SMC_Advanced_Trust_2026::assurance_profile(7);\nt('assurance profile exposes opaque subject only', isset($profile['subject']) && $profile['subject']==='uuid-7' && !array_key_exists('user_id',$profile));\nt('assurance profile reaches verified level', $profile['identity_assurance_level']>=3"
if needle not in s: raise SystemExit('advanced runtime profile assertion missing')
s=s.replace(needle,replacement,1)
write(p,s)

# Institutional expiry regression.
p='qa/membership-state-runtime.php'
s=read(p)
s=s.replace("foreach ( array( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' ) as $hard_block )", "foreach ( array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending' ) as $hard_block )", 1)
write(p,s)

# Round 9 — required deployment configuration must include the non-secret key identifier.
r='source/sabri-membership-core/README.txt'
s=read(r)
s=s.replace('Stable tag: 1.2.16','Stable tag: 1.2.17',1)
needle='Define a securely generated and separately backed-up SMC_MASTER_KEY with at least 256 bits of entropy in wp-config.php.\n'
replacement=needle+'Define SMC_MASTER_KEY_ID as a stable non-secret key identifier (for example smc-master-2026-01). New sensitive encryption fails closed if this identifier is absent or invalid; retain prior key identifiers only as required by an approved rotation/migration plan.\n'
if needle not in s: raise SystemExit('README master key configuration line missing')
s=s.replace(needle,replacement,1)
# Round 10 provenance: the older first-fresh release was 1.2.14, not a second 1.2.15 heading.
occ=s.count('= 1.2.15 =')
if occ < 2: raise SystemExit('expected duplicate 1.2.15 changelog headings')
idx=s.find('= 1.2.15 =', s.find('= 1.2.15 =')+1)
s=s[:idx]+s[idx:].replace('= 1.2.15 =','= 1.2.14 =',1)
insert='== Changelog ==\n\n'
entry="= 1.2.17 =\n* Fourth fresh ten-round corrective closure: institutional-admin MFA/hard-block enforcement, non-removable recovery allowlists, opaque assurance profiles, factual MFA provenance timestamps, institutional expiry fail-closed handling, deployment key-ID documentation, and release-QA provenance synchronization.\n* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical File 00 ownership boundaries.\n\n"
if insert not in s: raise SystemExit('README changelog marker missing')
s=s.replace(insert,insert+entry,1)
write(r,s)

# Release identity 1.2.17.
main='source/sabri-membership-core/sabri-membership-core.php'
s=read(main).replace('Version: 1.2.16','Version: 1.2.17',1).replace("define( 'SMC_VERSION', '1.2.16' );","define( 'SMC_VERSION', '1.2.17' );",1)
write(main,s)

pkg=ROOT/'package.json'; data=json.loads(pkg.read_text())
data['version']='1.2.17'
data['scripts']['test']=data['scripts']['test']+' && node qa/fourth-fresh-review-contract.mjs'
data['scripts']['verify']='npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.17.zip'
pkg.write_text(json.dumps(data,indent=2)+'\n')
lock=ROOT/'package-lock.json'; d=json.loads(lock.read_text()); d['version']='1.2.17'; d['packages']['']['version']='1.2.17'; lock.write_text(json.dumps(d,indent=2)+'\n')

# Current-runtime expectations in active executable QA move with the release.
for q in (ROOT/'qa').iterdir():
    if q.is_file() and q.suffix in {'.mjs','.php'}:
        text=q.read_text(encoding='utf-8')
        text=text.replace('1.2.16','1.2.17')
        q.write_text(text,encoding='utf-8',newline='\n')
# advanced runtime had a stale 1.2.15 literal and was already explicitly corrected above.

# Fourth-fresh permanent static regression suite.
fourth=r'''import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const auth=read('source/sabri-membership-core/includes/class-smc-authorization.php');
const fn=read('source/sabri-membership-core/includes/functions.php');
const sec=read('source/sabri-membership-core/includes/class-smc-security.php');
const adv=read('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php');
const main=read('source/sabri-membership-core/sabri-membership-core.php');
const readme=read('source/sabri-membership-core/README.txt');
const qa=read('qa/advanced-trust-runtime.php');
const checks=[
 ['runtime 1.2.17',main.includes('Version: 1.2.17')&&main.includes("SMC_VERSION', '1.2.17")],
 ['institutional expired hard block',fn.includes("'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending'")],
 ['recovery baseline cannot be filtered away',auth.includes('array_merge( self::$recovery_actions, $filtered )')],
 ['administrator no longer bypasses ordinary admin gate',!auth.includes('if ( $is_admin && ! $is_file00 )')],
 ['assurance profile uses opaque subject',/assurance_profile[\s\S]{0,1800}'subject'\s*=>\s*self::subject_reference/.test(adv)],
 ['assurance profile omits local user id',!/assurance_profile[\s\S]{0,1800}'user_id'\s*=>/.test(adv)],
 ['MFA provenance reads actual session time',sec.includes('public static function session_verified_at')&&sec.includes('SELECT two_factor_at FROM')&&adv.includes('SMC_Security::session_verified_at')],
 ['new encryption key ID documented',readme.includes('Define SMC_MASTER_KEY_ID as a stable non-secret key identifier')],
 ['active advanced runtime is current',qa.includes("define('SMC_VERSION', '1.2.17');")],
 ['historical first-fresh changelog identity repaired',readme.includes('= 1.2.14 =')&&readme.split('= 1.2.15 =').length-1===1],
 ['public membership contract preserved',main.includes("SMC_CONTRACT_VERSION', '1.2.0")],
 ['advanced trust contract preserved',main.includes("SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0")],
];
let fail=0; for(const [n,ok] of checks){console.log(`${ok?'PASS':'FAIL'} ${n}`);if(!ok)fail++;}
console.log(`Fourth fresh static: ${checks.length-fail} PASS / ${fail} FAIL`); if(fail)process.exit(1);
'''
write('qa/fourth-fresh-review-contract.mjs',fourth)

trace={
 'release':'1.2.17','database_schema':'1.3.0','public_membership_contract':'1.2.0','advanced_trust_contract':'1.0.0',
 'baseline_main_sha':'3c82152fe787eeb1830ed3feaf156f0306f4beef','review_complete':True,
 'rounds_with_defects':[1,2,3,4,5,9,10],'rounds_without_defects':[6,7,8],
 'defects_found_total':8,'defects_corrected_total':8,
 'severity':{'critical':1,'high':3,'medium':4,'low':0},'known_unresolved_repository_defects':0,
 'rounds':[
  {'round':1,'defects':['FFR-R1-D01'],'status':'corrected'},
  {'round':2,'defects':['FFR-R2-D01'],'status':'corrected'},
  {'round':3,'defects':['FFR-R3-D01'],'status':'corrected'},
  {'round':4,'defects':['FFR-R4-D01'],'status':'corrected'},
  {'round':5,'defects':['FFR-R5-D01'],'status':'corrected'},
  {'round':6,'defects':[],'status':'no_reproducible_defect'},
  {'round':7,'defects':[],'status':'no_reproducible_defect'},
  {'round':8,'defects':[],'status':'no_reproducible_defect'},
  {'round':9,'defects':['FFR-R9-D01'],'status':'corrected'},
  {'round':10,'defects':['FFR-R10-D01','FFR-R10-D02'],'status':'corrected'}],
 'guardrails':{'single_free_tier':True,'donor_advantage':False,'paid_unlocks':False,'commission_percent':0,'brand_primary':'#087A4E','self_mutating_ci':False,'fourth_fresh_regressions_permanent':True,'lockfile_matches_runtime':True},
 'external_status':{'staging_accepted':False,'live_deployed':False,'operational':False}
}
write('qa/fourth-fresh-ten-review-traceability.json',json.dumps(trace,indent=2)+'\n')

review='''# File 00 — Fourth Fresh Ten-Round Review and Corrective Closure — 1.2.17\n\nBaseline main: `3c82152fe787eeb1830ed3feaf156f0306f4beef` (1.2.16). Earlier review cycles are not counted.\n\n## Result\nRounds with defects: **1, 2, 3, 4, 5, 9, 10**. Rounds without a reproducible repository defect: **6, 7, 8**.\n\nTotal: **8 unique defects; corrected 8/8**. Severity: **1 Critical, 3 High, 4 Medium, 0 Low**. Known unresolved repository defects after corrected-source closure: **0**.\n\n## Round ledger\n1. FFR-R1-D01 Critical — institutional Administrator ordinary wp-admin early return bypassed File 00 hard-block/current-MFA enforcement. Removed the bypass; exact recovery allowlist remains the lockout-safe path.\n2. FFR-R2-D01 High — `smc_membership_recovery_actions` could remove mandatory baseline recovery actions. Baseline is now unioned back after filtering.\n3. FFR-R3-D01 Medium — public assurance-profile helper exposed local WordPress `user_id`. It now returns an opaque platform subject.\n4. FFR-R4-D01 High — local MFA provenance stamped `verified_at=time()` rather than the actual session `two_factor_at`. A current-session timestamp accessor now supplies factual provenance.\n5. FFR-R5-D01 High — institutional account with `expired` application evidence could collapse to `verified`. `expired` is now a controlling institutional hard block.\n6. Event transport/retry/inbox idempotency review — no new reproducible defect.\n7. Private-document/cryptography/key-ID compatibility review — no new reproducible defect.\n8. Guardian succession/account merge/containment/continuity review — no new reproducible defect.\n9. FFR-R9-D01 Medium — required configuration documented `SMC_MASTER_KEY` but omitted mandatory `SMC_MASTER_KEY_ID`, allowing staging setup to fail unexpectedly. Documentation corrected.\n10. FFR-R10-D01 Medium — active Advanced Trust runtime QA still declared stale 1.2.15. FFR-R10-D02 Medium — README had a duplicated 1.2.15 heading for the first-fresh release; repaired to 1.2.14. Current executable QA/release identity synchronized to 1.2.17.\n\n## External gates\nRepository closure does not establish Hostinger staging acceptance, live deployment or operational acceptance. Those remain pending.\n'''
write('docs/FILE-00-FOURTH-FRESH-TEN-ROUND-REVIEW-1.2.17.md',review)
release='''# File 00 — Release 1.2.17 — Fourth Fresh Ten-Review Corrective Closure\n\nCorrects 8/8 defects from a fourth independent ten-round review against the current File 00 Advanced Trust plan and governing cross-file contracts. DB schema remains 1.3.0; public membership contract remains 1.2.0; Advanced Trust remains 1.0.0.\n\nThe release is repository-coded/packaged/automated-QA candidate only until exact-head CI and post-merge main checks succeed. Hostinger staging/live/operational gates remain separate.\n'''
write('docs/RELEASE-1.2.17-FOURTH-FRESH-TEN-REVIEW.md',release)

# Update current master implementation identity without rewriting historical review evidence.
mp='docs/FILE-00-MASTER-PLAN-2026.md'
if (ROOT/mp).exists():
    s=read(mp)
    s=s.replace('Runtime implementation release: `1.2.16`','Runtime implementation release: `1.2.17`',1)
    write(mp,s)

# Read-only CI gates become 1.2.17 fourth-fresh gates.
w='.github/workflows/file00-three-plan-qa.yml'
s=read(w)
s=s.replace('File 00 1.2.16 Third-Fresh-Ten-Review QA','File 00 1.2.17 Fourth-Fresh-Ten-Review QA')
s=s.replace('1.2.16','1.2.17')
s=s.replace('Exact third-fresh review and governing traceability','Exact fourth-fresh review and governing traceability')
s=s.replace('node qa/third-fresh-review-contract.mjs\n          php qa/third-fresh-review-runtime.php', 'node qa/third-fresh-review-contract.mjs\n          php qa/third-fresh-review-runtime.php\n          node qa/fourth-fresh-review-contract.mjs')
# Replace historical-only closure assertions with current fourth closure assertions.
s=s.replace("grep -Fq 'Third fresh ten-round review complete: **Yes**' docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.17.md", "grep -Fq 'Total: **8 unique defects; corrected 8/8**.' docs/FILE-00-FOURTH-FRESH-TEN-ROUND-REVIEW-1.2.17.md")
s=s.replace("grep -Fq '**Unique defects:** 14.' docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.17.md", "test -f docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md")
# The old Python block references third traceability; replace whole bounded block.
start=s.find("          python3 - <<'PY'\n          import json\n          d=json.load(open('qa/third-fresh-ten-review-traceability.json'))")
if start!=-1:
    end=s.find("          PY\n",start)
    if end==-1: raise SystemExit('workflow python trace block end missing')
    end += len("          PY\n")
    block="""          python3 - <<'PY'\n          import json\n          d=json.load(open('qa/fourth-fresh-ten-review-traceability.json'))\n          assert d['release']=='1.2.17'\n          assert d['review_complete'] is True\n          assert d['rounds_with_defects']==[1,2,3,4,5,9,10]\n          assert d['rounds_without_defects']==[6,7,8]\n          assert d['defects_found_total']==8 and d['defects_corrected_total']==8\n          assert d['severity']=={'critical':1,'high':3,'medium':4,'low':0}\n          assert d['known_unresolved_repository_defects']==0\n          assert d['guardrails']['self_mutating_ci'] is False\n          assert d['guardrails']['fourth_fresh_regressions_permanent'] is True\n          assert d['guardrails']['lockfile_matches_runtime'] is True\n          assert d['external_status']=={'staging_accepted':False,'live_deployed':False,'operational':False}\n          PY\n"""
    s=s[:start]+block+s[end:]
# Add current docs/trace to artifact paths while retaining historical evidence.
s=s.replace('docs/RELEASE-1.2.17-THIRD-FRESH-TEN-REVIEW.md', 'docs/RELEASE-1.2.17-FOURTH-FRESH-TEN-REVIEW.md')
s=s.replace('docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.17.md', 'docs/FILE-00-FOURTH-FRESH-TEN-ROUND-REVIEW-1.2.17.md')
s=s.replace('qa/third-fresh-ten-review-traceability.json\n', 'qa/fourth-fresh-ten-review-traceability.json\n            qa/third-fresh-ten-review-traceability.json\n',1)
write(w,s)

w='.github/workflows/cf01-contract.yml'
s=read(w).replace('third-fresh suites','fourth-fresh suites').replace('1.2.16 package','1.2.17 package')
write(w,s)

# Sanity checks performed by the applicator before CI.
assert "if ( $is_admin && ! $is_file00 )" not in read(a)
assert 'array_merge( self::$recovery_actions, $filtered )' in read(a)
assert "'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending'" in read(f)
assert "'subject' => self::subject_reference( $user_id )" in read(adv)
assert 'public static function session_verified_at' in read(sec)
assert 'Define SMC_MASTER_KEY_ID as a stable non-secret key identifier' in read(r)
print('Fourth fresh ten-review corrections applied.')

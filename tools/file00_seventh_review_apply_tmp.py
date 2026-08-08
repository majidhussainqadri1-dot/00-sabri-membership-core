#!/usr/bin/env python3
from pathlib import Path
import hashlib, json, os, re, subprocess, textwrap

ROOT=Path.cwd()
ADV=ROOT/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
MAIN=ROOT/'source/sabri-membership-core/sabri-membership-core.php'
README=ROOT/'source/sabri-membership-core/README.txt'
MANIFEST=ROOT/'qa/hostinger-staging-acceptance-manifest.json'
STAGE_QA=ROOT/'qa/hostinger-staging-acceptance-contract.mjs'
ADV_QA=ROOT/'qa/advanced-trust-runtime.php'
V2_QA=ROOT/'qa/file02-auth-assurance-v2-runtime.php'
PKG=ROOT/'package.json'; LOCK=ROOT/'package-lock.json'
BRANCH='codex/file00-seventh-fresh-ten-review-1.2.20'
BASE_119='c0d04a67fb2c24319d34defc23d534e0546445e0'
PRIOR_ZIP='8d79c0a1fc67d7b9f8600da52f7323811739a05c1c3793eacbcdea446214b616'
PRIOR_ARTIFACT=9022343881
PRIOR_WRAPPER='014876db2b7a13671bf32de81841e5d3b7341de107c0ba96467f9e40f09949ab'
FILE02_LOCAL_ZIP='fe995c75e017909d15a118cb04b8c6570a7138406183119025add699178016a1'

def run(*args, check=True):
    print('+',' '.join(map(str,args)),flush=True)
    return subprocess.run(list(map(str,args)), cwd=ROOT, check=check, text=True)

def commit(msg, paths=None):
    if paths:
        run('git','add','--',*map(str,paths))
    else:
        run('git','add','-A')
    status=subprocess.check_output(['git','status','--porcelain'], cwd=ROOT, text=True).strip()
    if not status:
        raise SystemExit('No changes for commit: '+msg)
    run('git','commit','-m',msg)

def replace_once(path, old, new, label):
    s=path.read_text(encoding='utf-8')
    if old not in s:
        raise SystemExit(f'{label}: marker not found')
    path.write_text(s.replace(old,new,1),encoding='utf-8')

def sha256(path):
    h=hashlib.sha256();
    with path.open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
    return h.hexdigest()

# ---------------- ROUND 1 ----------------
# Defect: staging ledger described an older main artifact/package as current while the
# sixth-review exact candidate already existed.
m=json.loads(MANIFEST.read_text(encoding='utf-8'))
m['exact_main_artifact']['role']='historical main baseline only; not the current candidate artifact'
m['prior_exact_candidate_artifact']={
    'release':'1.2.19','head_sha':BASE_119,'id':PRIOR_ARTIFACT,
    'wrapper_sha256':PRIOR_WRAPPER,'plugin_zip_sha256':PRIOR_ZIP,
    'role':'immutable sixth-review exact-head baseline before the seventh fresh review'
}
f00=m['candidate_matrix']['file00']
f00.update({'ref':BRANCH,'sha':BASE_119,'version':'1.2.19','sha_role':'immutable sixth-review 1.2.19 exact-head baseline; seventh-review final head is external CI/PR evidence'})
m['staging_ledger_review']='seventh_fresh_review_in_progress'
MANIFEST.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
json.loads(MANIFEST.read_text(encoding='utf-8'))
commit('Review 1: correct File 00 staging artifact provenance', [MANIFEST])

# ---------------- ROUND 2 ----------------
# Defect: File02 row was still v2.2 / 1.3.0 while approved reviewed evidence is v2.3 / 1.3.2.
m=json.loads(MANIFEST.read_text(encoding='utf-8'))
f02=m['candidate_matrix']['file02']
f02.update({
    'governing_plan_version':'2.3',
    'governing_target_runtime':'1.3.2',
    'governing_authentication_assurance_v2_contract':'2.0.0',
    'current_reviewed_local_package_sha256':FILE02_LOCAL_ZIP,
    'current_reviewed_local_evidence_only':True,
    'current_plan_github_exact_head_synced':False,
    'staging_usable_for_final_integrated_acceptance':False,
    'blocker':'Latest approved File 02 v2.3 is locally reviewed at runtime 1.3.2 with Authentication Assurance v2 contract 2.0.0, but GitHub PR #7 remains runtime 1.2.0. Do not assert final integrated staging acceptance until the v2.3/1.3.2 source is synchronized and exact-head CI/package evidence is pinned.'
})
m['preflight_status']='blocked_waiting_for_current_file02_1.3.2_exact_head_candidate_and_real_hostinger_evidence'
MANIFEST.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
json.loads(MANIFEST.read_text(encoding='utf-8'))
commit('Review 2: synchronize File 02 governing plan and runtime blocker', [MANIFEST])

# ---------------- ROUND 3 ----------------
# Defect: pinning status falsely said complete while a mandatory current-plan File02
# exact-head candidate was absent.
m=json.loads(MANIFEST.read_text(encoding='utf-8'))
m['mandatory_unpinned_companions']=['02-current-plan-1.3.2-exact-head']
m['candidate_pinning_status']='blocked_current_plan_file02_exact_head_missing'
m['candidate_source_pinning_status']='partial_current_plan_file02_exact_head_missing'
m['candidate_pinning_note']='Older exact source candidates remain useful compatibility baselines, but current-plan pinning is incomplete while File 02 v2.3/runtime 1.3.2 has no synchronized GitHub exact-head candidate. Pinned never means staging-accepted or mutually compatible.'
m['package_pinning_status']='partial_pending_file02_current_plan_and_file12_inner_package_receipts'
MANIFEST.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
STAGE_QA.write_text("""import fs from 'node:fs';
const manifest=JSON.parse(fs.readFileSync('qa/hostinger-staging-acceptance-manifest.json','utf8'));
const required=['00','01','02','03','08','09','12','17','18','19','20','21','22','23','24','25'];
const planExpected=['02','03','08','09','12','17','18','19','20','21','22','23','24','25'];
const actual=Object.keys(manifest.candidate_matrix).map(k=>k.replace(/^file/,'')).sort((a,b)=>Number(a)-Number(b));
const expected=[...required].sort((a,b)=>Number(a)-Number(b));
const fail=m=>{console.error('FAIL '+m);process.exitCode=1}; const ok=m=>console.log('PASS '+m);
JSON.stringify(actual)===JSON.stringify(expected)?ok('exact staging candidate set'):fail('candidate set mismatch '+actual.join(','));
for(const id of required){const c=manifest.candidate_matrix['file'+id];if(!c){fail('missing '+id);continue;}if(!/^[0-9a-f]{40}$/.test(c.sha||''))fail('invalid SHA '+id);if(c.required!==true)fail('not mandatory '+id);}
const plan=[...(manifest.required_file00_plan_integrations||[])].sort((a,b)=>Number(a)-Number(b));
JSON.stringify(plan)===JSON.stringify(planExpected.sort((a,b)=>Number(a)-Number(b)))?ok('plan integration set complete'):fail('plan integration set stale');
const allowed=new Set(['pending','pass','fail','blocked']);for(const [k,v] of Object.entries(manifest.gates||{})){if(!allowed.has(v))fail('invalid gate state '+k+': '+v);}
const ext=manifest.external_status||{};if(ext.staging_accepted||ext.live_deployed||ext.operational)fail('repository packet cannot self-assert external acceptance');else ok('external status evidence gated');
const f02=manifest.candidate_matrix.file02;
if(f02.governing_plan_version!=='2.3'||f02.governing_target_runtime!=='1.3.2'||f02.governing_authentication_assurance_v2_contract!=='2.0.0')fail('File02 governing evidence stale');else ok('File02 v2.3/1.3.2 governing evidence current');
if(f02.current_plan_github_exact_head_synced!==false||f02.staging_usable_for_final_integrated_acceptance!==false)fail('File02 repository freshness must fail closed');else ok('File02 current-plan blocker encoded');
if(!(manifest.mandatory_unpinned_companions||[]).includes('02-current-plan-1.3.2-exact-head'))fail('missing current-plan unpinned blocker');
if(String(manifest.candidate_pinning_status||'').startsWith('complete'))fail('false source pinning completeness');else ok('candidate pinning truthfully partial/blocked');
if(manifest.package_pinning_status==='complete'){for(const id of required){if(!manifest.candidate_matrix['file'+id].package_sha256)fail('false package-complete '+id);}}else ok('partial package pinning represented truthfully');
if(!/^blocked_/.test(manifest.preflight_status||''))fail('preflight must remain blocked');
const doc=`docs/HOSTINGER-STAGING-ACCEPTANCE-${manifest.release}.md`;
if(!fs.existsSync(doc))fail('release-matched staging doc missing');
if(process.exitCode)process.exit(1);
""",encoding='utf-8')
run('node',STAGE_QA)
commit('Review 3: fail closed on incomplete current-plan candidate pinning', [MANIFEST,STAGE_QA])

# Common source markers.
old_auth="""\t/** F00-EXT-002 — File 02 Passkey/WebAuthn assurance adapter. */
\tpublic static function authentication_assurance( $user_id ) {
\t\t$user_id = absint( $user_id );
\t\t$session_mfa = class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $user_id );
\t\t$session_verified_at = $session_mfa && method_exists( 'SMC_Security', 'session_verified_at' ) ? absint( SMC_Security::session_verified_at( $user_id ) ) : 0;
\t\tif ( $session_mfa && $session_verified_at <= 0 ) {
\t\t\t$session_mfa = false;
\t\t}
\t\t$baseline = array(
\t\t\t'contract_version' => '1.0.0',
\t\t\t'owner' => 'file00',
\t\t\t'level' => $session_mfa ? 2 : 1,
\t\t\t'method' => $session_mfa ? 'file00_totp_or_recovery' : 'primary_authentication_unasserted',
\t\t\t'passkey_asserted' => false,
\t\t\t'hardware_backed' => false,
\t\t\t'verified_at' => $session_verified_at,
\t\t);
\t\t$claim = apply_filters( 'smc_file02_authentication_assurance_v1', $baseline, $user_id );
\t\tif ( ! is_array( $claim ) ) {
\t\t\treturn $baseline;
\t\t}
\t\t$level = max( 0, min( 4, absint( $claim['level'] ?? $baseline['level'] ) ) );
\t\t$method = sanitize_key( $claim['method'] ?? $baseline['method'] );
\t\t$verified_at = absint( $claim['verified_at'] ?? 0 );
\t\t$owner = sanitize_key( $claim['owner'] ?? '' );
\t\t$owner_ok = 'file02' === $owner;
\t\t$contract_ok = '1.0.0' === (string) ( $claim['contract_version'] ?? '' );
\t\t$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );
\t\t$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;
\t\t$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;
\t\t$elevated = $level > (int) $baseline['level'] || ! empty( $claim['passkey_asserted'] ) || ! empty( $claim['hardware_backed'] );
\t\tif ( $verified_at > time() + 60 || ( $elevated && ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation ) ) ) {
\t\t\treturn $baseline;
\t\t}
\t\tif ( ! $elevated ) {
\t\t\treturn $baseline;
\t\t}
\t\treturn array(
\t\t\t'contract_version' => '1.0.0',
\t\t\t'owner' => 'file02',
\t\t\t'level' => $level,
\t\t\t'method' => $method ?: 'file02_authentication_assurance',
\t\t\t'passkey_asserted' => ! empty( $claim['passkey_asserted'] ),
\t\t\t'hardware_backed' => ! empty( $claim['hardware_backed'] ),
\t\t\t'verified_at' => $verified_at,
\t\t);
\t}
"""
new_auth="""\t/** F00-EXT-002 — File 02 Passkey/WebAuthn assurance adapter. */
\tpublic static function authentication_assurance( $user_id ) {
\t\t$user_id = absint( $user_id );
\t\t$session_mfa = class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $user_id );
\t\t$session_verified_at = $session_mfa && method_exists( 'SMC_Security', 'session_verified_at' ) ? absint( SMC_Security::session_verified_at( $user_id ) ) : 0;
\t\tif ( $session_mfa && $session_verified_at <= 0 ) { $session_mfa = false; }
\t\t$baseline = array(
\t\t\t'contract_version' => '1.0.0', 'owner' => 'file00', 'level' => $session_mfa ? 2 : 1,
\t\t\t'method' => $session_mfa ? 'file00_totp_or_recovery' : 'primary_authentication_unasserted',
\t\t\t'passkey_asserted' => false, 'hardware_backed' => false, 'user_verified' => false,
\t\t\t'phishing_resistant' => false, 'risk' => 'unknown', 'session_bound' => false,
\t\t\t'fingerprint_bound' => false, 'verified_at' => $session_verified_at,
\t\t);
\t\t$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );
\t\t$v2 = apply_filters( 'smc_file02_authentication_assurance_v2', null, $user_id );
\t\tif ( is_array( $v2 ) ) {
\t\t\t$verified_at = absint( $v2['verified_at'] ?? 0 );
\t\t\t$level = max( (int) $baseline['level'], min( 4, absint( $v2['level'] ?? 0 ) ) );
\t\t\t$risk = sanitize_key( $v2['risk'] ?? 'unknown' );
\t\t\tif ( ! in_array( $risk, array( 'unknown', 'low', 'normal', 'elevated', 'high' ), true ) ) { $risk = 'unknown'; }
\t\t\t$owner_ok = 'file02' === sanitize_key( $v2['owner'] ?? '' );
\t\t\t$contract_ok = '2.0.0' === (string) ( $v2['contract_version'] ?? '' );
\t\t\t$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;
\t\t\t$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;
\t\t\t$session_bound = ! empty( $v2['session_bound'] );
\t\t\t$fingerprint_bound = ! empty( $v2['fingerprint_bound'] );
\t\t\tif ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation || ! $session_bound || ! $fingerprint_bound ) { return $baseline; }
\t\t\treturn array(
\t\t\t\t'contract_version' => '2.0.0', 'owner' => 'file02', 'level' => $level,
\t\t\t\t'method' => sanitize_key( $v2['method'] ?? 'file02_authentication_assurance_v2' ) ?: 'file02_authentication_assurance_v2',
\t\t\t\t'passkey_asserted' => ! empty( $v2['passkey_asserted'] ), 'hardware_backed' => ! empty( $v2['hardware_backed'] ),
\t\t\t\t'user_verified' => ! empty( $v2['user_verified'] ), 'phishing_resistant' => ! empty( $v2['phishing_resistant'] ),
\t\t\t\t'risk' => $risk, 'session_bound' => true, 'fingerprint_bound' => true, 'verified_at' => $verified_at,
\t\t\t);
\t\t}
\t\t$claim = apply_filters( 'smc_file02_authentication_assurance_v1', $baseline, $user_id );
\t\tif ( ! is_array( $claim ) ) { return $baseline; }
\t\t$level = max( 0, min( 4, absint( $claim['level'] ?? $baseline['level'] ) ) );
\t\t$method = sanitize_key( $claim['method'] ?? $baseline['method'] );
\t\t$verified_at = absint( $claim['verified_at'] ?? 0 );
\t\t$owner = sanitize_key( $claim['owner'] ?? '' );
\t\t$owner_ok = 'file02' === $owner;
\t\t$contract_ok = '1.0.0' === (string) ( $claim['contract_version'] ?? '' );
\t\t$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;
\t\t$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;
\t\t$elevated = $level > (int) $baseline['level'] || ! empty( $claim['passkey_asserted'] ) || ! empty( $claim['hardware_backed'] );
\t\tif ( $verified_at > time() + 60 || ( $elevated && ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation ) ) ) { return $baseline; }
\t\tif ( ! $elevated ) { return $baseline; }
\t\treturn array(
\t\t\t'contract_version' => '1.0.0', 'owner' => 'file02', 'level' => $level,
\t\t\t'method' => $method ?: 'file02_authentication_assurance', 'passkey_asserted' => ! empty( $claim['passkey_asserted'] ),
\t\t\t'hardware_backed' => ! empty( $claim['hardware_backed'] ), 'user_verified' => false,
\t\t\t'phishing_resistant' => false, 'risk' => 'unknown', 'session_bound' => false,
\t\t\t'fingerprint_bound' => false, 'verified_at' => $verified_at,
\t\t);
\t}
"""

# ---------------- ROUND 4 ----------------
replace_once(ADV,old_auth,new_auth,'round4 auth v2')
run('php','-l',ADV); run('php',ADV_QA)
commit('Review 4: add fail-closed File 02 Authentication Assurance v2 consumer', [ADV])

# ---------------- ROUND 5 ----------------
old_profile="""\t\t\t'authentication_assurance_level' => (int) $auth['level'],
\t\t\t'authentication_owner' => (string) $auth['owner'],
\t\t\t'authentication_method' => (string) $auth['method'],
\t\t\t'hardware_backed' => ! empty( $auth['hardware_backed'] ),
\t\t\t'passkey_asserted' => ! empty( $auth['passkey_asserted'] ),
"""
new_profile="""\t\t\t'authentication_assurance_level' => (int) $auth['level'],
\t\t\t'authentication_contract_version' => (string) ( $auth['contract_version'] ?? '1.0.0' ),
\t\t\t'authentication_owner' => (string) $auth['owner'],
\t\t\t'authentication_method' => (string) $auth['method'],
\t\t\t'authentication_verified_at' => absint( $auth['verified_at'] ?? 0 ),
\t\t\t'authentication_risk' => sanitize_key( $auth['risk'] ?? 'unknown' ),
\t\t\t'user_verified' => ! empty( $auth['user_verified'] ),
\t\t\t'phishing_resistant' => ! empty( $auth['phishing_resistant'] ),
\t\t\t'session_bound' => ! empty( $auth['session_bound'] ),
\t\t\t'fingerprint_bound' => ! empty( $auth['fingerprint_bound'] ),
\t\t\t'hardware_backed' => ! empty( $auth['hardware_backed'] ),
\t\t\t'passkey_asserted' => ! empty( $auth['passkey_asserted'] ),
"""
replace_once(ADV,old_profile,new_profile,'round5 profile')
old_hidden="""\t\t\t'authentication_assurance_level' => 0,
\t\t\t'authentication_owner' => 'none',
\t\t\t'authentication_method' => 'none',
\t\t\t'hardware_backed' => false,
\t\t\t'passkey_asserted' => false,
"""
new_hidden="""\t\t\t'authentication_assurance_level' => 0,
\t\t\t'authentication_contract_version' => 'none',
\t\t\t'authentication_owner' => 'none',
\t\t\t'authentication_method' => 'none',
\t\t\t'authentication_verified_at' => 0,
\t\t\t'authentication_risk' => 'unknown',
\t\t\t'user_verified' => false,
\t\t\t'phishing_resistant' => false,
\t\t\t'session_bound' => false,
\t\t\t'fingerprint_bound' => false,
\t\t\t'hardware_backed' => false,
\t\t\t'passkey_asserted' => false,
"""
replace_once(ADV,old_hidden,new_hidden,'round5 hidden')
run('php','-l',ADV); run('php',ADV_QA)
commit('Review 5: preserve v2 provenance and privacy-minimal assurance facts', [ADV])

# ---------------- ROUND 6 ----------------
old_step="""\t/** F00-EXT-003 — Adaptive step-up verification. */
\tpublic static function step_up_requirement( $user_id, $action ) {
\t\t$action = sanitize_key( $action );
\t\t$profile = self::assurance_profile( $user_id );
\t\t$requirements = array(
\t\t\t'default' => array( 2, 2, false ),
\t\t\t'profile_sensitive_change' => array( 3, 2, false ),
\t\t\t'identity_change' => array( 3, 2, false ),
\t\t\t'guardian_change' => array( 3, 2, false ),
\t\t\t'delegation_grant' => array( 3, 2, false ),
\t\t\t'account_merge' => array( 4, 2, false ),
\t\t\t'professional_approval' => array( 4, 2, false ),
\t\t\t'founder_recovery' => array( 4, 3, true ),
\t\t\t'break_glass' => array( 4, 3, true ),
\t\t);
\t\t$required = isset( $requirements[ $action ] ) ? $requirements[ $action ] : $requirements['default'];
\t\t$risk = (array) apply_filters( 'smc_file24_step_up_context_v1', array(), absint( $user_id ), $action );
\t\tif ( ! empty( $risk['high_risk'] ) ) {
\t\t\t$required[0] = max( $required[0], 3 );
\t\t\t$required[1] = max( $required[1], 2 );
\t\t}
\t\t$membership_operational = self::protected_actions_allowed( absint( $user_id ) );
\t\t$satisfied = $membership_operational
\t\t\t&& (int) $profile['identity_assurance_level'] >= $required[0]
\t\t\t&& (int) $profile['authentication_assurance_level'] >= $required[1]
\t\t\t&& ( ! $required[2] || ! empty( $profile['hardware_backed'] ) );
\t\treturn array(
\t\t\t'action' => $action,
\t\t\t'required_identity_level' => $required[0],
\t\t\t'required_authentication_level' => $required[1],
\t\t\t'hardware_backed_required' => (bool) $required[2],
\t\t\t'current_identity_level' => (int) $profile['identity_assurance_level'],
\t\t\t'current_authentication_level' => (int) $profile['authentication_assurance_level'],
\t\t\t'membership_operational' => (bool) $membership_operational,
\t\t\t'satisfied' => (bool) $satisfied,
\t\t\t'risk_context' => array( 'high_risk' => ! empty( $risk['high_risk'] ) ),
\t\t);
\t}
"""
step6="""\t/** F00-EXT-003 — Adaptive step-up verification. */
\tpublic static function step_up_requirement( $user_id, $action ) {
\t\t$action = sanitize_key( $action );
\t\t$profile = self::assurance_profile( $user_id );
\t\t$requirements = array(
\t\t\t'default' => array( 2, 2, false, false ),
\t\t\t'profile_sensitive_change' => array( 3, 2, false, false ),
\t\t\t'identity_change' => array( 3, 2, false, false ),
\t\t\t'guardian_change' => array( 3, 2, false, false ),
\t\t\t'delegation_grant' => array( 3, 2, false, false ),
\t\t\t'account_merge' => array( 4, 2, false, false ),
\t\t\t'professional_approval' => array( 4, 2, false, false ),
\t\t\t'founder_recovery' => array( 4, 3, true, true ),
\t\t\t'break_glass' => array( 4, 3, true, true ),
\t\t);
\t\t$required = isset( $requirements[ $action ] ) ? $requirements[ $action ] : $requirements['default'];
\t\t$risk = (array) apply_filters( 'smc_file24_step_up_context_v1', array(), absint( $user_id ), $action );
\t\tif ( ! empty( $risk['high_risk'] ) ) {
\t\t\t$required[0] = max( $required[0], 3 ); $required[1] = max( $required[1], 3 ); $required[3] = true;
\t\t}
\t\t$membership_operational = self::protected_actions_allowed( absint( $user_id ) );
\t\t$satisfied = $membership_operational
\t\t\t&& (int) $profile['identity_assurance_level'] >= $required[0]
\t\t\t&& (int) $profile['authentication_assurance_level'] >= $required[1]
\t\t\t&& ( ! $required[2] || ! empty( $profile['hardware_backed'] ) )
\t\t\t&& ( ! $required[3] || ! empty( $profile['phishing_resistant'] ) );
\t\treturn array(
\t\t\t'action' => $action, 'required_identity_level' => $required[0], 'required_authentication_level' => $required[1],
\t\t\t'hardware_backed_required' => (bool) $required[2], 'phishing_resistant_required' => (bool) $required[3],
\t\t\t'current_identity_level' => (int) $profile['identity_assurance_level'], 'current_authentication_level' => (int) $profile['authentication_assurance_level'],
\t\t\t'current_phishing_resistant' => ! empty( $profile['phishing_resistant'] ), 'membership_operational' => (bool) $membership_operational,
\t\t\t'satisfied' => (bool) $satisfied, 'risk_context' => array( 'high_risk' => ! empty( $risk['high_risk'] ) ),
\t\t);
\t}
"""
replace_once(ADV,old_step,step6,'round6 step-up')
# Historical break-glass runtime regression must now use the additive v2 receipt.
replace_once(ADV_QA,
"$filters['smc_file02_authentication_assurance_v1']=fn($base,$uid)=>['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()];\n$bg=SMC_Advanced_Trust_2026::open_break_glass(7,1,'founder recovery');",
"$filters['smc_file02_authentication_assurance_v2']=fn($base,$uid)=>['owner'=>'file02','contract_version'=>'2.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'user_verified'=>true,'phishing_resistant'=>true,'risk'=>'normal','session_bound'=>true,'fingerprint_bound'=>true,'verified_at'=>time()];\n$bg=SMC_Advanced_Trust_2026::open_break_glass(7,1,'founder recovery');",
'round6 historical breakglass v2')
replace_once(ADV_QA,
"t('break glass cannot be replayed', SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1)===false);\nunset($filters['smc_file02_authentication_assurance_v1']);",
"t('break glass cannot be replayed', SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1)===false);\nunset($filters['smc_file02_authentication_assurance_v2']);",
'round6 historical unset')
run('php','-l',ADV); run('php',ADV_QA)
commit('Review 6: require phishing-resistant authentication for highest-risk step-up', [ADV,ADV_QA])

# ---------------- ROUND 7 ----------------
# Defect: a legacy local File00-MFA precondition made a valid File02 v2 receipt unable to
# satisfy File00 step-up, duplicating the authentication ceremony.
replace_once(ADV,
"\t\tif ( ! self::actor_is_current( $actor_id, $capability, $founder_or_admin ) || ! class_exists( 'SMC_Security' ) || ! SMC_Security::session_is_verified( $actor_id ) ) { return false; }\n",
"\t\tif ( ! self::actor_is_current( $actor_id, $capability, $founder_or_admin ) ) { return false; }\n",
'round7 actor gate')
V2_QA.write_text(r'''<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/'); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400); define('YEAR_IN_SECONDS',31536000);
$meta=[];$filters=[];$current_user_id=1;
class WP_Error{public $code;function __construct($c,$m=''){$this->code=$c;}} function is_wp_error($v){return $v instanceof WP_Error;} function __($s,$d=null){return $s;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_userdata($id){return $id>0?(object)['ID'=>$id]:false;} function get_user_meta($id,$key='',$single=false){global $meta;return $meta[$id][$key]??($single?'':[]);} function update_user_meta($id,$key,$v){global $meta;$meta[$id][$key]=$v;return true;} function delete_user_meta($id,$key){global $meta;unset($meta[$id][$key]);return true;} function metadata_exists($type,$id,$key){global $meta;return array_key_exists($key,$meta[$id]??[]);} function get_current_user_id(){global $current_user_id;return $current_user_id;} function current_user_can($c){return true;} function user_can($u,$c){return true;}
function add_filter($t,$c,$p=10,$n=1){return true;} function add_action($t,$c,$p=10,$n=1){return true;} function apply_filters($tag,$value,...$args){global $filters;return isset($filters[$tag])?$filters[$tag]($value,...$args):$value;} function do_action($t,...$a){} function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000001';} function wp_salt($s='auth'){return 'salt';} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function get_option($k,$d=false){return $d;} function update_option($k,$v,$a=false){return true;} function add_option($k,$v,$d='',$a='yes'){return true;} function delete_option($k){return true;} function get_users($a=[]){return [];} function smc_is_founder($id){return (int)$id===1;} function smc_is_institutional_ai($id){return false;}
class WPDBV2{public $prefix='wp_';public $users='wp_users';function prepare($q,...$a){return $q;}function get_var($q){return null;}function get_col($q){return [];}} $wpdb=new WPDBV2();
class SMC_Security{public static $verified=false;static function session_is_verified($id){return self::$verified;}static function session_verified_at($id){return self::$verified?time()-30:0;}static function subject_hash($id){return hash('sha256',(string)$id);}static function audit($a,$id=0,$d=[]){return true;}static function revoke_all_sessions($id,$r=''){return true;}}
class SMC_Contracts{static function assertions($id){return ['identity_documents_current'=>true,'phone_verified'=>true,'email_verified'=>true,'guardian_verified'=>true,'professional_verified'=>true,'approved'=>true,'suspended'=>false,'eligible'=>true];}} class SMC_CF01_Contract{static function ensure_subject_uuid($id){return 'uuid-'.$id;}}
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php';
$tests=[];function t($n,$ok){global $tests;$tests[]=[$n,(bool)$ok];}
$v2good=fn($base,$uid)=>['owner'=>'file02','contract_version'=>'2.0.0','level'=>3,'method'=>'passkey','verified_at'=>time()-30,'risk'=>'normal','user_verified'=>true,'phishing_resistant'=>true,'session_bound'=>true,'fingerprint_bound'=>true,'passkey_asserted'=>true,'hardware_backed'=>true];
$filters['smc_file02_authentication_assurance_v2']=$v2good;
$a=SMC_Advanced_Trust_2026::authentication_assurance(7);t('v2 accepted',$a['owner']==='file02'&&$a['contract_version']==='2.0.0'&&$a['level']===3&&$a['phishing_resistant']&&$a['session_bound']&&$a['fingerprint_bound']);
$p=SMC_Advanced_Trust_2026::assurance_profile(7);t('v2 provenance propagated',$p['authentication_contract_version']==='2.0.0'&&$p['phishing_resistant']&&$p['user_verified']);
$r=SMC_Advanced_Trust_2026::step_up_requirement(7,'break_glass');t('break glass requires phishing resistance',$r['phishing_resistant_required']===true&&$r['satisfied']===true);
$rm=new ReflectionMethod('SMC_Advanced_Trust_2026','actor_meets_step_up');$rm->setAccessible(true);t('v2 satisfies stepup without duplicate local MFA gate',$rm->invoke(null,1,'break_glass','manage_options',true)===true);
$filters['smc_file02_authentication_assurance_v2']=fn($b,$u)=>['owner'=>'file02','contract_version'=>'2.0.0','level'=>4,'verified_at'=>time()-20,'session_bound'=>false,'fingerprint_bound'=>true];$filters['smc_file02_authentication_assurance_v1']=fn($b,$u)=>['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'verified_at'=>time()-10,'passkey_asserted'=>true,'hardware_backed'=>true];$a=SMC_Advanced_Trust_2026::authentication_assurance(7);t('malformed v2 cannot downgrade to v1',$a['owner']==='file00');unset($filters['smc_file02_authentication_assurance_v2']);$a=SMC_Advanced_Trust_2026::authentication_assurance(7);t('v1 compatibility retained',$a['owner']==='file02'&&$a['contract_version']==='1.0.0');
// ROUND8_TESTS
// ROUND9_TESTS
$fail=0;foreach($tests as[$n,$ok]){echo($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$fail++;}echo 'File02 assurance v2 runtime: '.(count($tests)-$fail).' PASS / '.$fail." FAIL\n";exit($fail?1:0);
''',encoding='utf-8')
run('php','-l',ADV); run('php',V2_QA); run('php',ADV_QA)
commit('Review 7: remove duplicate local MFA gate from versioned step-up evaluation', [ADV,V2_QA])

# ---------------- ROUND 8 ----------------
# Defect: v2 authentication risk was projected but native File00 step-up ignored it.
old_risk="""\t\t$risk = (array) apply_filters( 'smc_file24_step_up_context_v1', array(), absint( $user_id ), $action );
\t\tif ( ! empty( $risk['high_risk'] ) ) {
\t\t\t$required[0] = max( $required[0], 3 ); $required[1] = max( $required[1], 3 ); $required[3] = true;
\t\t}
"""
new_risk="""\t\t$risk = (array) apply_filters( 'smc_file24_step_up_context_v1', array(), absint( $user_id ), $action );
\t\t$authentication_risk = sanitize_key( $profile['authentication_risk'] ?? 'unknown' );
\t\t$high_risk = ! empty( $risk['high_risk'] ) || in_array( $authentication_risk, array( 'elevated', 'high' ), true );
\t\tif ( $high_risk ) {
\t\t\t$required[0] = max( $required[0], 3 ); $required[1] = max( $required[1], 3 ); $required[3] = true;
\t\t}
"""
replace_once(ADV,old_risk,new_risk,'round8 risk')
replace_once(ADV,"\t\t\t'current_phishing_resistant' => ! empty( $profile['phishing_resistant'] ), 'membership_operational' => (bool) $membership_operational,\n\t\t\t'satisfied' => (bool) $satisfied, 'risk_context' => array( 'high_risk' => ! empty( $risk['high_risk'] ) ),\n",
"\t\t\t'current_phishing_resistant' => ! empty( $profile['phishing_resistant'] ), 'authentication_risk' => $authentication_risk, 'membership_operational' => (bool) $membership_operational,\n\t\t\t'satisfied' => (bool) $satisfied, 'risk_context' => array( 'high_risk' => (bool) $high_risk ),\n",'round8 output')
replace_once(V2_QA,'// ROUND8_TESTS',"""$filters['smc_file02_authentication_assurance_v2']=fn($b,$u)=>['owner'=>'file02','contract_version'=>'2.0.0','level'=>3,'method'=>'passkey','verified_at'=>time()-20,'risk'=>'high','user_verified'=>true,'phishing_resistant'=>false,'session_bound'=>true,'fingerprint_bound'=>true,'passkey_asserted'=>true,'hardware_backed'=>true];$r=SMC_Advanced_Trust_2026::step_up_requirement(7,'profile_sensitive_change');t('v2 high risk strengthens native stepup',$r['risk_context']['high_risk']===true&&$r['required_authentication_level']>=3&&$r['phishing_resistant_required']===true&&$r['satisfied']===false);
// ROUND8_TESTS_DONE""",'round8 test')
run('php','-l',ADV); run('php',V2_QA); run('php',ADV_QA)
commit('Review 8: bind File 02 v2 authentication risk into native step-up', [ADV,V2_QA])

# ---------------- ROUND 9 ----------------
# Defect: claim envelope regenerated authentication issued/expires timestamps from now,
# losing the actual authentication receipt freshness and allowing over-extension.
old_claim="\t\t\t\t'authentication' => array( 'owner' => sanitize_key( $auth['owner'] ?? 'file00' ), 'level' => (int) $profile['authentication_assurance_level'], 'method' => sanitize_key( $auth['method'] ?? '' ), 'issued_at' => $now, 'expires_at' => $now + 120 ),\n"
new_claim="""\t\t\t\t'authentication' => array(
\t\t\t\t\t'owner' => sanitize_key( $auth['owner'] ?? 'file00' ),
\t\t\t\t\t'contract_version' => (string) ( $auth['contract_version'] ?? '1.0.0' ),
\t\t\t\t\t'level' => (int) $profile['authentication_assurance_level'],
\t\t\t\t\t'method' => sanitize_key( $auth['method'] ?? '' ),
\t\t\t\t\t'verified_at' => absint( $auth['verified_at'] ?? 0 ),
\t\t\t\t\t'user_verified' => ! empty( $auth['user_verified'] ),
\t\t\t\t\t'phishing_resistant' => ! empty( $auth['phishing_resistant'] ),
\t\t\t\t\t'risk' => sanitize_key( $auth['risk'] ?? 'unknown' ),
\t\t\t\t\t'session_bound' => ! empty( $auth['session_bound'] ),
\t\t\t\t\t'fingerprint_bound' => ! empty( $auth['fingerprint_bound'] ),
\t\t\t\t\t'issued_at' => $now,
\t\t\t\t\t'expires_at' => absint( $auth['verified_at'] ?? 0 ) > 0 ? min( $now + 120, absint( $auth['verified_at'] ) + 5 * MINUTE_IN_SECONDS ) : $now + 120,
\t\t\t\t),
"""
replace_once(ADV,old_claim,new_claim,'round9 claims')
replace_once(V2_QA,'// ROUND9_TESTS',"""$filters['smc_file02_authentication_assurance_v2']=$v2good;$env=SMC_Advanced_Trust_2026::claims_envelope(7);$ac=$env['claims']['authentication'];t('auth claim keeps receipt provenance and bounded freshness',$ac['verified_at']>0&&$ac['expires_at']<=$ac['verified_at']+300&&$ac['contract_version']==='2.0.0'&&$ac['phishing_resistant']===true&&$ac['fingerprint_bound']===true);
// ROUND9_TESTS_DONE""",'round9 test')
run('php','-l',ADV); run('php',V2_QA); run('php',ADV_QA)
commit('Review 9: bind authentication claim freshness to the source receipt', [ADV,V2_QA])

# ---------------- ROUND 10 ----------------
# Defect: corrected source would otherwise still identify/package itself as 1.2.19 and
# sixth-review evidence, conflating two materially different releases.
replace_once(MAIN,' * Version: 1.2.19',' * Version: 1.2.20','round10 plugin header')
replace_once(MAIN,"define( 'SMC_VERSION', '1.2.19' );","define( 'SMC_VERSION', '1.2.20' );",'round10 runtime')
replace_once(README,'Stable tag: 1.2.19','Stable tag: 1.2.20','round10 stable tag')
readme=README.read_text(encoding='utf-8')
anchor='= 1.2.19 =\n'
if anchor not in readme: raise SystemExit('round10 changelog anchor missing')
entry="""= 1.2.20 =
* Seventh fresh ten-round Review → Fix → Retest closure against the latest governing File 02 v2.3 / runtime 1.3.2 evidence.
* Adds fail-closed Authentication Assurance Receipt v2 consumption while preserving v1 compatibility only when v2 is absent.
* Propagates privacy-minimal UV/phishing-resistance/risk/binding provenance and binds high-risk step-up to phishing-resistant assurance.
* Removes the duplicate local-MFA ceremony gate, bounds authentication-claim freshness to the source receipt, and hardens staging candidate truthfulness.
* Hostinger staging, live deployment, and operational acceptance remain separate external evidence gates.

"""
README.write_text(readme.replace(anchor,entry+anchor,1),encoding='utf-8')
# Current-runtime QA fixture/version expectations.
replace_once(ADV_QA,"define('SMC_VERSION', '1.2.19');","define('SMC_VERSION', '1.2.20');",'round10 advanced runtime version')
for qname in ['qa/membership-state-contract.mjs','qa/third-fresh-review-contract.mjs','qa/fourth-fresh-review-contract.mjs']:
    qp=ROOT/qname; qs=qp.read_text(encoding='utf-8'); qs=qs.replace('1.2.19','1.2.20'); qp.write_text(qs,encoding='utf-8')
fifth=ROOT/'qa/fifth-fresh-review-contract.mjs'; fs=fifth.read_text(encoding='utf-8')
fs=fs.replace("['runtime 1.2.19',main.includes('Version: 1.2.19')&&main.includes(\"SMC_VERSION', '1.2.19\")&&pkg.version==='1.2.19'],","['runtime 1.2.20',main.includes('Version: 1.2.20')&&main.includes(\"SMC_VERSION', '1.2.20\")&&pkg.version==='1.2.20'],")
fs=fs.replace("adv.includes(\"'break_glass' => array( 4, 3, true )\")","adv.includes(\"'break_glass' => array( 4, 3, true, true )\")")
fs=fs.replace("readme.includes('Stable tag: 1.2.19')&&readme.includes('= 1.2.19 =')&&readme.includes('= 1.2.18 =')","readme.includes('Stable tag: 1.2.20')&&readme.includes('= 1.2.20 =')&&readme.includes('= 1.2.19 =')&&readme.includes('= 1.2.18 =')")
fifth.write_text(fs,encoding='utf-8')
# Historical fifth/sixth runtime suites exercise break-glass success; upgrade those specific strong-assurance fixtures to v2.
v2_literal="['owner'=>'file02','contract_version'=>'2.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'user_verified'=>true,'phishing_resistant'=>true,'risk'=>'normal','session_bound'=>true,'fingerprint_bound'=>true,'verified_at'=>time()]"
v1_literal="['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()]"
for qp in (ROOT/'qa').glob('*.php'):
    if qp.name=='advanced-trust-runtime.php': continue
    qs=qp.read_text(encoding='utf-8')
    if 'open_break_glass' not in qs: continue
    before=qs
    qs=qs.replace("$filters['smc_file02_authentication_assurance_v1']=fn($b,$u)=>"+v1_literal,"$filters['smc_file02_authentication_assurance_v2']=fn($b,$u)=>"+v2_literal)
    qs=qs.replace("unset($filters['smc_file02_authentication_assurance_v1']);","unset($filters['smc_file02_authentication_assurance_v2']);")
    if qs!=before: qp.write_text(qs,encoding='utf-8')
# Package metadata.
p=json.loads(PKG.read_text(encoding='utf-8')); p['version']='1.2.20'
if 'php qa/file02-auth-assurance-v2-runtime.php' not in p['scripts']['test']:
    p['scripts']['test'] += ' && php qa/file02-auth-assurance-v2-runtime.php && node qa/seventh-fresh-review-contract.mjs'
p['scripts']['verify']=p['scripts']['verify'].replace('1.2.19.zip','1.2.20.zip')
PKG.write_text(json.dumps(p,indent=2)+'\n',encoding='utf-8')
l=json.loads(LOCK.read_text(encoding='utf-8')); l['version']='1.2.20'; l['packages']['']['version']='1.2.20'; LOCK.write_text(json.dumps(l,indent=2)+'\n',encoding='utf-8')
# Current release line in repository master traceability.
master=ROOT/'docs/FILE-00-MASTER-PLAN-2026.md'
if master.exists():
    s=master.read_text(encoding='utf-8'); s=s.replace('Runtime implementation release: `1.2.19`','Runtime implementation release: `1.2.20`',1); master.write_text(s,encoding='utf-8')
# New staging delta doc; inherited full real-environment packet remains historical evidence.
stage_doc=ROOT/'docs/HOSTINGER-STAGING-ACCEPTANCE-1.2.20.md'
stage_doc.write_text("""# File 00 — Hostinger Staging Acceptance Delta — 1.2.20

This document inherits every real-environment gate from `HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md` and records only the seventh-review deltas. It is not evidence that Hostinger staging has passed.

## Candidate matrix

| File | Current staging evidence | Status |
|---|---|---|
| 00 Membership Core | runtime 1.2.20; final exact head and artifact supplied by GitHub CI/PR evidence | required candidate |
| 01 Foundation | previously pinned candidate | required |
| 02 Authentication | GitHub PR #7 remains 1.2.0; governing File 02 v2.3 local-reviewed runtime is 1.3.2, Authentication Assurance v2 contract 2.0.0 | **BLOCKED — current-plan GitHub exact-head candidate absent** |
| 03 Profiles | previously pinned candidate | required |
| 08 Clinic/Appointments | exact-head package previously pinned | required |
| 09 Doctor Verification | previously pinned candidate | required |
| 12 PDF Library | source/artifact wrapper pinned; inner installable identity still pending | required / partial package evidence |
| 17 Communication | previously pinned candidate | required |
| 18 Marketplace | exact-head package previously pinned | required |
| 19 Notifications | previously pinned candidate | required |
| 20 Unified Shell | previously pinned candidate | required |
| 21 Home/News | previously pinned candidate | required |
| 22 Composer | previously pinned candidate | required |
| 23 Publishing Dashboard | previously pinned candidate | required |
| 24 Security/Privacy | previously pinned candidate | required |
| 25 Visual Experience | previously pinned candidate | required |

## Preflight blocker — File 02 governing-plan freshness

File 00 now consumes the additive `smc_file02_authentication_assurance_v2` contract with contract version `2.0.0` and preserves v1 only when v2 is absent. A malformed or stale v2 receipt fails closed and cannot silently downgrade to v1. Final integrated staging acceptance remains blocked until File 02 v2.3/runtime 1.3.2 is synchronized to GitHub and exact-head CI/package evidence is pinned.

## Truthful status

Repository code/package/automated QA may be accepted independently. `Staging-Accepted`, `Live-Deployed`, and `Operational` remain false until real Hostinger, provider, browser/accessibility, backup/restore, rollback, and Founder-acceptance evidence is attached.
""",encoding='utf-8')
review_doc=ROOT/'docs/FILE-00-SEVENTH-FRESH-TEN-ROUND-REVIEW-1.2.20.md'
review_doc.write_text("""# File 00 — Seventh Completely Fresh Ten-Round Review — Release 1.2.20

Baseline: sixth-review exact candidate `c0d04a67fb2c24319d34defc23d534e0546445e0`, reopened because newer File 02 v2.3/runtime 1.3.2 governing evidence became available.

| Round | Result | Defect | Correction / retest |
|---:|---|---|---|
| 1 | Defect | S7R-R1-D01 Medium — staging artifact provenance still presented older main/package evidence while a newer exact 1.2.19 candidate existed | Preserved historical main receipt, added exact sixth-review baseline artifact/ZIP provenance and re-pinned File00 baseline |
| 2 | Defect | S7R-R2-D01 High — File02 ledger was v2.2/1.3.0 but governing reviewed evidence is v2.3/1.3.2 with Assurance v2 2.0.0 | Updated governing evidence and explicit GitHub-sync blocker |
| 3 | Defect | S7R-R3-D01 High — candidate/source pinning claimed complete despite missing current-plan File02 exact head | Pinning states now partial/blocked; executable contract fails closed |
| 4 | Defect | S7R-R4-D01 High — File00 consumed only File02 assurance v1 | Added additive v2 consumer with 5-minute freshness, owner/version, session/fingerprint binding, anti-downgrade and v1-when-absent compatibility |
| 5 | Defect | S7R-R5-D01 Medium — v2 UV/phishing/risk/binding/provenance disappeared from File00 assurance profile | Added privacy-minimal booleans/normalized risk and receipt provenance; no raw fingerprint |
| 6 | Defect | S7R-R6-D01 High — highest-risk File00 step-up modeled hardware-backed but not phishing resistance | Founder recovery and break-glass now require phishing-resistant assurance; File24 high risk may only strengthen |
| 7 | Defect | S7R-R7-D01 Medium — legacy local File00 MFA pre-gate could reject a valid File02 v2 strong receipt before versioned step-up evaluation | Removed duplicate ceremony gate; native step-up remains fail-closed on assurance levels and membership state |
| 8 | Defect | S7R-R8-D01 High — File02 v2 receipt risk was exposed but ignored by native File00 step-up | elevated/high receipt risk strengthens auth level and phishing-resistance requirement |
| 9 | Defect | S7R-R9-D01 Medium — claim envelope regenerated auth freshness from current time and omitted source verified_at | Bound nested auth claim to source verified_at and maximum receipt freshness |
| 10 | Defect | S7R-R10-D01 Medium — materially corrected source would still identify/package as 1.2.19/sixth review | Bumped 1.2.20, synchronized package/readme/tests/docs/manifest and deterministic package gates |

Total: **10 fresh rounds; defects in 10/10 rounds; 10 unique defects corrected.** Severity: **5 High, 5 Medium, 0 Critical, 0 Low**. Known unresolved repository defects in this reviewed scope are zero after final exact-head CI. External staging/live/operational gates remain separate.
""",encoding='utf-8')
release_doc=ROOT/'docs/RELEASE-1.2.20-SEVENTH-FRESH-TEN-REVIEW.md'
release_doc.write_text("""# File 00 Release 1.2.20 — Seventh Fresh Ten-Round Corrective Closure

Release 1.2.20 aligns File00 with the newly available File02 v2.3 authentication-assurance contract while preserving canonical ownership: File00 owns membership/identity/MFA policy; File02 owns authentication ceremonies; File24 remains the security assurance plane. The release adds no parallel identity or authentication backend.

Ten fresh Review → Fix → Retest rounds found and corrected ten repository defects. Final exact-head CI/package evidence is supplied by GitHub Actions after temporary review tooling is removed. Hostinger staging, live deployment, and operational acceptance remain external gates.
""",encoding='utf-8')
trace={
 'release':'1.2.20','series':'seventh-fresh-ten-round','review_complete':True,'rounds':10,
 'defect_rounds':[1,2,3,4,5,6,7,8,9,10],'clean_rounds':[],'defect_count':10,'defects_corrected_total':10,
 'known_unresolved_repository_defects':0,'severity':{'critical':0,'high':5,'medium':5,'low':0},
 'file02_governing_evidence':{'plan_version':'2.3','reviewed_runtime':'1.3.2','authentication_assurance_v2_contract':'2.0.0','github_exact_head_synced':False},
 'external_status':{'staging_accepted':False,'live_deployed':False,'operational':False}
}
(ROOT/'qa/seventh-fresh-ten-review-traceability.json').write_text(json.dumps(trace,indent=2)+'\n',encoding='utf-8')
static=ROOT/'qa/seventh-fresh-review-contract.mjs'
static.write_text("""import fs from 'node:fs';
const src=fs.readFileSync('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php','utf8');
const main=fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php','utf8');
const readme=fs.readFileSync('source/sabri-membership-core/README.txt','utf8');
const pkg=JSON.parse(fs.readFileSync('package.json','utf8'));
const trace=JSON.parse(fs.readFileSync('qa/seventh-fresh-ten-review-traceability.json','utf8'));
const stage=JSON.parse(fs.readFileSync('qa/hostinger-staging-acceptance-manifest.json','utf8'));
const t=[];const test=(n,v)=>t.push([n,!!v]);
test('runtime 1.2.20',main.includes('Version: 1.2.20')&&main.includes("SMC_VERSION', '1.2.20")&&pkg.version==='1.2.20'&&readme.includes('Stable tag: 1.2.20'));
test('v2 adapter additive',src.includes('smc_file02_authentication_assurance_v2')&&src.includes("'2.0.0' === (string) ( $v2['contract_version']" )&&src.includes('smc_file02_authentication_assurance_v1'));
test('v2 anti downgrade bindings',src.includes("! $session_bound || ! $fingerprint_bound")&&src.includes("return $baseline;"));
test('privacy minimal v2 provenance',src.includes("'phishing_resistant'")&&src.includes("'user_verified'")&&src.includes("'fingerprint_bound'")&&!src.includes("'fingerprint' =>"));
test('phishing stepup',src.includes("'phishing_resistant_required'")&&src.includes("'break_glass' => array( 4, 3, true, true )"));
test('duplicate local MFA gate removed',!src.includes("actor_is_current( $actor_id, $capability, $founder_or_admin ) || ! class_exists( 'SMC_Security' ) || ! SMC_Security::session_is_verified"));
test('receipt risk bound to stepup',src.includes("in_array( $authentication_risk, array( 'elevated', 'high' ), true )"));
test('auth claim receipt freshness',src.includes("'verified_at' => absint( $auth['verified_at']")&&src.includes("+ 5 * MINUTE_IN_SECONDS"));
test('review traceability',trace.release==='1.2.20'&&trace.rounds===10&&trace.defect_count===10&&trace.defect_rounds.join(',')==='1,2,3,4,5,6,7,8,9,10'&&trace.known_unresolved_repository_defects===0);
test('File02 current-plan staging blocked',stage.candidate_matrix.file02.governing_plan_version==='2.3'&&stage.candidate_matrix.file02.governing_target_runtime==='1.3.2'&&stage.candidate_matrix.file02.current_plan_github_exact_head_synced===false&&stage.external_status.staging_accepted===false);
let fail=0;for(const[n,ok]of t){console.log((ok?'PASS ':'FAIL ')+n);if(!ok)fail++;}console.log(`Seventh fresh review contract: ${t.length-fail} PASS / ${fail} FAIL`);if(fail)process.exit(1);
""",encoding='utf-8')
# Release manifest status before package build.
m=json.loads(MANIFEST.read_text(encoding='utf-8'));m['release']='1.2.20';m['candidate_matrix']['file00']['version']='1.2.20';m['release_review']='seventh_fresh_ten_round';m['staging_ledger_review']='seventh_fresh_review_complete_repository_scope';MANIFEST.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
# Build now that installable source identity is final; then bind package hash into the staging ledger.
run('php','-l',ADV); run('php',V2_QA); run('php',ADV_QA)
run('python3','tools/build.py')
zip_path=ROOT/'dist/00-sabri-membership-core-1.2.20.zip'; zhash=sha256(zip_path)
m=json.loads(MANIFEST.read_text(encoding='utf-8'));m['plugin_zip_sha256']=zhash;m['candidate_matrix']['file00']['package_sha256']=zhash;m['candidate_matrix']['file00']['package_identity_state']='deterministic_local_and_ci_rebuilt';MANIFEST.write_text(json.dumps(m,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
# Round 10 current-runtime fixture synchronization. Historical changelog/trace identities are retained.
for qp in (ROOT/'qa').rglob('*'):
    if not qp.is_file() or qp.suffix not in ('.mjs','.php'):
        continue
    qx=qp.read_text(encoding='utf-8')
    qn=qx
    for before,after in [
        ('Version: 1.2.19','Version: 1.2.20'),
        ("SMC_VERSION', '1.2.19","SMC_VERSION', '1.2.20"),
        ('Stable tag: 1.2.19','Stable tag: 1.2.20'),
        ("define('SMC_VERSION', '1.2.19')","define('SMC_VERSION', '1.2.20')"),
        ("define('SMC_VERSION','1.2.19')","define('SMC_VERSION','1.2.20')"),
        ("pkg.version==='1.2.19'","pkg.version==='1.2.20'"),
        ("packageJson.version === '1.2.19'","packageJson.version === '1.2.20'"),
        ('Runtime implementation release: `1.2.19`','Runtime implementation release: `1.2.20`'),
    ]:
        qn=qn.replace(before,after)
    if qn!=qx:
        qp.write_text(qn,encoding='utf-8')
run('node',STAGE_QA);run('node',static);run('npm','test');run('python3','qa/verify-package.py',zip_path);run('git','diff','--check')
commit('Review 10: close File 00 1.2.20 seventh fresh review and deterministic package')
# Temporary write-capable applicator must not survive final reviewed source head.
self_path=Path(__file__).resolve(); self_path.unlink()
commit('QA closure: remove temporary seventh-review applicator', [self_path.relative_to(ROOT)])
run('git','push','origin',f'HEAD:{BRANCH}')
print('SEVENTH REVIEW APPLIED; installable SHA256',zhash,flush=True)

#!/usr/bin/env python3
from pathlib import Path
import json

old='1.2.41'
new='1.2.42'
root=Path(__file__).resolve().parents[1]
plugin=root/'source/sabri-membership-core/sabri-membership-core.php'
auth=root/'source/sabri-membership-core/includes/class-smc-authorization.php'
readme=root/'source/sabri-membership-core/README.txt'
master=root/'docs/FILE-00-MASTER-PLAN-2026.md'

# Runtime identity and bridge contract constants.
s=plugin.read_text(encoding='utf-8')
s=s.replace('Version: 1.2.41','Version: 1.2.42',1)
s=s.replace("define( 'SMC_VERSION', '1.2.41' );","define( 'SMC_VERSION', '1.2.42' );",1)
anchor="define( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0' );"
addition="\n".join([
    anchor,
    "define( 'SMC_FILE01_AUTH_CLAIM_VERSION', '1.0.0' );",
    "define( 'SMC_FILE01_FOUNDATION_CONTRACT_VERSION', '2.0.0' );",
])
if 'SMC_FILE01_AUTH_CLAIM_VERSION' not in s:
    s=s.replace(anchor,addition,1)
plugin.write_text(s,encoding='utf-8')

# Canonical File 00 provider for File 01 structured authorization claims.
s=auth.read_text(encoding='utf-8')
hook="\t\tadd_filter( 'smc_assertions_v1', array( __CLASS__, 'filter_current_age_assertion' ), 100, 2 );"
hook_add=hook+"\n\t\tadd_filter( 'spf_file00_authorization_claim', array( __CLASS__, 'file01_authorization_claim' ), 10, 2 );"
if "add_filter( 'spf_file00_authorization_claim'" not in s:
    if hook not in s:
        raise SystemExit('authorization hook marker not found')
    s=s.replace(hook,hook_add,1)

marker="\n\t/**\n\t * Appeals must never return to the actor who imposed the latest rejection or\n"
bridge='''
\t/**
\t * Produce the canonical File 00 authorization claim consumed by File 01.
\t *
\t * The claim is in-process evidence only: it is short-lived, actor/action/
\t * object/purpose bound, and never grants authority beyond the exact File 01
\t * contract understood by this release. Unsupported or malformed requests
\t * return null so the consumer remains fail-closed.
\t */
\tpublic static function file01_authorization_claim( $claim, $request ) {
\t\tif ( null !== $claim ) {
\t\t\treturn $claim;
\t\t}
\t\tif ( ! is_array( $request ) ) {
\t\t\treturn null;
\t\t}

\t\t$user_id      = absint( $request['user_id'] ?? 0 );
\t\t$actor_id     = absint( $request['actor_id'] ?? 0 );
\t\t$action       = (string) ( $request['action'] ?? '' );
\t\t$capability   = (string) ( $request['capability'] ?? '' );
\t\t$purpose      = (string) ( $request['purpose'] ?? '' );
\t\t$plugin       = (string) ( $request['plugin'] ?? '' );
\t\t$contract     = (string) ( $request['contract'] ?? '' );
\t\t$object_hash  = strtolower( trim( (string) ( $request['object_hash'] ?? '' ) ) );
\t\t$request_time = absint( $request['current_time'] ?? 0 );

\t\tif ( ! $user_id || $user_id !== $actor_id || $user_id !== get_current_user_id() ) {
\t\t\treturn null;
\t\t}
\t\tif ( '' === $action || $action !== sanitize_key( $action ) || '' === $purpose || $purpose !== sanitize_key( $purpose ) ) {
\t\t\treturn null;
\t\t}
\t\tif ( 'file-01' !== $plugin || SMC_FILE01_FOUNDATION_CONTRACT_VERSION !== $contract ) {
\t\t\treturn null;
\t\t}
\t\tif ( ! preg_match( '/^[a-f0-9]{64}$/', $object_hash ) ) {
\t\t\treturn null;
\t\t}
\t\tif ( ! $request_time || abs( time() - $request_time ) > 120 ) {
\t\t\treturn null;
\t\t}

\t\t$required = self::file01_required_capability( $action );
\t\tif ( '' === $required || $required !== $capability || $capability !== sanitize_key( $capability ) ) {
\t\t\treturn null;
\t\t}

\t\t$assertions = self::assertions( $user_id );
\t\t$is_founder = smc_is_founder( $user_id );
\t\t$is_admin   = self::user_is_administrator( $user_id );
\t\t$role       = $is_founder ? 'founder' : ( $is_admin ? 'administrator' : 'member' );
\t\t$allowed    = ! empty( $assertions['effective_eligible'] ) && empty( $assertions['hard_blocked'] );
\t\tif ( $allowed ) {
\t\t\tif ( $is_founder ) {
\t\t\t\t$allowed = true;
\t\t\t} elseif ( $is_admin ) {
\t\t\t\t$allowed = in_array( $action, array( 'view', 'system_check', 'run_system_check' ), true );
\t\t\t} else {
\t\t\t\t$allowed = false;
\t\t\t}
\t\t}

\t\t$now = time();
\t\treturn array(
\t\t\t'claim_version'      => SMC_FILE01_AUTH_CLAIM_VERSION,
\t\t\t'allowed'            => (bool) $allowed,
\t\t\t'user_id'            => (int) $user_id,
\t\t\t'actor_id'           => (int) $user_id,
\t\t\t'action'             => $action,
\t\t\t'capability'         => $required,
\t\t\t'issued_at'          => $now,
\t\t\t'expires_at'         => $now + 60,
\t\t\t'claim_id'           => 'smc-f01:' . strtolower( wp_generate_uuid4() ),
\t\t\t'object_hash'        => $object_hash,
\t\t\t'purpose'            => $purpose,
\t\t\t'institutional_role' => $role,
\t\t\t'plugin'             => 'file-01',
\t\t\t'contract'           => SMC_FILE01_FOUNDATION_CONTRACT_VERSION,
\t\t\t'suspended'          => ! empty( $assertions['hard_blocked'] ) || ! empty( $assertions['suspended'] ),
\t\t\t'revoked'            => ! empty( $assertions['revoked'] ),
\t\t);
\t}

\tprivate static function file01_required_capability( $action ) {
\t\t$action = sanitize_key( $action );
\t\tif ( in_array( $action, array( 'view', 'system_check' ), true ) ) {
\t\t\treturn 'view_sabri_foundation';
\t\t}
\t\tif ( 'run_system_check' === $action ) {
\t\t\treturn 'manage_sabri_foundation';
\t\t}
\t\tif ( in_array( $action, array( 'record_release', 'transition_release', 'run_reconciliation', 'run_schema_upgrade' ), true ) ) {
\t\t\treturn 'release_sabri_foundation';
\t\t}
\t\tif ( in_array( $action, array( 'approve_release', 'deploy_release', 'approve_amendment', 'production_cutover' ), true ) ) {
\t\t\treturn 'govern_sabri_foundation';
\t\t}
\t\tif ( 'purge' === $action ) {
\t\t\treturn 'purge_sabri_foundation';
\t\t}
\t\treturn '';
\t}
'''
if 'public static function file01_authorization_claim' not in s:
    if marker not in s:
        raise SystemExit('authorization insertion marker not found')
    s=s.replace(marker,'\n'+bridge+marker,1)
auth.write_text(s,encoding='utf-8')

# WordPress readme: advance only current metadata, retain 1.2.41 history.
s=readme.read_text(encoding='utf-8')
s=s.replace('Stable tag: 1.2.41','Stable tag: 1.2.42',1)
changelog='''= 1.2.42 =
* Live-first File 01 authorization compatibility release: File 00 now provides the structured `spf_file00_authorization_claim` contract required by File 01 v2.0.0.
* Claims are exact actor/action/capability/object-hash/purpose/plugin/contract bound, expire after 60 seconds, preserve File 00 hard-block/eligibility authority, allow Founder governance, and limit ordinary Administrators to File 01 view/System Check operations.
* No legacy boolean bridge is introduced. Runtime 1.2.42; DB schema 1.4.5; public membership contract 1.2.3; File 01 authorization claim 1.0.0 for Foundation contract 2.0.0. Live resolution still requires deployment and live re-test.

'''
if '= 1.2.42 =' not in s:
    s=s.replace('== Changelog ==\n\n','== Changelog ==\n\n'+changelog,1)
readme.write_text(s,encoding='utf-8')

# Current QA/workflows use current runtime identity. Preserve historical next-ten review separately.
for folder in [root/'.github/workflows', root/'qa']:
    for p in folder.rglob('*'):
        if not p.is_file() or p.name in {'next-ten-round-contract.mjs','apply-file01-auth-bridge-v1.2.42.yml'}:
            continue
        if p.suffix not in {'.yml','.yaml','.mjs','.php','.json'}:
            continue
        text=p.read_text(encoding='utf-8')
        if old in text:
            p.write_text(text.replace(old,new),encoding='utf-8')

# Historical next-ten review remains 1.2.41 evidence while current identity advances.
p=root/'qa/next-ten-round-contract.mjs'
t=p.read_text(encoding='utf-8')
t=t.replace("check(main.includes('Version: 1.2.41') && main.includes(\"define( 'SMC_VERSION', '1.2.41' );\"), 'corrected runtime identity is 1.2.41');",
            "check(main.includes('Version: 1.2.42') && main.includes(\"define( 'SMC_VERSION', '1.2.42' );\"), 'current runtime identity is 1.2.42');")
t=t.replace("check(pkg.version === '1.2.41' && pkg.scripts?.verify?.includes('00-sabri-membership-core-1.2.41.zip'), 'package and deterministic verify identity are 1.2.41');",
            "check(pkg.version === '1.2.42' && pkg.scripts?.verify?.includes('00-sabri-membership-core-1.2.42.zip'), 'package and deterministic verify identity are 1.2.42');")
t=t.replace("check(readme.includes('Stable tag: 1.2.41') && readme.includes('= 1.2.41 ='), 'WordPress release metadata is 1.2.41');",
            "check(readme.includes('Stable tag: 1.2.42') && readme.includes('= 1.2.42 =') && readme.includes('= 1.2.41 ='), 'WordPress current metadata is 1.2.42 and 1.2.41 history is retained');")
p.write_text(t,encoding='utf-8')

# package metadata and permanent bridge QA.
pkg_path=root/'package.json'
pkg=json.loads(pkg_path.read_text(encoding='utf-8'))
pkg['version']=new
extra=' && node qa/file01-foundation-auth-bridge-contract.mjs && php qa/file01-foundation-auth-bridge-runtime.php'
if 'file01-foundation-auth-bridge-contract.mjs' not in pkg['scripts']['test']:
    pkg['scripts']['test'] += extra
pkg['scripts']['verify']=f"npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-{new}.zip"
pkg_path.write_text(json.dumps(pkg,indent=2)+'\n',encoding='utf-8')

lock_path=root/'package-lock.json'
lock=json.loads(lock_path.read_text(encoding='utf-8'))
lock['version']=new
lock['packages']['']['version']=new
lock_path.write_text(json.dumps(lock,indent=2)+'\n',encoding='utf-8')

# Master plan current identity plus explicit live-first bridge correction.
s=master.read_text(encoding='utf-8')
s=s.replace('Runtime implementation release: `1.2.41`','Runtime implementation release: `1.2.42`',1)
section='''## Live-proven File 01 authorization bridge correction — 1.2.42

Hostinger live evidence on 11 August 2026 froze deployed File 00 `1.2.39`, DB `1.4.4`, and deployed File 01 `2.0.0`. The complete deployed File 00 payload matched the exact `1.2.39` repository manifest, while deployed File 01 source proved that `SPF_Authorization::can()` requires a structured `spf_file00_authorization_claim` when File 00 is present and otherwise fails closed. A full search of the exact deployed File 00 package proved that no such provider existed. The resulting live symptom was File 01 Foundation Status returning `Unauthorized.` before System Check could render.

Release `1.2.42` corrects the canonical owner rather than weakening File 01. File 00 now provides a versioned `1.0.0` structured authorization claim only for File 01 Foundation contract `2.0.0`. The claim is bound to the current actor, exact action/capability, File 01 object hash, purpose, plugin identity and contract, is limited to a 60-second lifetime, and carries File 00's current hard-block/effective-eligibility result. Founder authority may satisfy all recognized File 01 governance actions; ordinary WordPress Administrators may receive only `view`, `system_check`, and `run_system_check`. Unsupported identities/actions/contracts remain fail-closed. No legacy boolean authorization bridge is introduced.

The DB schema remains `1.4.5`; the public membership contract remains `1.2.3`; CF-01 remains `1.1.0`; Advanced Trust remains `1.0.0`. Repository tests/package/CI are not live resolution. After merge, the exact `1.2.42` package must be deployed and the live File 01 Foundation Status/System Check must be re-tested before the incident can be marked Resolved.

'''
if '## Live-proven File 01 authorization bridge correction — 1.2.42' not in s:
    s=s.replace('## Current evidence\n',section+'## Current evidence\n',1)
    s=s.replace('## Current evidence\n','## Current evidence\n\n- `RELEASE-1.2.42.md`\n- `qa/file01-foundation-auth-bridge-contract.mjs`\n- `qa/file01-foundation-auth-bridge-runtime.php`\n',1)
master.write_text(s,encoding='utf-8')

(root/'qa/file01-foundation-auth-bridge-contract.mjs').write_text(r'''import fs from 'node:fs';
const root='source/sabri-membership-core';
const main=fs.readFileSync(`${root}/sabri-membership-core.php`,'utf8');
const auth=fs.readFileSync(`${root}/includes/class-smc-authorization.php`,'utf8');
const failures=[]; let passed=0; const check=(ok,label)=>ok?passed++:failures.push(label);
check(main.includes('Version: 1.2.42') && main.includes("define( 'SMC_VERSION', '1.2.42' );"),'runtime 1.2.42');
check(main.includes("SMC_FILE01_AUTH_CLAIM_VERSION', '1.0.0'") && main.includes("SMC_FILE01_FOUNDATION_CONTRACT_VERSION', '2.0.0'"),'File 01 bridge contract constants');
check(auth.includes("add_filter( 'spf_file00_authorization_claim', array( __CLASS__, 'file01_authorization_claim' ), 10, 2 )"),'structured File 01 claim provider registered');
check(!auth.includes("add_filter( 'spf_file00_capability_claim'"),'no legacy boolean bridge added');
for (const needle of ['user_id','actor_id','action','capability','object_hash','purpose','plugin','contract','current_time']) check(auth.includes(needle),`request binding ${needle}`);
check(auth.includes("'file-01' !== $plugin") && auth.includes('SMC_FILE01_FOUNDATION_CONTRACT_VERSION !== $contract'),'plugin and contract fail closed');
check(auth.includes("preg_match( '/^[a-f0-9]{64}$/'") && auth.includes('abs( time() - $request_time ) > 120'),'object hash and freshness validated');
check(auth.includes("array( 'view', 'system_check', 'run_system_check' )") && auth.includes("$is_founder ? 'founder'") && auth.includes("$is_admin ? 'administrator'"),'Founder/Admin scope explicit');
check(auth.includes("$now + 60") && auth.includes("'claim_id'") && auth.includes("'smc-f01:'"),'short-lived unique claim');
check(auth.includes("$assertions['effective_eligible']") && auth.includes("$assertions['hard_blocked']"),'File 00 eligibility and hard block remain authoritative');
if(failures.length){console.error(`file01 auth bridge static: ${passed} PASS / ${failures.length} FAIL`); for(const f of failures) console.error('- '+f); process.exit(1);} console.log(`file01 auth bridge static: ${passed} PASS / 0 FAIL`);
''',encoding='utf-8')

(root/'qa/file01-foundation-auth-bridge-runtime.php').write_text(r'''<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/'); define('ARRAY_A','ARRAY_A');
define('SMC_FILE01_AUTH_CLAIM_VERSION','1.0.0'); define('SMC_FILE01_FOUNDATION_CONTRACT_VERSION','2.0.0');
class WP_User{public $ID;public $allcaps;function __construct($id,$caps=[]){$this->ID=$id;$this->allcaps=$caps;}}
class WP_Error{}
class SMC_Security{public static function decrypt($v,$p,$c=[]){return $v;}}
class SMC_Contracts{public static function assertions($id){return $GLOBALS['assertions'][$id]??[];} public static function requested_types($id){return ['member'];}}
$GLOBALS['current']=1; $GLOBALS['users']=[]; $GLOBALS['assertions']=[]; $GLOBALS['states']=[]; $GLOBALS['founder']=1;
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function get_current_user_id(){return $GLOBALS['current'];}
function get_userdata($id){return $GLOBALS['users'][$id]??false;} function user_can($u,$cap){$id=is_object($u)?$u->ID:(int)$u;return !empty($GLOBALS['users'][$id]->allcaps[$cap]);}
function smc_is_founder($id){return (int)$id===(int)$GLOBALS['founder'];} function smc_is_institutional_account($id){return !empty($GLOBALS['assertions'][$id]['institutional_account']);}
function smc_membership_state($id){return $GLOBALS['states'][$id]??['status'=>'approved'];} function smc_application($id){return ['date_of_birth_enc'=>'age:30','gender'=>'male','residence_country'=>'PK'];}
function smc_age_from_dob($v){return 30;} function smc_effective_minimum_age($g,$c=''){return 15;} function smc_professional_types(){return ['doctor'];} function is_wp_error($v){return $v instanceof WP_Error;}
function wp_generate_uuid4(){static $n=0;return sprintf('00000000-0000-4000-8000-%012d',++$n);} function apply_filters($t,$v,...$a){return $v;} function wp_doing_cron(){return false;} function is_user_logged_in(){return true;} function is_admin(){return true;} function smc_is_membership_page(){return false;}
function add_action(...$a){} function remove_action(...$a){} function add_filter(...$a){} function remove_filter(...$a){} function __($s,$d=''){return $s;} function esc_html__($s,$d=''){return $s;} function wp_unslash($v){return $v;}
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-authorization.php';
$fail=[];$pass=0;function ok($c,$n){global $fail,$pass;if($c)$pass++;else$fail[]=$n;}
function usercase($id,$admin,$eligible=true,$hard=false,$founder=false){$GLOBALS['users'][$id]=new WP_User($id,$admin?['manage_options'=>true]:[]);$GLOBALS['assertions'][$id]=['institutional_account'=>$admin||$founder,'eligible'=>$eligible,'hard_blocked'=>$hard,'guardian_verified'=>true,'email_verified'=>true,'phone_verified'=>true,'status'=>$hard?'suspended':'approved'];$GLOBALS['states'][$id]=['status'=>$hard?'suspended':'approved'];if($founder)$GLOBALS['founder']=$id;}
function req($id,$action,$cap,$purpose=null,$contract='2.0.0',$hash=null){return ['user_id'=>$id,'actor_id'=>$id,'action'=>$action,'capability'=>$cap,'object_hash'=>$hash?:str_repeat('a',64),'purpose'=>$purpose?:$action,'plugin'=>'file-01','contract'=>$contract,'current_time'=>time()];}
usercase(1,true,true,false,true); usercase(2,true,true,false,false); usercase(3,false,true,false,false); usercase(4,true,false,true,true);
$GLOBALS['current']=1;$c=SMC_Authorization::file01_authorization_claim(null,req(1,'view','view_sabri_foundation'));ok(is_array($c)&&$c['allowed']===true&&$c['institutional_role']==='founder','Founder view allowed');ok($c['expires_at']-$c['issued_at']===60,'TTL 60 seconds');ok($c['plugin']==='file-01'&&$c['contract']==='2.0.0'&&$c['object_hash']===str_repeat('a',64),'claim bindings preserved');
$GLOBALS['current']=2;$c=SMC_Authorization::file01_authorization_claim(null,req(2,'view','view_sabri_foundation'));ok($c['allowed']===true&&$c['institutional_role']==='administrator','Administrator view allowed');$c=SMC_Authorization::file01_authorization_claim(null,req(2,'run_system_check','manage_sabri_foundation'));ok($c['allowed']===true,'Administrator persistent System Check allowed');$c=SMC_Authorization::file01_authorization_claim(null,req(2,'record_release','release_sabri_foundation'));ok($c['allowed']===false,'Administrator release denied');
$GLOBALS['current']=1;$c=SMC_Authorization::file01_authorization_claim(null,req(1,'record_release','release_sabri_foundation'));ok($c['allowed']===true,'Founder release allowed');
$GLOBALS['current']=3;$c=SMC_Authorization::file01_authorization_claim(null,req(3,'view','view_sabri_foundation'));ok($c['allowed']===false&&$c['institutional_role']==='member','ordinary member denied');
$GLOBALS['current']=4;$c=SMC_Authorization::file01_authorization_claim(null,req(4,'view','view_sabri_foundation'));ok($c['allowed']===false,'hard-blocked Founder denied');
$GLOBALS['current']=2;ok(null===SMC_Authorization::file01_authorization_claim(null,req(2,'view','manage_sabri_foundation')),'capability mismatch rejected');ok(null===SMC_Authorization::file01_authorization_claim(null,req(2,'view','view_sabri_foundation',null,'9.9.9')),'contract mismatch rejected');ok(null===SMC_Authorization::file01_authorization_claim(null,req(2,'view','view_sabri_foundation',null,'2.0.0','bad')),'bad object hash rejected');$r=req(2,'view','view_sabri_foundation');$r['actor_id']=99;ok(null===SMC_Authorization::file01_authorization_claim(null,$r),'actor mismatch rejected');$r=req(2,'View','view_sabri_foundation');ok(null===SMC_Authorization::file01_authorization_claim(null,$r),'noncanonical action rejected');$r=req(2,'view','view_sabri_foundation');$r['current_time']=time()-121;ok(null===SMC_Authorization::file01_authorization_claim(null,$r),'stale request rejected');$existing=['allowed'=>false];ok(SMC_Authorization::file01_authorization_claim($existing,req(2,'view','view_sabri_foundation'))===$existing,'existing provider result preserved');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "file01 auth bridge runtime: $pass PASS / 0 FAIL\n";
''',encoding='utf-8')

(root/'RELEASE-1.2.42.md').write_text('''# File 00 — Sabri Membership Core 1.2.42

## Live-proven File 01 authorization compatibility correction

Live evidence on 11 August 2026 established that Hostinger had File 00 `1.2.39` with DB `1.4.4` and File 01 `2.0.0`. The uploaded complete File 00 plugin payload matched the exact `1.2.39` manifest, and its full source contained neither `spf_file00_authorization_claim` nor `spf_file00_capability_claim`. The deployed File 01 authorization source matched the repository behavior that requires a structured File 00 claim when File 00 is present and otherwise returns `false`. The observed File 01 Foundation Status symptom was therefore `Unauthorized.` before System Check rendering.

### Correction

File 00 now registers the canonical structured `spf_file00_authorization_claim` provider. It supports File 01 Foundation contract `2.0.0` with claim version `1.0.0`, validates current actor identity, action, exact capability, object hash, purpose, plugin identity, consumer contract and request freshness, and issues a 60-second claim ID. File 00 hard-block and effective-eligibility state remains authoritative. Founder may satisfy all recognized File 01 governance actions; ordinary WordPress Administrators are limited to `view`, `system_check`, and `run_system_check`. Unsupported requests remain fail-closed, and no legacy boolean bridge is added.

### Release identity

- Runtime: `1.2.42`
- DB schema: `1.4.5` (unchanged)
- Public membership contract: `1.2.3` (unchanged)
- CF-01: `1.1.0` (unchanged)
- Advanced Trust: `1.0.0` (unchanged)
- File 01 authorization claim: `1.0.0`
- Supported File 01 Foundation contract: `2.0.0`

### Live boundary

This repository correction is not a live resolution. Resolution requires exact-head CI success, deterministic package creation, deployment of the exact `1.2.42` artifact, confirmation of deployed/package parity, and a live re-test proving File 01 Foundation Status/System Check is accessible to the authorized Founder/Administrator while unauthorized actors remain denied.
''',encoding='utf-8')

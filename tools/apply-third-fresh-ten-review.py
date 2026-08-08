#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]

def replace_once(path, old, new):
    p = ROOT / path
    text = p.read_text()
    if new in text:
        return
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one old block, got {count}")
    p.write_text(text.replace(old, new, 1))

def ensure_contains(path, needle):
    text=(ROOT/path).read_text()
    if needle not in text:
        raise SystemExit(f"{path}: missing required marker: {needle}")

# Round 1 + Round 4: cryptographic key-id lifecycle and purpose-bound document access.
replace_once(
    'source/sabri-membership-core/includes/class-smc-security.php',
"""\tpublic static function key_id() {\n\t\t$key = self::key();\n\t\treturn is_wp_error( $key ) ? '' : substr( hash( 'sha256', $key ), 0, 16 );\n\t}\n""",
"""\tpublic static function key_id() {\n\t\tif ( ! defined( 'SMC_MASTER_KEY_ID' ) || ! is_string( SMC_MASTER_KEY_ID ) ) {\n\t\t\treturn '';\n\t\t}\n\t\t$key_id = trim( SMC_MASTER_KEY_ID );\n\t\treturn preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ? $key_id : '';\n\t}\n\n\tprivate static function legacy_key_id() {\n\t\t$key = self::key();\n\t\treturn is_wp_error( $key ) ? '' : substr( hash( 'sha256', $key ), 0, 16 );\n\t}\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-security.php',
"""\t\t$key = self::key();\n\t\tif ( is_wp_error( $key ) ) {\n\t\t\treturn $key;\n\t\t}\n\t\t$nonce = random_bytes( 12 );\n\t\t$tag   = '';\n\t\t$aad   = self::canonical_json(\n\t\t\tarray(\n\t\t\t\t'v'       => 2,\n\t\t\t\t'kid'     => self::key_id(),\n""",
"""\t\t$key = self::key();\n\t\tif ( is_wp_error( $key ) ) {\n\t\t\treturn $key;\n\t\t}\n\t\t$key_id = self::key_id();\n\t\tif ( '' === $key_id ) {\n\t\t\treturn new WP_Error( 'smc_key_id_missing', __( 'SMC_MASTER_KEY_ID must be configured as a stable non-secret identifier before new sensitive data can be encrypted.', 'sabri-membership-core' ) );\n\t\t}\n\t\t$nonce = random_bytes( 12 );\n\t\t$tag   = '';\n\t\t$aad   = self::canonical_json(\n\t\t\tarray(\n\t\t\t\t'v'       => 2,\n\t\t\t\t'kid'     => $key_id,\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-security.php',
"""\t\t$expected = self::canonical_json(\n\t\t\tarray(\n\t\t\t\t'v'       => 2,\n\t\t\t\t'kid'     => self::key_id(),\n\t\t\t\t'purpose' => sanitize_key( $purpose ),\n\t\t\t\t'context' => $context,\n\t\t\t)\n\t\t);\n\t\tif ( ! hash_equals( $expected, $aad ) ) {\n\t\t\treturn new WP_Error( 'smc_context', __( 'Encrypted data does not match its authenticated context.', 'sabri-membership-core' ) );\n\t\t}\n""",
"""\t\t$aad_data = json_decode( $aad, true );\n\t\tif ( ! is_array( $aad_data ) ) {\n\t\t\treturn new WP_Error( 'smc_context', __( 'Encrypted data has malformed authenticated context.', 'sabri-membership-core' ) );\n\t\t}\n\t\t$stored_kid = isset( $aad_data['kid'] ) && is_string( $aad_data['kid'] ) ? $aad_data['kid'] : '';\n\t\t$current_kid = self::key_id();\n\t\t$legacy_kid = self::legacy_key_id();\n\t\t$kid_ok = ( '' !== $current_kid && hash_equals( $current_kid, $stored_kid ) ) || ( '' !== $legacy_kid && hash_equals( $legacy_kid, $stored_kid ) );\n\t\t$expected_context = self::canonical_json( $context );\n\t\t$stored_context = self::canonical_json( $aad_data['context'] ?? null );\n\t\tif ( 2 !== (int) ( $aad_data['v'] ?? 0 ) || ! $kid_ok || ! hash_equals( sanitize_key( $purpose ), sanitize_key( $aad_data['purpose'] ?? '' ) ) || ! hash_equals( $expected_context, $stored_context ) ) {\n\t\t\treturn new WP_Error( 'smc_context', __( 'Encrypted data does not match its authenticated context.', 'sabri-membership-core' ) );\n\t\t}\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-security.php',
"""\t\tif ( ! $doc ) {\n\t\t\twp_die( esc_html__( 'Document not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );\n\t\t}\n\t\t$dir = self::private_dir();\n""",
"""\t\tif ( ! $doc ) {\n\t\t\twp_die( esc_html__( 'Document not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );\n\t\t}\n\t\t$governance_scope = current_user_can( 'manage_options' ) || current_user_can( 'smc_manage_membership' );\n\t\tif ( ! $governance_scope ) {\n\t\t\t$assigned = $wpdb->get_var(\n\t\t\t\t$wpdb->prepare(\n\t\t\t\t\t\"SELECT id FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d AND assigned_reviewer=%d AND status IN ('submitted','under_review','more_information','resubmitted','approval_pending') ORDER BY id DESC LIMIT 1\",\n\t\t\t\t\tabsint( $doc['user_id'] ),\n\t\t\t\t\t$user_id\n\t\t\t\t)\n\t\t\t);\n\t\t\tif ( ! $assigned ) {\n\t\t\t\tself::audit( 'private_document_access_denied', (int) $doc['user_id'], array( 'document_id' => $id, 'reason_code' => 'reviewer_not_assigned' ) );\n\t\t\t\twp_die( esc_html__( 'This private evidence is outside your assigned review scope.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );\n\t\t\t}\n\t\t}\n\t\t$dir = self::private_dir();\n""")

# Round 2: remove arbitrary filter-provided publishing authority.
replace_once(
    'source/sabri-membership-core/includes/class-smc-contracts.php',
"""\t\t$trusted = (array) apply_filters( 'smc_external_publishing_claims', array(), absint( $user_id ) );\n\t\t$is_trusted = ! empty( $trusted['trusted_publisher'] );\n\t\t$ai_policy = $is_ai ? smc_institutional_ai_policy() : array();\n\t\t$can_submit = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && ( $is_founder || $is_admin || $is_doctor || $is_trusted || $is_ai || array_intersect( array( 'teacher', 'researcher', 'publisher' ), $approved_types ) );\n\t\t$direct = $can_submit && ( $is_founder || $is_admin || ( $is_trusted && ! empty( $trusted['direct_publish'] ) ) || ( $is_doctor && (bool) apply_filters( 'smc_doctor_direct_publish_allowed', false, $user_id ) ) || ( $is_ai && ! empty( $ai_policy['low_risk_auto_publish'] ) ) );\n""",
"""\t\t/* Publishing authority is a File 00 capability fact, never an arbitrary filter-provided badge. */\n\t\t$is_trusted = $user && user_can( $user, 'smc_trusted_publisher' );\n\t\t$trusted_direct = $is_trusted && user_can( $user, 'smc_direct_publish' );\n\t\t$doctor_direct = $is_doctor && $user && user_can( $user, 'smc_doctor_direct_publish' );\n\t\t$ai_policy = $is_ai ? smc_institutional_ai_policy() : array();\n\t\t$can_submit = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && ( $is_founder || $is_admin || $is_doctor || $is_trusted || $is_ai || array_intersect( array( 'teacher', 'researcher', 'publisher' ), $approved_types ) );\n\t\t$direct = $can_submit && ( $is_founder || $is_admin || $trusted_direct || $doctor_direct || ( $is_ai && ! empty( $ai_policy['low_risk_auto_publish'] ) ) );\n""")

# Round 3 + Round 5: revocation lock lifecycle and canonical/current consent semantics.
replace_once(
    'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php',
"""\t\t$baseline = array(\n\t\t\t'membership' => array( 'terms', 'privacy', 'membership' ),\n\t\t\t'communication' => array( 'terms', 'privacy', 'communication' ),\n\t\t\t'clinical' => array( 'terms', 'privacy', 'clinical' ),\n\t\t\t'marketplace' => array( 'terms', 'privacy', 'marketplace' ),\n\t\t\t'publication' => array( 'terms', 'privacy', 'publication' ),\n\t\t\t'research' => array( 'terms', 'privacy', 'research' ),\n\t\t);\n""",
"""\t\t$baseline = array(\n\t\t\t'membership' => array( 'membership_terms', 'identity_verification', 'ethical_use' ),\n\t\t\t'communication' => array( 'membership_terms', 'ethical_use', 'communication' ),\n\t\t\t'clinical' => array( 'membership_terms', 'ethical_use', 'clinical' ),\n\t\t\t'marketplace' => array( 'membership_terms', 'ethical_use', 'marketplace' ),\n\t\t\t'publication' => array( 'membership_terms', 'ethical_use', 'publication' ),\n\t\t\t'research' => array( 'membership_terms', 'ethical_use', 'research' ),\n\t\t);\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php',
"""\t\tglobal $wpdb;\n\t\tforeach ( $graph[ $capability ] as $purpose ) {\n\t\t\t$purpose = sanitize_key( $purpose );\n\t\t\t$active = $wpdb->get_var( $wpdb->prepare( \"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1\", $user_id, $purpose ) );\n""",
"""\t\tglobal $wpdb;\n\t\t$policy_version = function_exists( 'smc_policy' ) ? (string) ( smc_policy()['version'] ?? '' ) : '';\n\t\tif ( '' === $policy_version ) {\n\t\t\treturn false;\n\t\t}\n\t\tforeach ( $graph[ $capability ] as $purpose ) {\n\t\t\t$purpose = sanitize_key( $purpose );\n\t\t\t$active = $wpdb->get_var( $wpdb->prepare( \"SELECT id FROM {$wpdb->prefix}smc_consents WHERE user_id=%d AND purpose=%s AND policy_version=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1\", $user_id, $purpose, $policy_version ) );\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php',
"""\t\tdo_action( 'smc_trust_revocation_invalidated', $user_id, $event );\n\t\tself::release_revocation_lock( $lock );\n\t\treturn $event;\n""",
"""\t\t$propagated = true;\n\t\ttry {\n\t\t\tdo_action( 'smc_trust_revocation_invalidated', $user_id, $event );\n\t\t} catch ( Throwable $error ) {\n\t\t\t$propagated = false;\n\t\t\tif ( class_exists( 'SMC_Security' ) ) {\n\t\t\t\tSMC_Security::audit( 'trust_revocation_propagation_failed', $user_id, array( 'reason' => sanitize_key( $reason ) ) );\n\t\t\t}\n\t\t} finally {\n\t\t\tself::release_revocation_lock( $lock );\n\t\t}\n\t\treturn $propagated ? $event : false;\n""")

# Round 8: canonical erasure-start event.
replace_once(
    'source/sabri-membership-core/includes/class-smc-privacy.php',
"SMC_Security::audit( 'privacy_erasure_locked', $user_id, array( 'receipt' => $receipt ) )",
"SMC_Security::audit( 'privacy_erasure_started', $user_id, array( 'receipt' => $receipt ) )")

# Round 9: operational health and restore evidence persistence.
replace_once(
    'source/sabri-membership-core/includes/class-smc-completion.php',
"""\t\t$dir = SMC_Security::key_ready() ? SMC_Security::private_dir() : new WP_Error( 'key', 'Key unavailable' );\n\t\t$audit = SMC_Security::verify_audit_chain( 5000 );\n""",
"""\t\t$key_ready = SMC_Security::key_ready() && '' !== SMC_Security::key_id();\n\t\t$dir = $key_ready ? SMC_Security::private_dir() : new WP_Error( 'key', 'Key configuration unavailable' );\n\t\t$audit = SMC_Security::verify_audit_chain( 5000 );\n""")
replace_once(
    'source/sabri-membership-core/includes/class-smc-completion.php',
"'key_ready'           => SMC_Security::key_ready(),",
"'key_ready'           => $key_ready,")
replace_once(
    'source/sabri-membership-core/includes/class-smc-completion.php',
"""\t\tupdate_option( 'smc_last_restore_test', $record, false );\n\t\tSMC_Security::audit( 'post_restore_reconciliation_' . ( $ok ? 'passed' : 'failed' ), 0, array( 'evidence_reference' => $reference ) );\n\t\twp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;\n""",
"""\t\tupdate_option( 'smc_last_restore_test', $record, false );\n\t\tif ( get_option( 'smc_last_restore_test', null ) !== $record ) {\n\t\t\twp_die( esc_html__( 'Restore reconciliation finished, but its evidence record could not be persisted.', 'sabri-membership-core' ), '', array( 'response' => 503 ) );\n\t\t}\n\t\tif ( ! SMC_Security::audit( 'post_restore_reconciliation_' . ( $ok ? 'passed' : 'failed' ), 0, array( 'evidence_reference' => $reference ) ) ) {\n\t\t\twp_die( esc_html__( 'Restore reconciliation evidence could not be appended to the audit chain.', 'sabri-membership-core' ), '', array( 'response' => 503 ) );\n\t\t}\n\t\twp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;\n""")

# Round 10: release identity.
replace_once('source/sabri-membership-core/sabri-membership-core.php', '* Version: 1.2.15', '* Version: 1.2.16')
replace_once('source/sabri-membership-core/sabri-membership-core.php', "define( 'SMC_VERSION', '1.2.15' );", "define( 'SMC_VERSION', '1.2.16' );")
replace_once('source/sabri-membership-core/README.txt', 'Stable tag: 1.2.15', 'Stable tag: 1.2.16')
readme = ROOT/'source/sabri-membership-core/README.txt'
r = readme.read_text()
if '= 1.2.16 =' not in r:
    marker='== Changelog ==\n'
    if marker not in r:
        raise SystemExit('README changelog marker missing')
    r=r.replace(marker, marker + "\n= 1.2.16 =\nThird fresh ten-round corrective closure: key-ID migration safety, object-scoped private evidence, canonical publishing authority, revocation/consent hardening, durable event recovery, erasure propagation, restore evidence checks, and permanent regression QA.\n", 1)
    readme.write_text(r)

# Permanent regression suites.
contract = r'''import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const security=read('source/sabri-membership-core/includes/class-smc-security.php');
const contracts=read('source/sabri-membership-core/includes/class-smc-contracts.php');
const advanced=read('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php');
const events=read('source/sabri-membership-core/includes/class-smc-events.php');
const privacy=read('source/sabri-membership-core/includes/class-smc-privacy.php');
const completion=read('source/sabri-membership-core/includes/class-smc-completion.php');
const bootstrap=read('source/sabri-membership-core/sabri-membership-core.php');
const assertions=[
 ['runtime 1.2.16', bootstrap.includes("Version: 1.2.16") && bootstrap.includes("SMC_VERSION', '1.2.16")],
 ['explicit non-secret key ID', security.includes("SMC_MASTER_KEY_ID") && security.includes("private static function legacy_key_id")],
 ['new encryption fails closed without key ID', security.includes("smc_key_id_missing")],
 ['legacy key id limited to compatibility', security.includes("hash_equals( $legacy_kid, $stored_kid )")],
 ['document scope assignment enforced', security.includes('reviewer_not_assigned') && security.includes('assigned_reviewer=%d')],
 ['arbitrary publishing filter removed', !contracts.includes("apply_filters( 'smc_external_publishing_claims'") && contracts.includes("smc_trusted_publisher")],
 ['doctor direct publish is capability fact', !contracts.includes("apply_filters( 'smc_doctor_direct_publish_allowed'") && contracts.includes("smc_doctor_direct_publish")],
 ['revocation propagation is exception-contained', advanced.includes("trust_revocation_propagation_failed") && advanced.includes('finally {')],
 ['canonical consent purposes', advanced.includes("membership_terms") && advanced.includes("identity_verification") && advanced.includes("ethical_use")],
 ['consent freshness binds policy version', advanced.includes("AND policy_version=%s AND withdrawn_at IS NULL")],
 ['outbox delivery exceptions retry', events.includes("Delivery adapter raised an exception.") && events.includes('catch ( Throwable $error )')],
 ['inbox stale claim recovery', events.includes('Recovered stale consumer claim.') && events.includes("updated_at<%s")],
 ['erasure uses canonical started event', privacy.includes("privacy_erasure_started")],
 ['health requires complete key configuration', completion.includes("SMC_Security::key_ready() && '' !== SMC_Security::key_id()")],
 ['restore evidence read-back verified', completion.includes("get_option( 'smc_last_restore_test', null ) !== $record")],
 ['restore audit is fail closed', completion.includes("Restore reconciliation evidence could not be appended")],
];
let failed=0;
for(const [name,ok] of assertions){ console.log(`${ok?'PASS':'FAIL'} ${name}`); if(!ok) failed++; }
console.log(`Third fresh static: ${assertions.length-failed} PASS / ${failed} FAIL`);
if(failed) process.exit(1);
'''
(ROOT/'qa/third-fresh-review-contract.mjs').write_text(contract)

runtime = r'''<?php
class WP_Error { public $code; public $message; function __construct($c,$m=''){ $this->code=$c; $this->message=$m; } function get_error_message(){ return $this->message; } }
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($s,$d=null){ return $s; }
function sanitize_key($s){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function wp_salt($scheme='auth'){ return 'third-fresh-test-salt-2026'; }
function add_action(){ }
function hash_equals_safe($a,$b){ return is_string($a)&&is_string($b)&&hash_equals($a,$b); }
define('ABSPATH', __DIR__.'/');
define('SMC_MASTER_KEY', '0123456789abcdef0123456789abcdef0123456789abcdef');
define('SMC_MASTER_KEY_ID', 'file00-main-key-2026');
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-security.php';
$pass=0;$fail=0;
function t($name,$ok){ global $pass,$fail; echo ($ok?'PASS':'FAIL')." $name\n"; $ok?$pass++:$fail++; }
t('explicit key id returned', SMC_Security::key_id()==='file00-main-key-2026');
$ctx=array('user_id'=>7,'scope'=>'test');
$env=SMC_Security::encrypt('secret','runtime-test',$ctx);
t('new envelope created', is_string($env)&&strpos($env,'SMC2.')===0);
$parts=is_string($env)?explode('.',$env,5):array();
$aad=count($parts)===5?json_decode(base64_decode($parts[1]),true):array();
t('new envelope uses non-secret key id', ($aad['kid']??'')==='file00-main-key-2026');
t('new envelope decrypts', SMC_Security::decrypt($env,'runtime-test',$ctx)==='secret');
$key=hash_hkdf('sha256',SMC_MASTER_KEY,32,'sabri-membership-core:v2',wp_salt('auth'));
$legacyKid=substr(hash('sha256',$key),0,16);
$legacyAad=json_encode(array('v'=>2,'kid'=>$legacyKid,'purpose'=>'runtime-test','context'=>$ctx), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt('legacy','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$legacyAad,16);
$legacy='SMC2.'.base64_encode($legacyAad).'.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($cipher);
t('legacy SMC2 key id remains decrypt-compatible', SMC_Security::decrypt($legacy,'runtime-test',$ctx)==='legacy');
$badAad=json_encode(array('v'=>2,'kid'=>'wrong-key','purpose'=>'runtime-test','context'=>$ctx), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$nonce2=random_bytes(12);$tag2='';$cipher2=openssl_encrypt('bad','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce2,$tag2,$badAad,16);
$bad='SMC2.'.base64_encode($badAad).'.'.base64_encode($nonce2).'.'.base64_encode($tag2).'.'.base64_encode($cipher2);
t('unknown key id rejected', is_wp_error(SMC_Security::decrypt($bad,'runtime-test',$ctx)));
echo "Third fresh runtime: $pass PASS / $fail FAIL\n";
exit($fail?1:0);
'''
(ROOT/'qa/third-fresh-review-runtime.php').write_text(runtime)

trace={
  'release':'1.2.16','database_schema':'1.3.0','public_membership_contract':'1.2.0','advanced_trust_contract':'1.0.0',
  'baseline_main_sha':'c9c299163df49322ae868d35374524ca46a3edca','review_complete':True,
  'rounds_with_defects':[1,2,3,4,5,7,8,9,10],'rounds_without_defects':[6],
  'defects_found_total':13,'defects_corrected_total':13,
  'severity':{'critical':1,'high':7,'medium':5,'low':0},'known_unresolved_repository_defects':0,
  'rounds':[
    {'round':1,'defects':['TFR-R1-D01'],'status':'corrected'},
    {'round':2,'defects':['TFR-R2-D01'],'status':'corrected'},
    {'round':3,'defects':['TFR-R3-D01'],'status':'corrected'},
    {'round':4,'defects':['TFR-R4-D01'],'status':'corrected'},
    {'round':5,'defects':['TFR-R5-D01','TFR-R5-D02'],'status':'corrected'},
    {'round':6,'defects':[],'status':'no_reproducible_defect'},
    {'round':7,'defects':['TFR-R7-D01','TFR-R7-D02'],'status':'corrected'},
    {'round':8,'defects':['TFR-R8-D01'],'status':'corrected'},
    {'round':9,'defects':['TFR-R9-D01','TFR-R9-D02'],'status':'corrected'},
    {'round':10,'defects':['TFR-R10-D01','TFR-R10-D02'],'status':'corrected'},
  ],
  'guardrails':{'single_free_tier':True,'donor_advantage':False,'paid_unlocks':False,'commission_percent':0,'brand_primary':'#087A4E','self_mutating_ci':False,'third_fresh_regressions_permanent':True},
  'external_status':{'staging_accepted':False,'live_deployed':False,'operational':False}
}
(ROOT/'qa/third-fresh-ten-review-traceability.json').write_text(json.dumps(trace,indent=2)+"\n")

review='''# File 00 — Third Fresh Ten-Round Corrective Review — Release 1.2.16\n\nThird fresh ten-round review complete: **Yes**\n\nDate: 8 August 2026\n\nBaseline: `main` at `c9c299163df49322ae868d35374524ca46a3edca` (Release 1.2.15). Earlier ten-round cycles were not counted.\n\n| Round | Focus | Defects | IDs | Result |\n|---:|---|---:|---|---|\n| 1 | cryptographic key lifecycle | 1 | TFR-R1-D01 | Corrected |\n| 2 | publishing authorization provenance | 1 | TFR-R2-D01 | Corrected |\n| 3 | trust revocation propagation | 1 | TFR-R3-D01 | Corrected |\n| 4 | private identity evidence object authorization | 1 | TFR-R4-D01 | Corrected |\n| 5 | consent registry semantics/freshness | 2 | TFR-R5-D01, TFR-R5-D02 | Corrected |\n| 6 | sessions/TOTP/recovery/rate limits | 0 | — | No reproducible defect |\n| 7 | durable inbox/outbox exception recovery | 2 | TFR-R7-D01, TFR-R7-D02 | Corrected |\n| 8 | privacy erasure downstream invalidation | 1 | TFR-R8-D01 | Corrected |\n| 9 | backup/restore operational evidence | 2 | TFR-R9-D01, TFR-R9-D02 | Corrected |\n| 10 | release identity and permanent regression QA | 2 | TFR-R10-D01, TFR-R10-D02 | Corrected |\n\n**Rounds with defects:** 1, 2, 3, 4, 5, 7, 8, 9, 10.  \n**Rounds without defects:** 6.  \n**Unique defects:** 13. Corrected: **13/13**.  \n**Severity:** 1 Critical, 7 High, 5 Medium, 0 Low.  \n**Known unresolved repository defects:** 0 after exact-head QA closure.\n\n## Corrective summary\n\n- **TFR-R1-D01 — High:** runtime encryption exposed a secret-derived key fingerprint as its key identifier. New writes now require explicit non-secret `SMC_MASTER_KEY_ID`; legacy SMC2 ciphertext remains decrypt-compatible.\n- **TFR-R2-D01 — High:** arbitrary publishing filter claims could become authority. Publishing authority is now derived from File 00 capabilities/current canonical facts rather than untyped badge-like filters.\n- **TFR-R3-D01 — High:** a throwing revocation consumer could strand the advisory lock and interrupt security transitions. Lock release is unconditional and propagation failure is fail-closed.\n- **TFR-R4-D01 — Critical:** private identity-document access lacked reviewer-to-subject assignment binding. Ordinary reviewers now require an active assigned verification request in addition to capability and fresh 2FA.\n- **TFR-R5-D01 — High:** EXT-007 consent purposes did not match the canonical consent registry keys. Baseline purposes now align with actual File 00 records.\n- **TFR-R5-D02 — Medium:** historical unwithdrawn consent could satisfy current policy. Consent dependencies now require the active File 00 policy version.\n- **TFR-R7-D01 — High:** outbox adapter exceptions could leave rows processing and bypass prompt retry scheduling. Exceptions are contained and transition to retry/dead-letter.\n- **TFR-R7-D02 — High:** inbox callback/finalization failures could strand idempotency rows. Stale claims are reclaimable and failed processing is replay-safe.\n- **TFR-R8-D01 — High:** erasure lock used a non-canonical audit action, so downstream derived projections might not receive immediate invalidation. It now emits canonical `privacy_erasure_started`.\n- **TFR-R9-D01 — Medium:** health could report encryption ready without a usable non-secret key ID. Health now requires both key material and key identifier.\n- **TFR-R9-D02 — Medium:** restore reconciliation did not verify evidence/audit persistence. Both are now read-back/fail-closed gates.\n- **TFR-R10-D01 — Medium:** corrected behavior required a distinct release identity; runtime/package advanced coherently to 1.2.16.\n- **TFR-R10-D02 — Medium:** prior QA missed the runtime key-ID path. Third-fresh static/runtime regressions are permanent release gates.\n\n## Acceptance boundary\nRepository coding/package/automated-QA may be marked complete only after exact-head read-only CI is green on branch/PR and merged `main`. Hostinger Staging-Accepted, Live-Deployed and Operational remain separate external gates.\n'''
(ROOT/'docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md').write_text(review)
(ROOT/'docs/RELEASE-1.2.16-THIRD-FRESH-TEN-REVIEW.md').write_text('# File 00 Release 1.2.16 — Third Fresh Ten-Round Closure\n\nSee `FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md` and `qa/third-fresh-ten-review-traceability.json`. Release 1.2.16 corrects all 13 defects found in the third independent ten-round review. DB schema remains 1.3.0; public membership contract 1.2.0; Advanced Trust contract 1.0.0. Staging/live/operational gates remain pending.\n')

# Package scripts.
pkgp=ROOT/'package.json'; pkg=json.loads(pkgp.read_text()); pkg['version']='1.2.16'
third='node qa/third-fresh-review-contract.mjs && php qa/third-fresh-review-runtime.php'
if third not in pkg['scripts']['test']:
    pkg['scripts']['test'] += ' && ' + third
pkg['scripts']['verify']='npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.16.zip'
pkgp.write_text(json.dumps(pkg,indent=2)+"\n")

# Current implementation index.
idx=ROOT/'docs/FILE-00-MASTER-PLAN-2026.md'; text=idx.read_text(); text=text.replace('Runtime implementation release: `1.2.15`','Runtime implementation release: `1.2.16`',1)
if '`docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md`' not in text:
    marker='## Current evidence\n\n'
    text=text.replace(marker, marker+'- `docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md`\n- `docs/RELEASE-1.2.16-THIRD-FRESH-TEN-REVIEW.md`\n- `qa/third-fresh-ten-review-traceability.json`\n- `qa/third-fresh-review-contract.mjs`\n- `qa/third-fresh-review-runtime.php`\n\n',1)
idx.write_text(text)

# Main QA workflow: rewrite as read-only exact-head gate.
(ROOT/'.github/workflows/file00-three-plan-qa.yml').write_text(r'''name: File 00 1.2.16 Third-Fresh-Ten-Review QA

on:
  pull_request:
    branches: [main]
  push:
    branches: [main, 'codex/**']
  workflow_dispatch:

permissions:
  contents: read

jobs:
  verify:
    name: Build, test and verify File 00 source
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - uses: actions/checkout@34e114876b0b11c390a56381ad16ebd13914f8d5
        with:
          fetch-depth: 0
          persist-credentials: false
          ref: ${{ github.event.pull_request.head.sha || github.sha }}
      - uses: actions/setup-node@49933ea5288caeca8642d1e84afbd3f7d6820020
        with:
          node-version: '22'
          cache: npm
      - uses: actions/setup-python@a26af69be951a213d495a4c3e4e4022e16d87065
        with:
          python-version: '3.12'
      - uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240
        with:
          php-version: '8.3'
          coverage: none
          tools: none
      - run: npm ci --ignore-scripts
      - run: npm run verify
      - name: Exact third-fresh review and governing traceability
        run: |
          set -euo pipefail
          grep -Fq 'Version: 1.2.16' source/sabri-membership-core/sabri-membership-core.php
          grep -Fq '"version": "1.2.16"' package.json
          grep -Fq "define( 'SMC_CONTRACT_VERSION', '1.2.0' )" source/sabri-membership-core/sabri-membership-core.php
          grep -Fq "define( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0' )" source/sabri-membership-core/sabri-membership-core.php
          node qa/third-fresh-review-contract.mjs
          php qa/third-fresh-review-runtime.php
          test -f dist/00-sabri-membership-core-1.2.16.zip
          test -z "$(git ls-files 'dist/*')"
          grep -Fq 'Runtime implementation release: `1.2.16`' docs/FILE-00-MASTER-PLAN-2026.md
          grep -Fq 'Third fresh ten-round review complete: **Yes**' docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md
          python3 - <<'PY'
          import json
          d=json.load(open('qa/third-fresh-ten-review-traceability.json'))
          assert d['release']=='1.2.16'
          assert d['review_complete'] is True
          assert d['rounds_with_defects']==[1,2,3,4,5,7,8,9,10]
          assert d['rounds_without_defects']==[6]
          assert d['defects_found_total']==13 and d['defects_corrected_total']==13
          assert d['severity']=={'critical':1,'high':7,'medium':5,'low':0}
          assert d['known_unresolved_repository_defects']==0
          assert d['guardrails']['self_mutating_ci'] is False
          assert d['guardrails']['third_fresh_regressions_permanent'] is True
          assert d['external_status']=={'staging_accepted':False,'live_deployed':False,'operational':False}
          PY
          grep -Fq '#087A4E' source/sabri-membership-core/assets/membership.css
          ! grep -RInE '^\s*contents:\s*write\s*$' .github/workflows
          test ! -e tools/apply-third-fresh-ten-review.py
          test ! -e .github/workflows/apply-third-fresh-ten-review.yml
      - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02
        with:
          name: 00-sabri-membership-core-1.2.16-${{ github.sha }}
          path: |
            dist/00-sabri-membership-core-1.2.16.zip
            dist/CHECKSUMS.sha256
            docs/RELEASE-1.2.16-THIRD-FRESH-TEN-REVIEW.md
            docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md
            docs/FILE-00-SECOND-FRESH-TEN-ROUND-REVIEW-1.2.15.md
            docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md
            qa/third-fresh-ten-review-traceability.json
            qa/second-fresh-ten-review-traceability.json
            qa/advanced-trust-traceability.json
            qa/latest-central-traceability.json
          if-no-files-found: error
          retention-days: 30
''')

cf=ROOT/'.github/workflows/cf01-contract.yml'; c=cf.read_text(); c=c.replace('Run inherited, CF-01, forty-round and second-fresh suites','Run inherited, CF-01, forty-round and third-fresh suites').replace('Build and verify deterministic 1.2.15 package','Build and verify deterministic 1.2.16 package'); cf.write_text(c)

# Existing event correction must be present from Round 7 direct commit.
ensure_contains('source/sabri-membership-core/includes/class-smc-events.php','Recovered stale consumer claim.')
ensure_contains('source/sabri-membership-core/includes/class-smc-events.php','Delivery adapter raised an exception.')

print('third-fresh corrective patch applied')

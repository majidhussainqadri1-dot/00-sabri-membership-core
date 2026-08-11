<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.35');
define('SMC_CONTRACT_VERSION', '1.2.3');
define('SMC_CF01_CONTRACT_VERSION', '1.1.0');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['meta'] = [];
$GLOBALS['users'] = [1 => (object)['roles' => ['sabri_member']], 2 => (object)['roles' => ['sabri_doctor_verified']]];
$GLOBALS['states'] = [];
$GLOBALS['apps'] = [];
$GLOBALS['base'] = [];
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }
function get_userdata($id){ return $GLOBALS['users'][(int)$id] ?? false; }
function get_user_meta($id,$key,$single=true){ return $GLOBALS['meta'][(int)$id][$key] ?? ''; }
function add_user_meta($id,$key,$value,$unique=false){ if($unique && isset($GLOBALS['meta'][(int)$id][$key])) return false; $GLOBALS['meta'][(int)$id][$key]=$value; return true; }
function delete_user_meta($id,$key,$value=''){ if(!isset($GLOBALS['meta'][(int)$id][$key])) return false; if($value!=='' && $GLOBALS['meta'][(int)$id][$key]!==$value) return false; unset($GLOBALS['meta'][(int)$id][$key]); return true; }
function wp_generate_uuid4(){ static $n=1; return sprintf('00000000-0000-4000-8000-%012d',$n++); }
function smc_membership_state($id){ return $GLOBALS['states'][(int)$id]; }
function smc_application($id){ return $GLOBALS['apps'][(int)$id] ?? false; }
function smc_policy(){ return ['version'=>'2026-08-10']; }
function smc_age_from_dob($dob){ return $dob === '2010-01-01' ? 16 : 30; }
function is_wp_error($v){ return $v instanceof WP_Error; }
class WP_Error { public function __construct($code=''){} }
class SMC_Contracts { public static function assertions($id){ return $GLOBALS['base'][(int)$id]; } }
class SMC_Security {
  public static function audit($event,$id,$context=[]){ return true; }
  public static function decrypt($value,$purpose,$context=[]){ return $value; }
  public static function blind_index($value,$purpose){ return hash('sha256',$purpose.'|'.$value); }
}
class FakeWpdb {
  public string $prefix='wp_';
  public function prepare($q,...$args){ return ['q'=>$q,'args'=>$args]; }
  public function get_var($prepared){ return 'PK'; }
}
$GLOBALS['wpdb']=new FakeWpdb();
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
function expect($condition,$label){ if(!$condition){fwrite(STDERR,$label."\n");exit(1);} }

$GLOBALS['states'][1]=['application_exists'=>true];
$GLOBALS['apps'][1]=['row_version'=>7,'guardian_required'=>0,'policy_version'=>'2026-08-10','date_of_birth_enc'=>'1990-01-01'];
$GLOBALS['base'][1]=['account_class'=>'member','membership_type'=>'member','status'=>'approved','eligible'=>true,'suspended'=>false,'guardian_verified'=>true,'professional_verified'=>true,'phone_verified'=>true,'email_verified'=>true,'identity_documents_current'=>true,'can_practice'=>false,'mfa_required'=>false,'mfa_owner'=>'none','two_factor_ready'=>false,'session_two_factor'=>false];
$a=SMC_CF01_Contract::membership_assertion(1,['action'=>'clinical_read','purpose'=>'treatment','jurisdiction'=>'PK']);
expect($a['result']==='allow','Eligible member must satisfy the File 00 membership prerequisite without File 00 MFA.');
expect($a['authorization_scope']==='membership_prerequisite_only','CF-01 allow must be explicitly limited to membership prerequisite scope.');
expect($a['authentication_assurance']==='not_owned_by_file00','Authentication assurance must not be claimed by File 00.');
expect($a['membership']['mfa_required']===false && $a['membership']['mfa_owner']==='none','Membership envelope must expose File 00 MFA retirement.');
expect($a['subject']['record_version']===7,'Assertion must expose canonical record version.');
expect($a['subject']['platform_uuid']!=='','Assertion must expose a stable opaque platform UUID.');
expect($a['capabilities']['key_recovery']===false,'Membership must never grant key recovery.');
$b=SMC_CF01_Contract::membership_assertion(1,['action'=>'unknown_action']);
expect($b['result']==='unknown' && $b['reason_code']==='unsupported_action','Unknown action must fail unknown.');
$c=SMC_CF01_Contract::membership_assertion(1,['action'=>'clinical_read','jurisdiction'=>'US']);
expect($c['result']==='deny' && $c['reason_code']==='jurisdiction_mismatch','Canonical/requested jurisdiction mismatch must deny.');

$GLOBALS['states'][2]=['application_exists'=>true];
$GLOBALS['apps'][2]=['row_version'=>3,'guardian_required'=>0,'policy_version'=>'2026-08-10','date_of_birth_enc'=>'1990-01-01'];
$GLOBALS['base'][2]=['account_class'=>'member','membership_type'=>'doctor','status'=>'approved','eligible'=>true,'suspended'=>false,'guardian_verified'=>true,'professional_verified'=>true,'phone_verified'=>true,'email_verified'=>true,'identity_documents_current'=>true,'can_practice'=>true,'mfa_required'=>false,'mfa_owner'=>'none','two_factor_ready'=>false,'session_two_factor'=>false];
$d=SMC_CF01_Contract::membership_assertion(2,['action'=>'prescription_sign','jurisdiction'=>'PK']);
expect($d['result']==='allow' && $d['reason_code']==='membership_prerequisite_satisfied','Verified doctor may satisfy the File 00 membership prerequisite for prescription signing without File 00 MFA.');
$e=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'prescription_sign','scope'=>'patient:opaque']);
expect($e['result']==='unknown' && $e['reason_code']==='authentication_assurance_not_owned_by_file00','File 00 compatibility step-up must delegate/fail unknown instead of verifying TOTP.');
expect($e['method']==='not_owned_by_file00' && $e['owner']==='file02_or_consumer','Compatibility step-up must identify external authentication ownership.');
$f=SMC_CF01_Contract::verify_step_up(2,'ANY-RECOVERY-CODE',['purpose'=>'clinical_export','scope'=>'patient:opaque-2']);
expect($f['result']==='unknown' && $f['file00_mfa_active']===false,'Recovery-code-shaped input must never activate File 00 MFA.');
$g=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'','scope'=>'']);
expect($g['result']==='unknown' && $g['reason_code']==='unsupported_purpose_or_scope','Missing purpose/scope must fail unknown.');

echo "CF-01 File 00 runtime: 15 PASS, 0 FAIL\n";

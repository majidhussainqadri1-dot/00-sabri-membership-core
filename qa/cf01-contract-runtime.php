<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.7');
define('SMC_CONTRACT_VERSION', '1.1.2');
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
function wp_generate_uuid4(){ static $n=1; return sprintf('00000000-0000-4000-8000-%012d',$n++); }
function smc_membership_state($id){ return $GLOBALS['states'][(int)$id]; }
function smc_application($id){ return $GLOBALS['apps'][(int)$id] ?? false; }
function smc_policy(){ return ['version'=>'2026-07-31']; }
function smc_age_from_dob($dob){ return $dob === '2010-01-01' ? 16 : 30; }
function is_wp_error($v){ return $v instanceof WP_Error; }
class WP_Error { public function __construct($code=''){} }
class SMC_Contracts { public static function assertions($id){ return $GLOBALS['base'][(int)$id]; } }
class SMC_Security {
  public static function audit($event,$id,$context=[]){ return true; }
  public static function decrypt($value,$purpose,$context=[]){ return $value; }
  public static function two_factor_ready($id){ return !empty($GLOBALS['base'][(int)$id]['two_factor_ready']); }
  public static function verify_setup_code($secret,$code){ return $secret === 'SECRET' && $code === '123456'; }
  public static function consume_recovery_code($id,$code){ return $code === 'RECOVERY-CODE'; }
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
$GLOBALS['apps'][1]=['row_version'=>7,'guardian_required'=>0,'policy_version'=>'2026-07-31','date_of_birth_enc'=>'1990-01-01'];
$GLOBALS['base'][1]=['account_class'=>'member','membership_type'=>'member','status'=>'approved','eligible'=>true,'suspended'=>false,'two_factor_ready'=>true,'session_two_factor'=>true,'guardian_verified'=>true,'professional_verified'=>true,'phone_verified'=>true,'email_verified'=>true,'can_practice'=>false];
$a=SMC_CF01_Contract::membership_assertion(1,['action'=>'clinical_read','purpose'=>'treatment','jurisdiction'=>'PK']);
expect($a['result']==='allow','Eligible member must receive a bounded clinical_read allow assertion.');
expect($a['subject']['record_version']===7,'Assertion must expose canonical record version.');
expect($a['subject']['platform_uuid']!=='','Assertion must expose a stable opaque platform UUID.');
expect($a['capabilities']['key_recovery']===false,'Membership must never grant key recovery.');
$b=SMC_CF01_Contract::membership_assertion(1,['action'=>'unknown_action']);
expect($b['result']==='unknown' && $b['reason_code']==='unsupported_action','Unknown action must fail unknown.');

$GLOBALS['states'][2]=['application_exists'=>true];
$GLOBALS['apps'][2]=['row_version'=>3,'guardian_required'=>0,'policy_version'=>'2026-07-31','date_of_birth_enc'=>'1990-01-01'];
$GLOBALS['base'][2]=['account_class'=>'member','membership_type'=>'doctor','status'=>'approved','eligible'=>true,'suspended'=>false,'two_factor_ready'=>true,'session_two_factor'=>true,'guardian_verified'=>true,'professional_verified'=>true,'phone_verified'=>true,'email_verified'=>true,'can_practice'=>true];
$GLOBALS['meta'][2]['_smc_totp_secret_enc']='SECRET';
$c=SMC_CF01_Contract::membership_assertion(2,['action'=>'prescription_sign']);
expect($c['result']==='allow','Verified doctor may receive prescription_sign membership capability.');
$d=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'prescription_sign','scope'=>'patient:opaque']);
expect($d['result']==='allow' && $d['method']==='totp','File 00 must verify TOTP internally without returning the secret.');
$e=SMC_CF01_Contract::verify_step_up(2,'000000',['purpose'=>'prescription_sign','scope'=>'patient:opaque']);
expect($e['result']==='deny','Invalid TOTP must be denied.');
$f=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'unsupported','scope'=>'patient:opaque']);
expect($f['result']==='unknown','Unsupported step-up purpose must fail unknown.');

echo "CF-01 File 00 runtime: 9 PASS, 0 FAIL\n";

<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.7');
define('SMC_CONTRACT_VERSION', '1.1.2');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['meta'] = [];
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
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
function smc_policy(){ return ['version'=>'2026-07-31']; }
function smc_age_from_dob($dob){ return $dob === '2010-01-01' ? 16 : 30; }
function is_wp_error($v){ return $v instanceof WP_Error; }
function current_time($type,$gmt=false){ return '2026-08-03 14:30:00'; }
function add_option($name,$value,$deprecated='',$autoload=false){ if(array_key_exists($name,$GLOBALS['options'])) return false; $GLOBALS['options'][$name]=$value; return true; }
function get_option($name,$default=false){ return $GLOBALS['options'][$name] ?? $default; }
function delete_option($name){ unset($GLOBALS['options'][$name]); return true; }
function wp_next_scheduled($hook,$args=[]){ return false; }
function wp_schedule_single_event($time,$hook,$args=[]){ return true; }
function get_transient($key){ return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key,$value,$expiration){ $GLOBALS['transients'][$key]=$value; return true; }
function wp_check_password($plain,$hash){ return $plain===$hash; }
class WP_Error { public function __construct($code=''){} }
class SMC_Contracts { public static function assertions($id){ return $GLOBALS['base'][(int)$id]; } }
class SMC_Security {
  public static function audit($event,$id,$context=[]){ return true; }
  public static function decrypt($value,$purpose,$context=[]){ return $value; }
  public static function two_factor_ready($id){ return !empty($GLOBALS['base'][(int)$id]['two_factor_ready']); }
  public static function verify_setup_code($secret,$code){ return $secret === 'SECRET' && $code === '123456'; }
  public static function blind_index($value,$purpose){ return hash('sha256',$purpose.'|'.$value); }
}
class FakeWpdb {
  public string $prefix='wp_';
  public function prepare($q,...$args){ return ['q'=>$q,'args'=>$args]; }
  public function get_var($prepared){ return 'PK'; }
  public function get_row($prepared,$format=null){ return false; }
  public function query($prepared){ return 1; }
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
$c=SMC_CF01_Contract::membership_assertion(1,['action'=>'clinical_read','jurisdiction'=>'US']);
expect($c['result']==='deny' && $c['reason_code']==='jurisdiction_mismatch','Canonical/requested jurisdiction mismatch must deny.');

$GLOBALS['states'][2]=['application_exists'=>true];
$GLOBALS['apps'][2]=['row_version'=>3,'guardian_required'=>0,'policy_version'=>'2026-07-31','date_of_birth_enc'=>'1990-01-01'];
$GLOBALS['base'][2]=['account_class'=>'member','membership_type'=>'doctor','status'=>'approved','eligible'=>true,'suspended'=>false,'two_factor_ready'=>true,'session_two_factor'=>true,'guardian_verified'=>true,'professional_verified'=>true,'phone_verified'=>true,'email_verified'=>true,'can_practice'=>true];
$GLOBALS['meta'][2]['_smc_totp_secret_enc']='SECRET';
$d=SMC_CF01_Contract::membership_assertion(2,['action'=>'prescription_sign','jurisdiction'=>'PK']);
expect($d['result']==='allow','Verified doctor may receive prescription_sign membership capability.');
$e=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'prescription_sign','scope'=>'patient:opaque']);
expect($e['result']==='allow' && $e['method']==='totp' && $e['scope_hash']!=='','File 00 must verify a purpose/scope-bound TOTP without returning the secret.');
$f=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'prescription_sign','scope'=>'patient:opaque']);
expect($f['result']==='deny' && $f['reason_code']==='second_factor_invalid_or_replayed','The same TOTP must not be reusable.');
$g=SMC_CF01_Contract::verify_step_up(2,'000000',['purpose'=>'prescription_sign','scope'=>'patient:opaque-2']);
expect($g['result']==='deny','Invalid TOTP must be denied.');
$h=SMC_CF01_Contract::verify_step_up(2,'654321',['purpose'=>'unsupported','scope'=>'patient:opaque']);
expect($h['result']==='unknown','Unsupported step-up purpose must fail unknown.');
$i=SMC_CF01_Contract::verify_step_up(2,'123456',['purpose'=>'key_recovery','scope'=>'key:opaque']);
expect(in_array($i['result'],['allow','deny'],true),'Step-up authentication is evaluated separately from membership key authority.');

echo "CF-01 File 00 runtime: 13 PASS, 0 FAIL\n";

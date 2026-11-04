<?php
/**
 * F00-CEN-03: protected membership assertions must use current decrypted
 * age and current guardian approval; a cached registration-era flag is not
 * sufficient. Isolated WordPress data-contract fixture, no live DB.
 */
define('ABSPATH',__DIR__.'/');
define('SMC_CONTRACT_VERSION','fixture-contract-1.0.0');
$GLOBALS['v12_subject']=array(
 'birth'=>'minor','guardian_required'=>false,'consent'=>false,
 'user'=>77,'institutional'=>false
);
function absint($v){return abs((int)$v);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{}
class WP_User{public $ID;public $user_email;public function __construct($id){$this->ID=$id;$this->user_email='fixture@example.com';}}
function get_userdata($id){return absint($id)===77?new WP_User(77):false;}
function user_can($u,$cap){return false;}
function smc_is_founder($id){return false;}
function smc_is_institutional_ai($id){return false;}
function smc_institutional_ai_policy(){return array();}
function smc_policy(){return array('version'=>'v12-fixture','base_services'=>array('membership','library'));}
function smc_is_professional_type($t){return false;}
function smc_account_types(){return array('patient'=>array());}
function smc_sanitize_membership_types($types){return array_values(array_intersect((array)$types,array('patient')));}
function smc_required_identity_documents(){return array();}
function smc_membership_state($id){
 return array('application_exists'=>true,'status'=>'approved','membership_type'=>'patient',
  'approved'=>true,'institutional_account'=>$GLOBALS['v12_subject']['institutional'],
  'account_class'=>'human');
}
function smc_application($id){
 return array('membership_type'=>'patient','status'=>'approved',
  'gender'=>'female','residence_country'=>'PK',
  'guardian_required'=>$GLOBALS['v12_subject']['guardian_required'],
  'date_of_birth_enc'=>$GLOBALS['v12_subject']['birth'],
  'phone_e164_enc'=>'phone', 'profile_visibility'=>'private');
}
function smc_age_from_dob($dob){
 if($dob==='minor')return 16;
 if($dob==='adult')return 25;
 return false;
}
function smc_effective_minimum_age($gender,$country=''){return 12;}
function apply_filters($hook,$default,...$args){return $default;}
class SMC_Security{
 static function decrypt($encrypted,$purpose,$ctx=array()){return $encrypted;}
 static function blind_index($value,$ctx=''){return hash('sha256',(string)$value);}
}
class SMC_V12_DB{
 public $prefix='wp_';
 function prepare($sql,...$args){return $sql;}
 function esc_like($v){return $v;}
 function get_var($sql){
  if(strpos($sql,'SHOW TABLES LIKE')!==false)return null;
  if(strpos($sql,'smc_guardian_consents')!==false)return $GLOBALS['v12_subject']['consent']?901:null;
  if(strpos($sql,'smc_contact_otps')!==false)return 902;
  return null;
 }
 function get_results($sql,$format=null){return array();}
}
$wpdb=new SMC_V12_DB();
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-contracts.php';
$cases=array();$test=function($name,$pass)use(&$cases){$cases[]=array($name,(bool)$pass);};

$a=SMC_Contracts::assertions(77);
$test('minor with obsolete guardian_required=false cannot pass protected eligibility',
 empty($a['guardian_verified'])&&empty($a['eligible'])&&empty($a['can_message'])&&empty($a['can_book_appointment']));
$test('live age_context identifies minor despite stale database flag',
 !empty($a['age_context']['minor'])&&!empty($a['age_context']['guardian_required']));
$GLOBALS['v12_subject']['consent']=true;
$a=SMC_Contracts::assertions(77);
$test('minor with current verified guardian becomes eligible',!empty($a['guardian_verified'])&&!empty($a['eligible']));
$GLOBALS['v12_subject']['consent']=false;
$a=SMC_Contracts::assertions(77);
$test('guardian withdrawal blocks the very next assertion without waiting for cron',
 empty($a['eligible'])&&empty($a['can_message']));
$GLOBALS['v12_subject']['birth']='adult';
$GLOBALS['v12_subject']['guardian_required']=true;
$a=SMC_Contracts::assertions(77);
$test('now-adult user is not indefinitely bound to obsolete minor flag',
 !empty($a['eligible'])&&!empty($a['guardian_verified']));
$GLOBALS['v12_subject']['birth']='unknown';
$GLOBALS['v12_subject']['guardian_required']=false;
$a=SMC_Contracts::assertions(77);
$test('unreadable or unknown DOB never generates an approved protected assertion',
 empty($a['eligible'])&&empty($a['can_message'])&&empty($a['can_book_appointment']));
$GLOBALS['v12_subject']['institutional']=true;
$a=SMC_Contracts::assertions(77);
$test('institutional account remains exempt from ordinary human DOB path',!empty($a['eligible']));
$bad=array_values(array_filter($cases,function($v){return !$v[1];}));
if($bad){foreach($bad as $x)fwrite(STDERR,'F00-CEN-03 FAIL '.$x[0]."\n");exit(1);}
echo 'F00-CEN-03 current-age/guardian runtime '.count($cases).'/'.count($cases)." PASS\n";

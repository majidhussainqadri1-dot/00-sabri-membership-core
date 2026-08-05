<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_CONTRACT_VERSION', '1.2.0');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['states'] = [];
$GLOBALS['apps'] = [];
$GLOBALS['users'] = [];
$GLOBALS['contacts'] = [];
$GLOBALS['guardians'] = [];
$GLOBALS['documents'] = [];
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }
function apply_filters($tag,$value,...$args){ return $value; }
function is_wp_error($v){ return false; }
function smc_membership_state($uid){ return $GLOBALS['states'][(int)$uid]; }
function smc_application($uid){ return $GLOBALS['apps'][(int)$uid] ?? false; }
function smc_is_professional_type($type){ return in_array($type,['doctor','teacher','researcher'],true); }
function smc_is_founder($uid){ return false; }
function smc_policy(){ return ['version'=>'2026.1']; }
function smc_required_identity_documents(){ return ['government_id'=>'Government identity']; }
function smc_account_types(){ return ['member'=>'Member','doctor'=>'Doctor','teacher'=>'Teacher','researcher'=>'Researcher']; }
function smc_sanitize_membership_types($types){
  $allowed=array_keys(smc_account_types());
  $clean=[];
  foreach((array)$types as $type){ $type=sanitize_key($type); if(in_array($type,$allowed,true)) $clean[]=$type; }
  return array_values(array_unique($clean));
}
function get_userdata($uid){ return $GLOBALS['users'][(int)$uid] ?? false; }
function get_user_meta($uid,$key,$single=true){ return ''; }
function user_can($user,$cap){ return false; }
class WP_User { public array $roles=[]; public function __construct($id=0){} public function exists(){return true;} public function remove_role($r){} public function add_role($r){$this->roles[]=$r;} }
class SMC_Security {
  public static function two_factor_ready($uid){ return true; }
  public static function session_is_verified($uid){ return true; }
  public static function decrypt($v,$p,$c=[]){ return $v; }
  public static function blind_index($v,$p){ return hash('sha256',(string)$v); }
  public static function register_session($u,$t,$e){ return true; }
  public static function revoke_session($u,$t){ return true; }
}
class FakeWpdb {
  public string $prefix='wp_';
  public function esc_like($value){ return addcslashes((string)$value, '_%\\'); }
  public function prepare($q,...$args){ return ['q'=>$q,'args'=>$args]; }
  public function get_var($prepared){
    $q=$prepared['q']; $a=$prepared['args'];
    if (false !== strpos($q,'SHOW TABLES LIKE')) return $this->prefix.'smc_role_grants';
    if (false !== strpos($q,'smc_guardian_consents')) return $GLOBALS['guardians'][(int)$a[0]] ?? '';
    if (false !== strpos($q,'smc_contact_otps')) return !empty($GLOBALS['contacts'][(int)$a[0]][(string)$a[1]]) ? 1 : 0;
    return null;
  }
  public function get_results($prepared,$format=null){
    $q=$prepared['q']; $a=$prepared['args'];
    if (false !== strpos($q,'smc_role_grants')) return [];
    if (false === strpos($q,'smc_identity_documents')) return [];
    $uid=(int)$a[0];
    return array_map(static fn($key)=>['document_key'=>$key],array_keys($GLOBALS['documents'][$uid] ?? []));
  }
  public function delete($table,$where,$formats=[]){ return 1; }
}
$GLOBALS['wpdb']=new FakeWpdb();
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-contracts.php';

function seed_user(int $id, bool $institutional=false, bool $guardianRequired=false): void {
  $GLOBALS['states'][$id]=[
    'application_exists'=>true,'institutional_account'=>$institutional,'account_class'=>$institutional?'administrator':'member',
    'membership_type'=>'member','status'=>'approved','approved'=>true
  ];
  $GLOBALS['apps'][$id]=['user_id'=>$id,'membership_type'=>'member','guardian_required'=>$guardianRequired?1:0,'profile_visibility'=>'private','phone_e164_enc'=>'+923001234567'];
  $GLOBALS['users'][$id]=(object)['roles'=>[],'user_email'=>'user'.$id.'@example.test'];
  if(!$institutional){ $GLOBALS['documents'][$id]=['government_id'=>true]; }
}
function expect(bool $condition,string $label): void { if(!$condition){fwrite(STDERR,$label."\n");exit(1);} }

seed_user(1,false,false);
$a=SMC_Contracts::assertions(1);
expect($a['eligible']===false && $a['can_message']===false,'Ordinary approved account without verified contacts must remain ineligible.');
$GLOBALS['contacts'][1]=['email'=>true,'mobile'=>true];
$a=SMC_Contracts::assertions(1);
expect($a['eligible']===true && $a['can_message']===true,'Ordinary account becomes eligible only after both contacts and current identity evidence.');
unset($GLOBALS['documents'][1]);
$a=SMC_Contracts::assertions(1);
expect($a['eligible']===false,'Ordinary account without current approved identity evidence must remain ineligible.');
$GLOBALS['documents'][1]=['government_id'=>true];
seed_user(2,false,true); $GLOBALS['contacts'][2]=['email'=>true,'mobile'=>true];
$a=SMC_Contracts::assertions(2);
expect($a['eligible']===false,'Minor account without verified guardian must remain ineligible.');
$GLOBALS['guardians'][2]='verified';
$a=SMC_Contracts::assertions(2);
expect($a['eligible']===true,'Verified guardian completes effective eligibility.');
seed_user(3,true,false);
$a=SMC_Contracts::assertions(3);
expect($a['eligible']===true,'Institutional account retains the explicit contact and identity-document exemption.');
$GLOBALS['states'][1]['status']='invalid_application'; $GLOBALS['states'][1]['approved']=false;
$a=SMC_Contracts::assertions(1);
expect($a['suspended']===true && $a['eligible']===false,'Corrupt application state is a public hard block.');
echo "Ilhami eligibility runtime: 7 PASS, 0 FAIL\n";

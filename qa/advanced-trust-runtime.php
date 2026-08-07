<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('YEAR_IN_SECONDS', 31536000);
define('SMC_VERSION', '1.2.14');
$meta=[]; $options=[]; $actions=[]; $filters=[]; $current_user_id=1;
class WP_Error { public $code; public function __construct($c,$m=''){ $this->code=$c; } }
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($s,$d=null){ return $s; }
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function get_userdata($id){ return $id>0 ? (object)['ID'=>$id] : false; }
function get_user_meta($id,$key='',$single=false){ global $meta; if($key==='') return $meta[$id]??[]; return $meta[$id][$key]??($single?'':[]); }
function update_user_meta($id,$key,$value){ global $meta; $meta[$id][$key]=$value; return true; }
function delete_user_meta($id,$key){ global $meta; unset($meta[$id][$key]); return true; }
function metadata_exists($type,$id,$key){ global $meta; return array_key_exists($key,$meta[$id]??[]); }
function get_current_user_id(){ global $current_user_id; return $current_user_id; }
function current_user_can($cap){ return true; }
function add_filter($tag,$cb,$p=10,$n=1){ return true; }
function add_action($tag,$cb,$p=10,$n=1){ return true; }
function apply_filters($tag,$value,...$args){ global $filters; if(isset($filters[$tag]) && is_callable($filters[$tag])) return $filters[$tag]($value,...$args); return $value; }
function do_action($tag,...$args){ global $actions; $actions[]=$tag; }
function wp_generate_uuid4(){ static $i=0; $i++; return sprintf('00000000-0000-4000-8000-%012d',$i); }
function wp_salt($scheme='auth'){ return 'runtime-test-salt-'.$scheme; }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function get_option($key,$default=false){ global $options; return $options[$key]??$default; }
function update_option($key,$value,$autoload=false){ global $options; $options[$key]=$value; return true; }
function add_option($key,$value,$deprecated='',$autoload='yes'){ global $options; if(array_key_exists($key,$options)) return false; $options[$key]=$value; return true; }
function delete_option($key){ global $options; unset($options[$key]); return true; }
function get_users($args=[]){ return []; }
function smc_is_founder($id){ return in_array((int)$id,[1,2],true); }
function smc_is_institutional_ai($id){ return (int)$id===99; }
class WPDBRevocationStub { public $prefix='wp_'; public $users='wp_users'; public $approved_at; public function __construct(){ $this->approved_at=gmdate('Y-m-d H:i:s'); } public function prepare($q,...$a){ return $q; } public function get_var($q){ if(strpos($q,'GET_LOCK')!==false||strpos($q,'RELEASE_LOCK')!==false) return 1; if(strpos($q,'smc_role_grants')!==false||strpos($q,'smc_applications')!==false) return $this->approved_at; return 1; } public function get_col($q){ return []; } }
$wpdb=new WPDBRevocationStub();
class SMC_Security {
  public static $verified=true; public static $audits=[];
  public static function session_is_verified($id){ return self::$verified; }
  public static function revoke_all_sessions($id,$reason=''){ return true; }
  public static function audit($action,$id=0,$details=[]){ self::$audits[]=$action; return true; }
  public static function subject_hash($id){ return hash('sha256','subject|'.$id); }
  public static function blind_index($value,$purpose){ return hash_hmac('sha256',strtolower(trim($purpose.'|'.$value)),'runtime-derived-key'); }
}
class SMC_Contracts {
  public static function assertions($id){ return [
    'application_exists'=>false,'status'=>'not_enrolled','identity_documents_current'=>true,'phone_verified'=>true,'email_verified'=>true,'guardian_verified'=>true,
    'professional_verified'=>true,'approved'=>true,'suspended'=>false,'eligible'=>true,'public_profile_allowed'=>true
  ]; }
}
class SMC_CF01_Contract { public static function ensure_subject_uuid($id){ return 'uuid-'.$id; } }
require __DIR__ . '/../source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php';
$tests=[]; function t($name,$ok){ global $tests; $tests[]=[$name,(bool)$ok]; }

$n=SMC_Advanced_Trust_2026::negotiate_contract('1.0.0');
t('contract 1.0.0 compatible', !empty($n['compatible']) && empty($n['downgrade_allowed']));
$n2=SMC_Advanced_Trust_2026::negotiate_contract('2.0.0');
t('future major fails closed', empty($n2['compatible']));
$a=SMC_Advanced_Trust_2026::authentication_assurance(7); t('local File00 MFA provenance is explicit', $a['owner']==='file00' && $a['level']===2);
$filters['smc_file02_authentication_assurance_v1']=fn($base,$uid)=>['owner'=>'evil','contract_version'=>'1.0.0','level'=>4,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()];
$a=SMC_Advanced_Trust_2026::authentication_assurance(7); t('spoofed File02 elevation rejected', $a['level']===2 && empty($a['passkey_asserted']) && $a['owner']==='file00');
$filters['smc_file02_authentication_assurance_v1']=fn($base,$uid)=>['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()];
$a=SMC_Advanced_Trust_2026::authentication_assurance(7); t('fresh File02 passkey claim accepted', $a['level']===3 && !empty($a['passkey_asserted']) && !empty($a['hardware_backed']) && $a['owner']==='file02');
unset($filters['smc_file02_authentication_assurance_v1']);
$p=SMC_Advanced_Trust_2026::minimal_assertions(7,'file26');
t('minimal assertion uses opaque subject', $p['subject']==='uuid-7' && !array_key_exists('user_id',$p));
t('minimal assertion has revocation epoch', array_key_exists('revocation_epoch',$p));
$proof=SMC_Advanced_Trust_2026::selective_disclosure_proof(7,['identity_current','age_requirement_met'],'file17',600);
t('selective proof capped TTL', is_array($proof) && ($proof['expires_at']-$proof['issued_at'])<=300);
t('selective proof verifies', SMC_Advanced_Trust_2026::verify_selective_disclosure_proof($proof,'file17'));
t('selective proof is purpose bound and revocation-fresh', $proof['proof_version']==='1.1.0' && !empty($proof['purpose']) && ($proof['expires_at']-$proof['issued_at'])<=60 && !SMC_Advanced_Trust_2026::verify_selective_disclosure_proof($proof,'file17','wrong_purpose'));
$proof['claims']['identity_current']=false;
t('tampered proof rejected', !SMC_Advanced_Trust_2026::verify_selective_disclosure_proof($proof,'file17'));
$rev=SMC_Advanced_Trust_2026::bump_revocation_epoch(7,'test');
t('revocation epoch and SLA emitted', is_array($rev) && $rev['sla_seconds']===60 && in_array('smc_trust_revocation_invalidated',$actions,true) && !array_key_exists('user_id',$rev));
$current_user_id=2; $bad=SMC_Advanced_Trust_2026::grant_delegated_authority(7,1,['membership_support'],time()+3600); t('confused-deputy delegation rejected', is_wp_error($bad)); $current_user_id=1;
$chg=SMC_Advanced_Trust_2026::mark_critical_identity_change(7,'date_of_birth',1,'correction'); $m=SMC_Advanced_Trust_2026::minimal_assertions(7,'file17'); t('critical DOB change invalidates age assertion', $chg===true && empty($m['age_requirement_met']) && empty($m['membership_eligible']));
$res=SMC_Advanced_Trust_2026::resolve_critical_identity_change(7,1,'identity_review'); $m=SMC_Advanced_Trust_2026::minimal_assertions(7,'file17'); t('identity reverification restores age assertion', is_array($res) && !empty($m['age_requirement_met']));
SMC_Advanced_Trust_2026::set_containment_state(7,'contained',1,'test');
$caps=['publish_posts'=>true,'read'=>true];
$out=SMC_Advanced_Trust_2026::filter_capabilities($caps,[],[],(object)['ID'=>7]);
t('contained account loses sensitive cap', $out['publish_posts']===false && $out['read']===true);
SMC_Advanced_Trust_2026::set_containment_state(7,'clear',1,'recovered');
update_user_meta(14,'_smc_service_identity_v1',['kind'=>'service','purpose'=>'integration','approved'=>false]);$disabled_service=SMC_Advanced_Trust_2026::subject_kind(14);t('disabled service identity remains non-human', $disabled_service['kind']==='service' && !$disabled_service['human'] && !$disabled_service['approved']);
$kind=SMC_Advanced_Trust_2026::subject_kind(99);
t('institutional AI never human/doctor', $kind['kind']==='institutional_ai' && !$kind['human'] && !$kind['doctor']);
$cont=SMC_Advanced_Trust_2026::set_continuity_state(8,'deceased',1,'verified notice');
t('deceased preserves authorship and blocks protected actions', is_array($cont) && !empty($cont['authorship_preserved']) && !SMC_Advanced_Trust_2026::protected_actions_allowed(8));
$bg=SMC_Advanced_Trust_2026::open_break_glass(7,1,'founder recovery');
t('break glass opens bounded', is_array($bg) && ($bg['expires_at']-$bg['opened_at'])===900);
$current_user_id=2; SMC_Advanced_Trust_2026::approve_break_glass($bg['id'],2);
$current_user_id=1; $token=SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1);
t('break glass requires/uses two approvals', is_array($token) && !empty($token['authorized']));
t('break glass authority is subject and purpose bound', is_array($token) && $token['subject']==='uuid-7' && $token['purpose']==='founder recovery');
t('blank break glass purpose is rejected', is_wp_error(SMC_Advanced_Trust_2026::open_break_glass(7,1,'   ')));
t('break glass cannot be replayed', SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1)===false);
$profile=SMC_Advanced_Trust_2026::assurance_profile(7);
t('assurance profile reaches verified level', $profile['identity_assurance_level']>=3 && $profile['authentication_assurance_level']>=2 && $profile['authentication_owner']==='file00');

$fail=0; foreach($tests as [$name,$ok]){ echo ($ok?'PASS ':'FAIL ').$name."\n"; if(!$ok)$fail++; }
echo 'Advanced trust runtime: '.(count($tests)-$fail).' PASS / '.$fail." FAIL\n";
exit($fail?1:0);

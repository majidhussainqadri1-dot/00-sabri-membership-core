<?php
/**
 * Exact-source File00 + File04 + File08 consumer/provider contract fixture.
 * The companion sources are checked out at immutable, independently checked
 * Git SHAs by the workflow. These are real PHP classes, not copied snippets.
 * WordPress/DB services are deliberately deterministic local fakes, so this
 * proves cross-source binding but NOT installed WP/MySQL or live parity.
 */
define('ABSPATH', __DIR__.'/');
$file04=getenv('FILE04_SOURCE_ROOT');
$file08=getenv('FILE08_SOURCE_ROOT');
if(!is_string($file04)||!is_dir($file04)||!is_string($file08)||!is_dir($file08)){
    fwrite(STDERR,"Exact companion repositories are mandatory.\n");exit(2);
}
$GLOBALS['v12_users']=array();
$GLOBALS['v12_posts']=array();
$GLOBALS['v12_meta']=array();
$GLOBALS['v12_hooks']=array();
$GLOBALS['v12_actor']=42;
$GLOBALS['v12_cap']=true;
$GLOBALS['v12_audit_ok']=true;

class WP_Error{
  public $code; public $message;
  public function __construct($code,$message='',$data=array()){ $this->code=$code; $this->message=$message; }
  public function get_error_code(){return $this->code;}
}
class WP_User{public $ID;public $user_login;public function __construct($id,$login){$this->ID=(int)$id;$this->user_login=$login;}}
class WP_Post{public $ID;public $post_author;public function __construct($id,$author){$this->ID=$id;$this->post_author=$author;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function get_current_user_id(){return $GLOBALS['v12_actor'];}
function current_user_can($cap){return $cap==='sabri_file04_run_migrations' && $GLOBALS['v12_cap'];}
function get_userdata($id){return $GLOBALS['v12_users'][absint($id)]??false;}
function get_post($id){return $GLOBALS['v12_posts'][absint($id)]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['v12_meta'][absint($id)][$key]??'';}
function update_user_meta($id,$key,$v){$GLOBALS['v12_meta'][absint($id)][$key]=$v;return true;}
function add_user_meta($id,$key,$v,$unique=false){
    $id=absint($id);
    if($unique && array_key_exists($key,$GLOBALS['v12_meta'][$id]??array()))return false;
    $GLOBALS['v12_meta'][$id][$key]=$v;return true;
}
function delete_user_meta($id,$key,$value=null){unset($GLOBALS['v12_meta'][absint($id)][$key]);return true;}
function get_users($query){
    $matches=array();
    foreach($GLOBALS['v12_meta'] as $id=>$row){
        if(($row[$query['meta_key']]??null)===$query['meta_value'])$matches[]=(int)$id;
    }
    sort($matches);
    return array_slice($matches,0,absint($query['number']??2));
}
function wp_generate_uuid4(){return '12345678-1234-4123-8123-123456789abc';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$x));}
function __($x,$domain=''){return $x;}
function add_action($name,$call,$priority=10,$args=1){return add_filter($name,$call,$priority,$args);}
function add_filter($name,$call,$priority=10,$args=1){
    $GLOBALS['v12_hooks'][$name][$priority][]=array('call'=>$call,'argc'=>$args);
    return true;
}
function apply_filters($name,$value,...$rest){
    $list=$GLOBALS['v12_hooks'][$name]??array();ksort($list,SORT_NUMERIC);
    foreach($list as $handlers)foreach($handlers as $h){
        $value=call_user_func_array($h['call'],array_slice(array_merge(array($value),$rest),0,$h['argc']));
    }
    return $value;
}
class SMC_Security{
  public static function audit($action,$id,$details=array()){return $GLOBALS['v12_audit_ok'];}
}
function wp_generate_password($length=64,$special=true,$extra=true){return str_repeat('x',$length);}
function username_exists($login){
    foreach($GLOBALS['v12_users'] as $id=>$user)if($user->user_login===$login)return $id;return false;
}

require dirname(__DIR__).'/source/sabri-membership-core/includes/functions.php';
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
require $file04.'/includes/class-snfla-checksum.php';
require $file04.'/includes/class-snfla-plan-completion.php';
require $file08.'/includes/class-wca-authorization.php';
require $file08.'/includes/class-wca-future24.php';

$uuid77='00000000-0000-4000-8000-000000000077';
$uuid5='00000000-0000-4000-8000-000000000005';
$GLOBALS['v12_users'][77]=new WP_User(77,'member_77');
$GLOBALS['v12_users'][5]=new WP_User(5,'sabri_legacy_author_placeholder');
$GLOBALS['v12_users'][88]=new WP_User(88,'member_88');
$GLOBALS['v12_posts'][345]=new WP_Post(345,77);
$GLOBALS['v12_posts'][346]=new WP_Post(346,0);
$GLOBALS['v12_posts'][347]=new WP_Post(347,999);
$GLOBALS['v12_meta'][77]['_smc_platform_uuid_v1']=$uuid77;
$GLOBALS['v12_meta'][5]=array('_smc_platform_uuid_v1'=>$uuid5,
 '_smc_legacy_author_placeholder_v1'=>1,
 '_smc_login_disabled_v1'=>1,
 '_smc_legacy_author_placeholder_audited_v1'=>1);
$GLOBALS['v12_meta'][88]['_smc_platform_uuid_v1']='00000000-0000-4000-8000-000000000088';
SMC_CF01_Contract::init();

$cases=array();
$check=function($ok,$name)use(&$cases){$cases[]=array($name,(bool)$ok);};
$known=SNFLA_Plan_Completion::authorship_preflight(345);
$check(is_array($known)&&!empty($known['verified'])&&$known['user_id']===77
 &&$known['platform_uuid']===$uuid77&&empty($known['placeholder']),
 'real File04 current-source known author uses File00 canonical UUID');
$deleted=SNFLA_Plan_Completion::authorship_preflight(346);
$check(is_array($deleted)&&!empty($deleted['verified'])&&$deleted['user_id']===5
 &&$deleted['platform_uuid']===$uuid5&&!empty($deleted['placeholder']),
 'real File04 current-source deleted author uses audited File00 sentinel');
$unresolvable=SNFLA_Plan_Completion::authorship_preflight(347);
$check(is_array($unresolvable)&&!empty($unresolvable['verified'])&&$unresolvable['user_id']===5,
 'real File04 unknown historical author uses same governed attribution sentinel');

$GLOBALS['v12_cap']=false;
$denied=SNFLA_Plan_Completion::authorship_preflight(345);
$check(is_wp_error($denied)&&$denied->get_error_code()==='snfla_author_platform_uuid_unresolved',
 'File04 owner missing migration capability rejects known-author resolution');
$denied=SNFLA_Plan_Completion::authorship_preflight(346);
$check(is_wp_error($denied)&&$denied->get_error_code()==='snfla_author_placeholder_contract_required',
 'File04 owner missing migration capability rejects placeholder');
$GLOBALS['v12_cap']=true;
$GLOBALS['v12_actor']=0;
$guest=SNFLA_Plan_Completion::authorship_preflight(345);
$check(is_wp_error($guest),'anonymous File04 caller rejected by real current source');
$GLOBALS['v12_actor']=42;

add_filter('sabri_file00_legacy_author_placeholder_v1',function($existing,$req)use($uuid77){
 return array('verified'=>true,'provider_id'=>'forged','user_id'=>77,
  'platform_uuid'=>$uuid77,'legacy_id'=>$req['legacy_id'],
  'legacy_author_id'=>$req['legacy_author_id'],'request_digest'=>$req['request_digest']);
},1,2);
$clean=SNFLA_Plan_Completion::authorship_preflight(346);
$check(is_array($clean)&&$clean['user_id']===5&&$clean['platform_uuid']===$uuid5,
 'File00 owner replaces forged upstream verified File04 provider response');

$subject=SMC_CF01_Contract::resolve_user_id_by_subject_uuid($uuid77);
$check($subject===77,'File00 read-only exact canonical inverse resolver');
$check(smc_get_user_id_by_subject_uuid($uuid77)===77,
 'File00 official inverse helper matches class resolver');
$check(SMC_CF01_Contract::resolve_user_id_by_subject_uuid('garbage')===0,
 'malformed subject cannot bind a WordPress principal');
$check(SMC_CF01_Contract::resolve_user_id_by_subject_uuid($uuid5)===5,
 'governed sentinel has own canonical identity');

$reflect=new ReflectionMethod('WCA_Future24','subject_user_id');
$reflect->setAccessible(true);
$check($reflect->invoke(null,$uuid77)===77,
 'actual File08 private consumer resolves File00 canonical subject with forward check');
$check($reflect->invoke(null,'incorrect')===0,
 'actual File08 consumer denies syntactically invalid subject');
$GLOBALS['v12_meta'][88]['_smc_platform_uuid_v1']=$uuid77;
$check($reflect->invoke(null,$uuid77)===0,
 'real File08 consumer denies duplicate File00 subject identity');
unset($GLOBALS['v12_meta'][88]['_smc_platform_uuid_v1']);
$GLOBALS['v12_meta'][77]['_smc_platform_uuid_v1']='00000000-0000-4000-8000-000000000088';
$check($reflect->invoke(null,$uuid77)===0,
 'real File08 consumer denies stale/changed forward canonical identity');
$GLOBALS['v12_meta'][77]['_smc_platform_uuid_v1']=$uuid77;

$check(SMC_CF01_Contract::block_placeholder_session(5)===0,
 'File00 current-session policy rejects reserved sentinel');
$check(SMC_CF01_Contract::block_placeholder_session(77)===77,
 'File00 current-session policy preserves normal member');

$fail=array_values(array_filter($cases,function($v){return !$v[1];}));
if($fail){
 foreach($fail as $f)fwrite(STDERR,'File00 cross repo FAIL: '.$f[0]."\n");
 exit(1);
}
echo 'File00 + exact File04 + exact File08 provider/consumer runtime '.count($cases).'/'.count($cases)." PASS\n";

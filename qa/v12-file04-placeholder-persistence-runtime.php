<?php
/**
 * File00: legacy-author placeholder is a user-creation saga, not a single
 * atomic SQL write. Fault-inject every meta/audit/delete boundary.
 * No live DB; WP functions below are explicit in-memory contract fakes.
 */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['st'] = array();
function reset_case($fault=array()) {
    $GLOBALS['st'] = array(
        'users'=>array(), 'meta'=>array(), 'fault'=>$fault,
        'created'=>0, 'deleted'=>0, 'audits'=>array(), 'actor'=>42, 'created_role'=>null,
    );
}
function absint($x){return abs((int)$x);}
function get_userdata($id){return $GLOBALS['st']['users'][(int)$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['st']['meta'][(int)$id][$key]??'';}
function username_exists($login){foreach($GLOBALS['st']['users'] as $id=>$u){if($u->user_login===$login)return $id;}return 0;}
function wp_generate_password($n=64,$special=true,$extra=true){return str_repeat('Z',$n);}
function wp_generate_uuid4(){return '12345678-1234-4123-8123-123456789abc';}
function wp_insert_user($args){
    if(!empty($GLOBALS['st']['fault']['insert']))return new WP_Error('insert_failure');
    $id=901;
    $GLOBALS['st']['created_role']=$args['role']??null;
    $GLOBALS['st']['users'][$id]=(object)array('ID'=>$id,'user_login'=>$args['user_login']);
    $GLOBALS['st']['created']++;
    if(empty($GLOBALS['st']['fault']['ignore_meta_input'])){
        foreach(($args['meta_input']??array()) as $k=>$v)$GLOBALS['st']['meta'][$id][$k]=$v;
    }
    return $id;
}
function update_user_meta($id,$key,$value){
    $f=$GLOBALS['st']['fault'];
    if(!empty($f['meta_fail_both']) && in_array($key,array('_smc_legacy_author_placeholder_v1','_smc_login_disabled_v1'),true))return false;
    if(!empty($f['meta_fail']) && $key===$f['meta_fail'])return false;
    if(!empty($f['meta_silent']) && $key===$f['meta_silent'])return true;
    $GLOBALS['st']['meta'][$id][$key]=$value;return true;
}
function add_user_meta($id,$key,$value,$unique=false){
    if(!empty($GLOBALS['st']['fault']['uuid_fail']))return false;
    if($unique && isset($GLOBALS['st']['meta'][$id][$key]))return false;
    $GLOBALS['st']['meta'][$id][$key]=$value;return true;
}
function delete_user_meta($id,$key,$value=null){unset($GLOBALS['st']['meta'][$id][$key]);return true;}
function wp_delete_user($id){
    $GLOBALS['st']['deleted']++;
    if(!empty($GLOBALS['st']['fault']['delete_fail']))return false;
    unset($GLOBALS['st']['users'][$id],$GLOBALS['st']['meta'][$id]);return true;
}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['st']['actor'];}
function current_user_can($cap){return $cap==='sabri_file04_run_migrations';}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}
function __($value,$domain=''){return $value;}
class WP_Error{private $code;function __construct($c){$this->code=$c;}function get_error_code(){return $this->code;}}
class WP_User{public $ID;public $user_login;function __construct($id){$this->ID=$id;$u=get_userdata($id);$this->user_login=$u?$u->user_login:('ordinary_'.$id);}}
class SMC_Security{
    public static function audit($action,$id,$details=array()){
        $GLOBALS['st']['audits'][]=$action;
        if(!empty($GLOBALS['st']['fault']['audit_fail']) && strpos($action,'legacy_author_placeholder_')===0)return false;
        return true;
    }
}
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
$req=array('file_number'=>'04','legacy_id'=>123,'legacy_author_id'=>0);
ksort($req,SORT_STRING);
$req['request_digest']=hash('sha256',wp_json_encode($req,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$assertions=array();
$check=function($label,$ok)use(&$assertions){$assertions[]=array($label,(bool)$ok);};
$invoke=function($fault=array())use($req){reset_case($fault);return SMC_CF01_Contract::file04_legacy_author_placeholder(array('verified'=>false),$req);};

$r=$invoke();
$check('healthy sentinel created and verified',is_array($r)&&!empty($r['verified'])&&$r['user_id']===901);
$check('File04 non-login attribution sentinel is provisioned with no WordPress role', $GLOBALS['st']['created_role']==='');
$check('both login and attribution markers persisted',get_user_meta(901,'_smc_login_disabled_v1',true)==='1'||get_user_meta(901,'_smc_login_disabled_v1',true)===1 ? get_user_meta(901,'_smc_legacy_author_placeholder_v1',true)==='1'||get_user_meta(901,'_smc_legacy_author_placeholder_v1',true)===1 : false);
$check('verified successful create leaves existing user',!!get_userdata(901));
$check('successful audit commitment is durably marked',get_user_meta(901,'_smc_legacy_author_placeholder_audited_v1',true)==='1'||get_user_meta(901,'_smc_legacy_author_placeholder_audited_v1',true)===1);

$r=$invoke(array('ignore_meta_input'=>true,'meta_fail'=>'_smc_login_disabled_v1'));
$check('failed login-disabled marker denies result',empty($r['verified']));
$check('failed marker write rolls back newly created user',!get_userdata(901) && $GLOBALS['st']['deleted']===1);

$r=$invoke(array('ignore_meta_input'=>true,'meta_fail'=>'_smc_legacy_author_placeholder_v1'));
$check('failed attribution marker denies result',empty($r['verified']));
$check('failed attribution marker removes new identity',!get_userdata(901));

$r=$invoke(array('ignore_meta_input'=>true,'meta_silent'=>'_smc_login_disabled_v1'));
$check('false-positive successful metadata write denied by readback',empty($r['verified']));
$check('phantom marker state is rolled back',!get_userdata(901));

$r=$invoke(array('audit_fail'=>true));
$check('audit failure never verifies sentinel',empty($r['verified']));
$check('audit failure compensating delete succeeds',!get_userdata(901) && $GLOBALS['st']['deleted']===1);

$r=$invoke(array('audit_fail'=>true,'delete_fail'=>true));
$check('failed deletion still denies verified receipt',empty($r['verified']));
$check('retained orphan sentinel has login-disabled marker',get_user_meta(901,'_smc_login_disabled_v1',true)==='1'||get_user_meta(901,'_smc_login_disabled_v1',true)===1);
$check('orphan with stored disabled marker cannot authenticate',is_wp_error(SMC_CF01_Contract::block_placeholder_login(new WP_User(901))));

$retryWhileAuditDown=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$req);
$check('unaudited orphan cannot be reclassified as verified on retry',empty($retryWhileAuditDown['verified']));
$GLOBALS['st']['fault']['audit_fail']=false;
$recoverAfterAudit=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$req);
$check('orphan can be accepted only after audited recovery plus durable marker',
  !empty($recoverAfterAudit['verified']) &&
  (string)get_user_meta(901,'_smc_legacy_author_placeholder_audited_v1',true)==='1');

$r=$invoke(array('ignore_meta_input'=>true,'meta_fail_both'=>true,'delete_fail'=>true));
$check('non-atomic dual failure must not verify incomplete account',empty($r['verified']));
$check('dual failure fixture retains orphan to examine login safety',!!get_userdata(901));
$check('reserved orphan without persisted marker cannot authenticate',
 is_wp_error(SMC_CF01_Contract::block_placeholder_login(new WP_User(901))));
$check('session-cookie guard exists for orphaned reserved identity',method_exists('SMC_CF01_Contract','block_placeholder_session'));
if(method_exists('SMC_CF01_Contract','block_placeholder_session')) {
 $check('unmarked orphan cannot use inherited session cookie',SMC_CF01_Contract::block_placeholder_session(901)===0);
 $check('normal unrelated session survives',SMC_CF01_Contract::block_placeholder_session(1001)===1001);
}

$check('unrelated non-reserved account remains untouched by reserved-login ban',
 SMC_CF01_Contract::block_placeholder_login(new WP_User(1001)) instanceof WP_User);

$r=$invoke(array('insert'=>true));
$check('insert failure neither verifies nor fabricates user',empty($r['verified'])&&!get_userdata(901));

$bad=array_filter($assertions,function($v){return !$v[1];});
if($bad){foreach($bad as $item)fwrite(STDERR,'File00 placeholder persistence FAIL '.$item[0]."\n");exit(1);}
echo 'File00 placeholder persistence '.count($assertions).'/'.count($assertions)." PASS\n";

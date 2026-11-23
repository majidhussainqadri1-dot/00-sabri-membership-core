<?php
/** File 00 V12.3 File04 owner-side placeholder provider runtime contract. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
class WP_Error { private $code; public function __construct($code=''){ $this->code=$code; } public function get_error_code(){ return $this->code; } }
class WP_User { public $ID; public $user_login; public $display_name; public function __construct($id,$login=''){ $this->ID=$id; $this->user_login=$login; } }
$GLOBALS['f00_users']=array(); $GLOBALS['f00_meta']=array(); $GLOBALS['f00_logins']=array();
$GLOBALS['f00_actions']=array(); $GLOBALS['f00_filters']=array(); $GLOBALS['f00_next_id']=900;
$GLOBALS['f00_logged_in']=true; $GLOBALS['f00_caps']=array('manage_options'=>true,'sabri_feed_run_migrations'=>true);
$GLOBALS['f00_fail_audit']=''; $GLOBALS['f00_erasure_locked']=array();
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function __( $text, $domain = null ) { unset( $domain ); return $text; }
function is_wp_error($v){return $v instanceof WP_Error;} function is_user_logged_in(){return !empty($GLOBALS['f00_logged_in']);}
function current_user_can($cap){return !empty($GLOBALS['f00_caps'][$cap]);}
function smc_privacy_erasure_lock($id){return !empty($GLOBALS['f00_erasure_locked'][(int)$id]);}
function add_action($tag,$callback,$priority=10,$accepted=1){$GLOBALS['f00_actions'][$tag][]=$callback;return true;}
function add_filter($tag,$callback,$priority=10,$accepted=1){$GLOBALS['f00_filters'][$tag][]=$callback;return true;}
function get_userdata($id){return $GLOBALS['f00_users'][(int)$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['f00_meta'][(int)$id][$key]??'';}
function add_user_meta($id,$key,$value,$unique=false){if($unique&&array_key_exists($key,$GLOBALS['f00_meta'][(int)$id]??array()))return false;$GLOBALS['f00_meta'][(int)$id][$key]=$value;return true;}
function delete_user_meta($id,$key,$value=null){if(!isset($GLOBALS['f00_meta'][(int)$id][$key]))return false;if(null!==$value&&$GLOBALS['f00_meta'][(int)$id][$key]!==$value)return false;unset($GLOBALS['f00_meta'][(int)$id][$key]);return true;}
function get_user_by($field,$value){if('login'!==$field)return false;$id=$GLOBALS['f00_logins'][(string)$value]??0;return $id?get_userdata($id):false;}
function get_users($args){if(!is_array($args)||($args['fields']??'')!=='ids'||(int)($args['number']??0)!==2||($args['meta_key']??'')!=='_smc_platform_uuid_v1')return false;$ids=array();foreach($GLOBALS['f00_meta'] as $id=>$meta){if(($meta['_smc_platform_uuid_v1']??'')===($args['meta_value']??''))$ids[]=(int)$id;}return array_slice($ids,0,2);}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000000077';}
function wp_generate_password($length=12,$special=true,$extra=true){unset($special,$extra);return str_repeat('P',(int)$length);}
function wp_insert_user($data){$id=$GLOBALS['f00_next_id']++;$u=new WP_User($id,(string)($data['user_login']??''));$u->display_name=(string)($data['display_name']??'');$GLOBALS['f00_users'][$id]=$u;$GLOBALS['f00_logins'][$u->user_login]=$id;foreach((array)($data['meta_input']??array()) as $key=>$value){$GLOBALS['f00_meta'][$id][$key]=$value;}return $id;}
function wp_delete_user($id){$u=$GLOBALS['f00_users'][(int)$id]??false;if($u)unset($GLOBALS['f00_logins'][$u->user_login]);unset($GLOBALS['f00_users'][(int)$id],$GLOBALS['f00_meta'][(int)$id]);return true;}
class SMC_Security { public static function audit($action,$user_id=0,$data=array()){unset($user_id,$data);return $GLOBALS['f00_fail_audit']!==$action;} }
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
function f00_check($condition,$label,&$cases){$cases[]=array($label,(bool)$condition);}
$cases=array(); SMC_CF01_Contract::init();
f00_check(!empty($GLOBALS['f00_actions']['user_register']),'UUID assignment hook registered',$cases);
f00_check(!empty($GLOBALS['f00_filters']['sabri_file00_platform_uuid_v1']),'File04 UUID provider registered',$cases);
f00_check(!empty($GLOBALS['f00_filters']['sabri_file00_legacy_author_placeholder_v1']),'File04 placeholder provider registered',$cases);
f00_check(!empty($GLOBALS['f00_filters']['wp_authenticate_user']),'placeholder login blocker registered',$cases);
$invalid=SMC_CF01_Contract::legacy_author_placeholder(array('verified'=>true),array('file_number'=>'04','legacy_id'=>10,'request_digest'=>'bad'));
f00_check(is_array($invalid)&&empty($invalid['verified']),'malformed request denied',$cases);
$GLOBALS['f00_logged_in']=false;$no_actor=SMC_CF01_Contract::legacy_author_placeholder(null,array('file_number'=>'04','legacy_id'=>10,'request_digest'=>str_repeat('a',64)));
f00_check(is_array($no_actor)&&empty($no_actor['verified']),'anonymous placeholder creation denied',$cases);$GLOBALS['f00_logged_in']=true;
$request=array('file_number'=>'04','legacy_id'=>501,'legacy_author_id'=>999,'request_digest'=>str_repeat('a',64));
$spoof=array('verified'=>true,'provider_id'=>'third_party','user_id'=>123,'platform_uuid'=>'00000000-0000-4000-8000-000000000123');
$claim=SMC_CF01_Contract::legacy_author_placeholder($spoof,$request);$id=(int)($claim['user_id']??0);$u=get_userdata($id);
f00_check(!empty($claim['verified'])&&'file00_legacy_author_placeholder_v1'===($claim['provider_id']??''),'File00 creates its own claim rather than trusting earlier filter data',$cases);
f00_check($id>0&&$u instanceof WP_User&&'sabri_legacy_unknown_author'===$u->user_login,'reserved non-login placeholder account created',$cases);
f00_check('1'===get_user_meta($id,'_smc_legacy_author_placeholder_v1',true),'owner placeholder metadata persisted',$cases);
f00_check('unknown_or_deleted_legacy_author'===($claim['attribution_class']??''),'placeholder attribution class is explicit',$cases);
f00_check(SMC_CF01_Contract::resolve_user_id_by_subject_uuid($claim['platform_uuid'])===$id,'placeholder UUID reverse-resolves to its owner',$cases);
f00_check(SMC_CF01_Contract::is_legacy_author_placeholder($id),'File00 exposes an owner-checked placeholder predicate',$cases);
$login=SMC_CF01_Contract::block_placeholder_login($u,'ignored');f00_check(is_wp_error($login)&&'smc_legacy_author_placeholder_login_denied'===$login->get_error_code(),'placeholder cannot authenticate',$cases);
$spoofed_uuid='00000000-0000-4000-8000-000000000088';
$projected=SMC_CF01_Contract::filter_platform_uuid($spoofed_uuid,$id,array('file_number'=>'04','purpose'=>'legacy_publication_migration'));
f00_check($projected===$claim['platform_uuid'],'UUID provider ignores earlier injected UUID',$cases);
$GLOBALS['f00_erasure_locked'][$id]=true;
$locked_projection=SMC_CF01_Contract::filter_platform_uuid('',$id,array('file_number'=>'04','purpose'=>'legacy_publication_migration'));
f00_check(''===$locked_projection&&!SMC_CF01_Contract::is_legacy_author_placeholder($id),'erasure-locked identity is not projected to File04',$cases);
$GLOBALS['f00_erasure_locked'][$id]=false;
$wrong_context=SMC_CF01_Contract::filter_platform_uuid('',$id,array('file_number'=>'08','purpose'=>'other'));f00_check(''===$wrong_context,'UUID provider refuses wrong consumer purpose',$cases);
$GLOBALS['f00_meta'][$id]['_smc_legacy_author_placeholder_v1']='0';
$collision=SMC_CF01_Contract::legacy_author_placeholder(null,$request);
f00_check(is_array($collision)&&empty($collision['verified'])&&'smc_legacy_author_placeholder_collision'===($collision['error_code']??''),'reserved login collision with non-placeholder identity rejected',$cases);
$GLOBALS['f00_meta'][$id]['_smc_legacy_author_placeholder_v1']='1';wp_delete_user($id);$GLOBALS['f00_fail_audit']='legacy_author_placeholder_created';
$audit_failure=SMC_CF01_Contract::legacy_author_placeholder(null,$request);
f00_check(is_array($audit_failure)&&empty($audit_failure['verified']),'placeholder creation fails when audit persistence fails',$cases);
f00_check(false===get_user_by('login','sabri_legacy_unknown_author'),'failed placeholder creation is compensated by user deletion',$cases);
$failed=array_values(array_filter($cases,static function($case){return !$case[1];}));
if($failed){foreach($failed as $case){fwrite(STDERR,'File00 File04 provider FAIL: '.$case[0]."\n");}exit(1);}
echo 'File00 File04 placeholder provider runtime: '.count($cases).'/'.count($cases)." PASS\n";

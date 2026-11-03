<?php
/**
 * File00 V12 / PR52 reconciliation: no foreign UUID projection or
 * re-labeling of a real reserved-login user as a deleted-author sentinel.
 * Standalone fail-closed owner tests, PHP 7.4+.
 */
defined('ABSPATH') || define('ABSPATH', __DIR__.'/');
$GLOBALS['users'] = array(
  5=>(object)array('ID'=>5),77=>(object)array('ID'=>77),88=>(object)array('ID'=>88));
$GLOBALS['user_meta'] = array(
  5=>array('_smc_platform_uuid_v1'=>'00000000-0000-4000-8000-000000000005'),
  77=>array('_smc_platform_uuid_v1'=>'00000000-0000-4000-8000-000000000077'),
  88=>array('_smc_platform_uuid_v1'=>'00000000-0000-4000-8000-000000000088'));
$GLOBALS['new_users']=0;
$GLOBALS['smc_v12_signed_in']=true;
$GLOBALS['smc_v12_migration_cap']=true;
$GLOBALS['smc_v12_legacy_cap']=false;
function absint($x){return abs((int)$x);}
function get_userdata($x){return $GLOBALS['users'][(int)$x] ?? false;}
function get_user_meta($x,$key,$single=true){return $GLOBALS['user_meta'][(int)$x][$key] ?? '';}
function update_user_meta($x,$key,$value){$GLOBALS['user_meta'][(int)$x][$key]=$value;return true;}
function username_exists($x){return $x==='sabri_legacy_author_placeholder'?5:0;}
function wp_insert_user($args){$GLOBALS['new_users']++;return new WP_Error('unexpected_create');}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000009999';}
function is_wp_error($x){return $x instanceof WP_Error;}
function __($s,$domain=''){return $s;}
function is_user_logged_in(){return $GLOBALS['smc_v12_signed_in'];}
function get_current_user_id(){return $GLOBALS['smc_v12_signed_in']?42:0;}
function current_user_can($cap){
    if($cap==='sabri_file04_run_migrations')return $GLOBALS['smc_v12_migration_cap'];
    if($cap==='sabri_feed_run_migrations')return $GLOBALS['smc_v12_legacy_cap'];
    return false;
}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
$GLOBALS['smc_v12_filter_map']=array();
function add_action($tag,$callback,$priority=10,$accepted=1) { return true; }
function add_filter($tag,$callback,$priority=10,$accepted=1) {
    $GLOBALS['smc_v12_filter_map'][$tag][] = array('callback'=>$callback,'accepted'=>$accepted);
    return true;
}
function apply_filters($tag,$value,...$args) {
    foreach ($GLOBALS['smc_v12_filter_map'][$tag]??array() as $entry) {
        $all=array_merge(array($value),$args);
        $value=call_user_func_array($entry['callback'],array_slice($all,0,$entry['accepted']));
    }
    return $value;
}

class WP_Error{public $code;function __construct($s){$this->code=$s;}}
class WP_User{public $ID;function __construct($id){$this->ID=$id;}}
class SMC_Security{public static function audit($n,$id,$a=array()){return true;}}
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
$tests=array();$test=function($condition,$label)use(&$tests){$tests[]=array((bool)$condition,$label);};
$canonical='00000000-0000-4000-8000-000000000077';
$foreign='00000000-0000-4000-8000-000000000088';
$ctx=array('file_number'=>'04','legacy_id'=>123,'purpose'=>'legacy_publication_migration');
$test(SMC_CF01_Contract::file04_platform_uuid($canonical,77,$ctx)===$canonical,'own canonical source remains stable');
$GLOBALS['smc_v12_migration_cap']=false;
$GLOBALS['smc_v12_legacy_cap']=true;
$legacyAuthorizedUUID=SMC_CF01_Contract::file04_platform_uuid('untrusted',77,$ctx);
$test($legacyAuthorizedUUID===$canonical,'current File04 canonical File21 migration fallback is accepted');
$GLOBALS['smc_v12_legacy_cap']=false;
$GLOBALS['smc_v12_migration_cap']=true;
$test(SMC_CF01_Contract::file04_platform_uuid($foreign,77,$ctx)===$canonical,'forged other-account UUID cannot override File00 owner');
$test(SMC_CF01_Contract::file04_platform_uuid($canonical,999,$ctx)==='','deleted or unknown user cannot inherit a supplied UUID');
$test(SMC_CF01_Contract::file04_platform_uuid('not-uuid',77,$ctx)===$canonical,'untrusted malformed UUID cannot replace canonical owner');
$unsigned=array('file_number'=>'04','legacy_id'=>123,'legacy_author_id'=>0);
ksort($unsigned,SORT_STRING);
$request=$unsigned;
$request['request_digest']=hash('sha256',wp_json_encode($unsigned,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$placeholder=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($placeholder)&&empty($placeholder['verified']),'reserved-login real user collision is denied');
$test($GLOBALS['new_users']===0,'collision never creates another account');
$wrongContext=SMC_CF01_Contract::file04_platform_uuid('',77,array('file_number'=>'09','legacy_id'=>123,'purpose'=>'legacy_publication_migration'));
$test($wrongContext==='','File04 UUID filter rejects wrong consumer');
$wrongPurpose=SMC_CF01_Contract::file04_platform_uuid('',77,array('file_number'=>'04','legacy_id'=>123,'purpose'=>'public_profile'));
$test($wrongPurpose==='','File04 UUID filter refuses unrelated purpose');
$GLOBALS['smc_v12_signed_in']=false;
$guestUUID=SMC_CF01_Contract::file04_platform_uuid('',77,$ctx);
$test($guestUUID==='','anonymous caller cannot mint another user UUID through migration filter');
$guestPlaceholder=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($guestPlaceholder)&&empty($guestPlaceholder['verified']),'anonymous caller cannot create placeholder with digest');
$GLOBALS['smc_v12_signed_in']=true;
$GLOBALS['smc_v12_migration_cap']=false;
$unprivilegedPlaceholder=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($unprivilegedPlaceholder)&&empty($unprivilegedPlaceholder['verified']),'authenticated user without migration capability denied');
$GLOBALS['smc_v12_migration_cap']=true;

$invalid=$request;$invalid['request_digest']='not-a-digest';
$denied=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$invalid);
$test(is_array($denied)&&empty($denied['verified']),'invalid migration digest is rejected');
// Once a reserved account has been independently verified as a sentinel,
// its sign-in still fails, while an unrelated person is not blocked.
$fake=$request;$fake['request_digest']=str_repeat('b',64);
$badDigest=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$fake);
$test(is_array($badDigest)&&empty($badDigest['verified']),'arbitrary well-formed but incorrect digest fails closed');
$wrongFile=$request;$wrongFile['file_number']='09';
$badFile=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$wrongFile);
$test(is_array($badFile)&&empty($badFile['verified']),'placeholder rejects wrong consumer file identifier');
$spoofed=array('verified'=>true,'user_id'=>77,'platform_uuid'=>$canonical,'request_digest'=>$request['request_digest']);
$untrusted=SMC_CF01_Contract::file04_legacy_author_placeholder($spoofed,$request);
$test(is_array($untrusted)&&empty($untrusted['verified']),'preverified upstream filter payload cannot bypass File00 owner');
$GLOBALS['user_meta'][5]['_smc_legacy_author_placeholder_v1']=1;
$GLOBALS['user_meta'][5]['_smc_login_disabled_v1']=1;
$valid=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($valid)&&!empty($valid['verified'])&&$valid['user_id']===5,'verified reserved sentinel remains a stable placeholder');
$test((string)get_user_meta(5,'_smc_legacy_author_placeholder_audited_v1',true)==='1','existing sentinel is recovered through a durable File00 audit attestation');

$forgedWhenReady=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$fake);
$test(is_array($forgedWhenReady)&&empty($forgedWhenReady['verified']),'forged 64-hex digest rejected even with real sentinel already present');
$GLOBALS['smc_v12_migration_cap']=false;
$unauthorizedWhenReady=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($unauthorizedWhenReady)&&empty($unauthorizedWhenReady['verified']),'ordinary user cannot reuse valid sentinel to claim migration authority');
$GLOBALS['smc_v12_migration_cap']=true;
$GLOBALS['smc_v12_signed_in']=false;
$anonymousWhenReady=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($anonymousWhenReady)&&empty($anonymousWhenReady['verified']),'anonymous user cannot reuse existing verified sentinel');
$GLOBALS['smc_v12_signed_in']=true;

$independentlyVerified=SMC_CF01_Contract::file04_legacy_author_placeholder($spoofed,$request);
$test(is_array($independentlyVerified)&&!empty($independentlyVerified['verified'])
  &&$independentlyVerified['user_id']===5
  &&$independentlyVerified['platform_uuid']==='00000000-0000-4000-8000-000000000005',
  'preverified upstream receipt is replaced by File00 authoritative identity');

$sentinel=new WP_User(5);$person=new WP_User(77);
$blocked=SMC_CF01_Contract::block_placeholder_login($sentinel,'ignored','secret');
$test(is_wp_error($blocked),'legacy attribution sentinel explicitly denied normal sign-in');
$unchanged=SMC_CF01_Contract::block_placeholder_login($person,'ignored','secret');
$test($unchanged===$person,'real member is not blocked by placeholder login policy');
// Consumer-shaped WordPress hook invocation: source payload is exactly the
// three-field canonicalized request emitted by File04 main authorship_preflight.
SMC_CF01_Contract::init();
$hookUUID=apply_filters('sabri_file00_platform_uuid_v1','',77,$ctx);
$test($hookUUID===$canonical,'registered File04 UUID filter returns canonical author subject');
$hookPlaceholder=apply_filters('sabri_file00_legacy_author_placeholder_v1',array('verified'=>false),$request);
$test(is_array($hookPlaceholder)&&!empty($hookPlaceholder['verified'])
  &&$hookPlaceholder['user_id']===5
  &&$hookPlaceholder['request_digest']===$request['request_digest']
  &&$hookPlaceholder['legacy_id']===123
  &&$hookPlaceholder['legacy_author_id']===0,
  'registered File04 placeholder filter returns request-bound File00 owner receipt');
$hookLogin=apply_filters('authenticate',new WP_User(5),'sabri_legacy_author_placeholder','blocked');
$test(is_wp_error($hookLogin),'registered authenticate filter denies placeholder sign-in');
$hookNormal=apply_filters('wp_authenticate_user',new WP_User(77),'secret');
$test($hookNormal instanceof WP_User && $hookNormal->ID===77,'registered WordPress user filter allows real member');

$bad=array_values(array_filter($tests,function($a){return !$a[0];}));
if($bad){foreach($bad as $x){fwrite(STDERR,'File00 File04 FAIL '.$x[1]."\n");}exit(1);}
echo 'File00 File04 owner guard '.count($tests).'/'.count($tests)." PASS\n";

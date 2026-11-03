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
function absint($x){return abs((int)$x);}
function get_userdata($x){return $GLOBALS['users'][(int)$x] ?? false;}
function get_user_meta($x,$key,$single=true){return $GLOBALS['user_meta'][(int)$x][$key] ?? '';}
function username_exists($x){return $x==='sabri_legacy_author_placeholder'?5:0;}
function wp_insert_user($args){$GLOBALS['new_users']++;return new WP_Error('unexpected_create');}
function wp_generate_uuid4(){return '00000000-0000-4000-8000-000000009999';}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{public $code;function __construct($s){$this->code=$s;}}
class WP_User{public $ID;function __construct($id){$this->ID=$id;}}
class SMC_Security{public static function audit($n,$id,$a=array()){return true;}}
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
$tests=array();$test=function($condition,$label)use(&$tests){$tests[]=array((bool)$condition,$label);};
$canonical='00000000-0000-4000-8000-000000000077';
$foreign='00000000-0000-4000-8000-000000000088';
$ctx=array('file_number'=>'04','purpose'=>'legacy_publication_migration');
$test(SMC_CF01_Contract::file04_platform_uuid($canonical,77,$ctx)===$canonical,'own canonical source remains stable');
$test(SMC_CF01_Contract::file04_platform_uuid($foreign,77,$ctx)===$canonical,'forged other-account UUID cannot override File00 owner');
$test(SMC_CF01_Contract::file04_platform_uuid($canonical,999,$ctx)==='','deleted or unknown user cannot inherit a supplied UUID');
$test(SMC_CF01_Contract::file04_platform_uuid('not-uuid',77,$ctx)===$canonical,'untrusted malformed UUID cannot replace canonical owner');
$request=array('file_number'=>'04','legacy_id'=>123,'legacy_author_id'=>0,'request_digest'=>str_repeat('b',64));
$placeholder=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($placeholder)&&empty($placeholder['verified']),'reserved-login real user collision is denied');
$test($GLOBALS['new_users']===0,'collision never creates another account');
$invalid=$request;$invalid['request_digest']='not-a-digest';
$denied=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$invalid);
$test(is_array($denied)&&empty($denied['verified']),'invalid migration digest is rejected');
// Once a reserved account has been independently verified as a sentinel,
// its sign-in still fails, while an unrelated person is not blocked.
$GLOBALS['user_meta'][5]['_smc_legacy_author_placeholder_v1']=1;
$GLOBALS['user_meta'][5]['_smc_login_disabled_v1']=1;
$valid=SMC_CF01_Contract::file04_legacy_author_placeholder(null,$request);
$test(is_array($valid)&&!empty($valid['verified'])&&$valid['user_id']===5,'verified reserved sentinel remains a stable placeholder');
$sentinel=new WP_User(5);$person=new WP_User(77);
$blocked=SMC_CF01_Contract::block_placeholder_login($sentinel,'ignored','secret');
$test(is_wp_error($blocked),'legacy attribution sentinel explicitly denied normal sign-in');
$unchanged=SMC_CF01_Contract::block_placeholder_login($person,'ignored','secret');
$test($unchanged===$person,'real member is not blocked by placeholder login policy');
$bad=array_values(array_filter($tests,function($a){return !$a[0];}));
if($bad){foreach($bad as $x){fwrite(STDERR,'File00 File04 FAIL '.$x[1]."\n");}exit(1);}
echo 'File00 File04 owner guard '.count($tests).'/'.count($tests)." PASS\n";

<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('SMC_CONTRACT_VERSION', '1.2.1');
$GLOBALS['meta'] = [];
$GLOBALS['users'] = [];
$GLOBALS['apps'] = [];
function __($s,$d=''){return $s;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function get_user_meta($uid,$key,$single=true){return $GLOBALS['meta'][$uid][$key] ?? '';}
function get_userdata($uid){return $GLOBALS['users'][$uid] ?? false;}
function user_can($user,$cap){return is_object($user) && !empty($user->caps[$cap]);}
function get_option($key,$default=false){return $key==='smc_founder_user_id' ? 10 : $default;}
class FakeWpdb { public $prefix='wp_'; public function prepare($q,...$args){return $q;} public function get_row($q,$mode){foreach($GLOBALS['apps'] as $app){return $app;} return null;} }
$GLOBALS['wpdb'] = new FakeWpdb();
require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/functions.php';
function assert_state($uid,$expected,$label){$s=smc_membership_state($uid); if($s['status']!==$expected || $s['approved']!==false){fwrite(STDERR,"$label failed: ".json_encode($s)."\n"); exit(1);} }
$GLOBALS['users'][10]=(object)['caps'=>[]];
$GLOBALS['users'][20]=(object)['caps'=>['manage_options'=>true]];
$GLOBALS['users'][30]=(object)['caps'=>[]];
foreach([10,20,30] as $uid){$GLOBALS['meta'][$uid]['_smc_privacy_erasure_lock']=['version'=>1,'locked_at'=>'2026-08-02 15:00:00','receipt'=>'x'];}
assert_state(10,'erasure_pending','Founder erasure lock');
assert_state(20,'erasure_pending','Administrator erasure lock');
assert_state(30,'erasure_pending','Member erasure lock');
echo "privacy erasure runtime: 3 PASS, 0 FAIL\n";

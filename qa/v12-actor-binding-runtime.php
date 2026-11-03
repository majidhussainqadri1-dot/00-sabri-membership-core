<?php
/**
 * V12 F00-EXT-003 / F00-EXT-017: actor must be the logged-in principal.
 * Negative runtime tests on PHP 7.4+ without modifying production WordPress.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['smc_v12_actor'] = 0;
function absint($v) { return abs((int)$v); }
function get_current_user_id() { return $GLOBALS['smc_v12_actor']; }
function smc_is_founder($v) { return (int)$v===1; }
function current_user_can($v) { return in_array($v,array('allowed','smc_manage_membership'),true); }
define('DAY_IN_SECONDS',86400);
define('YEAR_IN_SECONDS',31536000);
define('ARRAY_A','ARRAY_A');
function __($s,$domain='') { return $s; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v)); }
function get_userdata($v) { return $v>0 ? (object)array('ID'=>$v) : false; }
function get_user_meta($v,$key,$single=true) { return $GLOBALS['smc_v12_meta'][$v][$key] ?? ''; }
function update_user_meta($v,$key,$value) { return false; } // Fault injector: prevent unauthorized mutations.
function metadata_exists($type,$id,$key) { return isset($GLOBALS['smc_v12_meta'][$id][$key]); }
function apply_filters($tag,$value,...$args) {
    if($tag==='smc_reverification_interval_seconds') return 10*YEAR_IN_SECONDS;
    if($tag==='smc_system_reverification_authorized') return true; // untrusted legacy boolean
    return $value;
}
class WP_Error {
    private $code;
    function __construct($code,$message='') { $this->code=$code; }
    function get_error_code() { return $this->code; }
}
function is_wp_error($x){return $x instanceof WP_Error;}
class SMC_Security { public static function subject_hash($id){return hash('sha256','test-subject-'.$id);} }
class SMC_V12_WPDB {
    public $prefix='wp_';
    function prepare($sql,...$args) { return $sql; }
    function get_results($sql,$mode=null) { return array(array('id'=>1,'action'=>'contact_verified','created_at'=>'2026-11-01 00:00:00')); }
}
$wpdb = new SMC_V12_WPDB();
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php';
$method = new ReflectionMethod('SMC_Advanced_Trust_2026', 'actor_is_current');
$method->setAccessible(true);
$cases = array();
$check = function($name,$expected,$actor,$cap='',$founder=false) use($method,&$cases) {
    $actual = $method->invoke(null,$actor,$cap,$founder);
    $cases[] = array($name,$expected === $actual);
};
$check('anonymous must never bind actor-id 1',false,1);
$check('anonymous cannot claim Founder bypass',false,1,'',true);
$check('anonymous cannot use capability spoof',false,1,'allowed');
$GLOBALS['smc_v12_actor']=2;
$check('different signed-in principal rejected',false,1);
$GLOBALS['smc_v12_actor']=1;
$check('correct signed-in principal accepted',true,1);
$check('capability mismatch rejected',false,1,'missing');
$check('proper active capability accepted',true,1,'allowed');
$check('Founder session must be identity-bound',true,1,'',true);

// F00-EXT-004 — ten-year policy filters and persisted due timestamps cannot
// silently extend the canonical maximum one-year assurance interval.
$GLOBALS['smc_v12_meta'][1][SMC_Advanced_Trust_2026::REVERIFY_META]=array(
  'verified_at'=>time()-YEAR_IN_SECONDS-300,
  'due_at'=>time()+5*YEAR_IN_SECONDS, 'source'=>'manual_review'
);
$reverify=SMC_Advanced_Trust_2026::reverification_status(1);
$cases[]=array('reverification policy interval has one-year ceiling',
  $reverify['interval_seconds']<=YEAR_IN_SECONDS);
$cases[]=array('stored future due date never extends beyond canonical maximum',
  $reverify['due_at'] <= $reverify['verified_at']+YEAR_IN_SECONDS && empty($reverify['current']) && !empty($reverify['overdue']));

// F00-EXT-004 — unsigned boolean system authorization must fail closed.
$sys=SMC_Advanced_Trust_2026::mark_reverified(1,0,'system');
$cases[]=array('bare boolean adapter cannot authorize system reverification',
  is_wp_error($sys)&&$sys->get_error_code()==='smc_reverify_authorization');

// F00-EXT-020 — cross-subject timeline needs current typed and purpose-bound
// authority, not a general membership-management capability alone.
$GLOBALS['smc_v12_actor']=2;
$timeline=SMC_Advanced_Trust_2026::trust_timeline(1);
$cases[]=array('cross-subject trust timeline denies blanket management role',
  is_array($timeline)&&count($timeline)===0);
$GLOBALS['smc_v12_actor']=1;
$own=SMC_Advanced_Trust_2026::trust_timeline(1);
$cases[]=array('own trust timeline remains readable without cross-subject grant',
  is_array($own)&&count($own)===1);

$failed=array_values(array_filter($cases,function($x){return !$x[1];}));
if($failed) {
    foreach($failed as $failure) { fwrite(STDERR,'FAIL: '.$failure[0]."\n"); }
    exit(1);
}
echo 'File00 v12 actor binding: '.count($cases).'/'.count($cases)." PASS\n";

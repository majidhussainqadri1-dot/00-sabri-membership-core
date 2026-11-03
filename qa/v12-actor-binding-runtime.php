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
function current_user_can($v) { return 'allowed'===$v; }
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
$failed=array_values(array_filter($cases,function($x){return !$x[1];}));
if($failed) {
    foreach($failed as $failure) { fwrite(STDERR,'FAIL: '.$failure[0]."\n"); }
    exit(1);
}
echo 'File00 v12 actor binding: '.count($cases).'/'.count($cases)." PASS\n";

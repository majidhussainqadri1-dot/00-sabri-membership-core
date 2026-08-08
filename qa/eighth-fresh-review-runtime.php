<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS',86400);
if (!defined('YEAR_IN_SECONDS')) define('YEAR_IN_SECONDS',31536000);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS',60);
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim((string)$v);} function wp_salt($s=''){return 'eighth-test-salt';}
$GLOBALS['e8_filters']=array(); function apply_filters($tag,$value,...$args){return array_key_exists($tag,$GLOBALS['e8_filters'])?$GLOBALS['e8_filters'][$tag]:$value;}
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php';
$pass=0;$fail=0;function e8($n,$v){global $pass,$fail;echo ($v?'PASS ':'FAIL ').$n."\n";$v?$pass++:$fail++;}
$rc=new ReflectionClass('SMC_Advanced_Trust_2026');
$m=$rc->getMethod('reverification_interval_seconds');$m->setAccessible(true);$GLOBALS['e8_filters']['smc_reverification_interval_seconds']=YEAR_IN_SECONDS*10;e8('annual maximum cannot be weakened',$m->invoke(null,7)===YEAR_IN_SECONDS);$GLOBALS['e8_filters']['smc_reverification_interval_seconds']=3600;e8('stricter interval retained',$m->invoke(null,7)===DAY_IN_SECONDS);
$sr=$rc->getMethod('subject_reference');$sr->setAccessible(true);$subject=$sr->invoke(null,7);$auth=$rc->getMethod('system_reverification_authorized');$auth->setAccessible(true);$GLOBALS['e8_filters']['smc_system_reverification_authorization_v1']=true;e8('bare boolean system authority rejected',$auth->invoke(null,7,'scheduled_review')===false);$GLOBALS['e8_filters']['smc_system_reverification_authorization_v1']=array('owner'=>'file00','contract_version'=>'1.0.0','authorized'=>true,'source'=>'scheduled_review','subject'=>$subject,'asserted_at'=>time());e8('typed fresh system authority accepted',$auth->invoke(null,7,'scheduled_review')===true);
$pr=$rc->getMethod('prune_break_glass_requests');$pr->setAccessible(true);$bad=array('bad'=>array('id'=>'bad','subject_user_id'=>1,'opened_at'=>time(),'expires_at'=>0));$out=$pr->invoke(null,$bad);e8('malformed no-expiry breakglass pruned',count($out)===0);
echo "Eighth fresh runtime: $pass PASS / $fail FAIL\n"; if($fail) exit(1);

<?php
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/'); define('DB_NAME','test'); define('SMC_DB_VERSION','1.3.0'); define('SMC_VERSION','1.2.14');
$options=['smc_db_version'=>'1.2.9'];
function get_option($k,$d=false){global $options;return array_key_exists($k,$options)?$options[$k]:$d;}
function update_option($k,$v,$autoload=null){global $options;$options[$k]=$v;return true;}
function delete_option($k){global $options;unset($options[$k]);return true;}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
function current_time($t,$gmt=false){return '2026-08-08 03:45:00';}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
class SMC_Security{public static $audits=[];public static function audit($a,$u=0,$d=[]){self::$audits[]=[$a,$u,$d];return true;}}
class WPDBSecondReviewStub{public $prefix='wp_';public function prepare($q,...$a){return $q;}public function get_var($q){if(strpos($q,'GET_LOCK')!==false)return 0;return null;}}
$wpdb=new WPDBSecondReviewStub();$GLOBALS['wpdb']=$wpdb;
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-installer.php';
$threw=false;try{SMC_Installer::maybe_upgrade();}catch(Throwable $e){$threw=true;}
$recorded=get_option('smc_last_migration_failure',[]);
$ok=!$threw && is_array($recorded) && ($recorded['scope']??'')==='upgrade';
echo ($ok?'PASS ':'FAIL ')."migration lock contention is safely deferred without request-fatal exception\n";
exit($ok?0:1);

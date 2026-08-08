#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
installer=root/'source/sabri-membership-core/includes/class-smc-installer.php'
s=installer.read_text()
old="""\tpublic static function maybe_upgrade() {\n\t\tif ( SMC_DB_VERSION === get_option( 'smc_db_version', '' ) ) {\n\t\t\treturn;\n\t\t}\n\t\t$lock = self::acquire_lock( 1 );\n\t\ttry {\n\t\t\tself::create_tables();\n\t\t\tself::create_roles();\n\t\t\tself::create_pages();\n\t\t\tif ( ! self::backfill_role_grants() ) {\n\t\t\t\treturn;\n\t\t\t}\n\t\t\tself::run_legacy_batch();\n\t\t} catch ( Throwable $error ) {\n\t\t\tself::record_failure( 'upgrade', $error );\n\t\t} finally {\n\t\t\tself::release_lock( $lock );\n\t\t}\n\t}\n"""
new="""\tpublic static function maybe_upgrade() {\n\t\tif ( SMC_DB_VERSION === get_option( 'smc_db_version', '' ) ) {\n\t\t\treturn;\n\t\t}\n\t\t$lock = null;\n\t\ttry {\n\t\t\t$lock = self::acquire_lock( 1 );\n\t\t\tself::create_tables();\n\t\t\tself::create_roles();\n\t\t\tself::create_pages();\n\t\t\tif ( ! self::backfill_role_grants() ) {\n\t\t\t\treturn;\n\t\t\t}\n\t\t\tself::run_legacy_batch();\n\t\t} catch ( Throwable $error ) {\n\t\t\tself::record_failure( 'upgrade', $error );\n\t\t} finally {\n\t\t\tif ( is_array( $lock ) ) {\n\t\t\t\tself::release_lock( $lock );\n\t\t\t}\n\t\t}\n\t}\n"""
if old not in s: raise SystemExit('round2 maybe_upgrade block not found')
installer.write_text(s.replace(old,new,1))

test=root/'qa/second-fresh-review-runtime.php'
test.write_text(r'''<?php
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
''')

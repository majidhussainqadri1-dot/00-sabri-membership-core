<?php
class WP_Error { public $code; public $message; function __construct($c,$m=''){ $this->code=$c; $this->message=$m; } function get_error_message(){ return $this->message; } }
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($s,$d=null){ return $s; }
function sanitize_key($s){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function wp_salt($scheme='auth'){ return 'third-fresh-test-salt-2026'; }
function add_action(){ }
function hash_equals_safe($a,$b){ return is_string($a)&&is_string($b)&&hash_equals($a,$b); }
define('ABSPATH', __DIR__.'/');
define('SMC_MASTER_KEY', '0123456789abcdef0123456789abcdef0123456789abcdef');
define('SMC_MASTER_KEY_ID', 'file00-main-key-2026');
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-security.php';
$pass=0;$fail=0;
function t($name,$ok){ global $pass,$fail; echo ($ok?'PASS':'FAIL')." $name\n"; $ok?$pass++:$fail++; }
t('explicit key id returned', SMC_Security::key_id()==='file00-main-key-2026');
$ctx=array('user_id'=>7,'scope'=>'test');
$env=SMC_Security::encrypt('secret','runtime-test',$ctx);
t('new envelope created', is_string($env)&&strpos($env,'SMC2.')===0);
$parts=is_string($env)?explode('.',$env,5):array();
$aad=count($parts)===5?json_decode(base64_decode($parts[1]),true):array();
t('new envelope uses non-secret key id', ($aad['kid']??'')==='file00-main-key-2026');
t('new envelope decrypts', SMC_Security::decrypt($env,'runtime-test',$ctx)==='secret');
$key=hash_hkdf('sha256',SMC_MASTER_KEY,32,'sabri-membership-core:v2',wp_salt('auth'));
$legacyKid=substr(hash('sha256',$key),0,16);
$legacyAad=json_encode(array('v'=>2,'kid'=>$legacyKid,'purpose'=>'runtime-test','context'=>$ctx), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt('legacy','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$legacyAad,16);
$legacy='SMC2.'.base64_encode($legacyAad).'.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($cipher);
t('legacy SMC2 key id remains decrypt-compatible', SMC_Security::decrypt($legacy,'runtime-test',$ctx)==='legacy');
$badAad=json_encode(array('v'=>2,'kid'=>'wrong-key','purpose'=>'runtime-test','context'=>$ctx), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$nonce2=random_bytes(12);$tag2='';$cipher2=openssl_encrypt('bad','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce2,$tag2,$badAad,16);
$bad='SMC2.'.base64_encode($badAad).'.'.base64_encode($nonce2).'.'.base64_encode($tag2).'.'.base64_encode($cipher2);
t('unknown key id rejected', is_wp_error(SMC_Security::decrypt($bad,'runtime-test',$ctx)));
echo "Third fresh runtime: $pass PASS / $fail FAIL\n";
exit($fail?1:0);

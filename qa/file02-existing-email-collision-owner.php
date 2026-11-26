<?php
/** Actual File00 decision code, isolated synthetic membership/auth/audit evidence. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');define('SMC_DB_VERSION','1.4.5');
class WP_User {public $ID=17,$user_email='member@example.test';}
$GLOBALS['actor']=17;$GLOBALS['session']='fixture';$GLOBALS['schema']='1.4.5';$GLOBALS['privacy']=false;$GLOBALS['blocked']=false;$GLOBALS['contact']=true;$GLOBALS['owner']=17;$GLOBALS['audit']=true;$GLOBALS['audits']=0;
function get_option($k,$d=''){return $GLOBALS['schema'];}
function get_current_user_id(){return $GLOBALS['actor'];}
function wp_get_session_token(){return $GLOBALS['session'];}
function smc_privacy_erasure_lock($u){return $GLOBALS['privacy'];}
function get_userdata($u){return new WP_User();}
function get_user_by($field,$email){$u=new WP_User();$u->ID=$GLOBALS['owner'];return $u;}
function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL)!==false;}
class SMC_Completion {static $safe=false;static function safe_mode(){return self::$safe;}}
class SMC_Authorization {static function is_hard_blocked($u){return $GLOBALS['blocked'];}}
class SMC_Contracts {
 static $claims=['eligible'=>true,'approved'=>true,'suspended'=>false,'identity_documents_current'=>true,'guardian_verified'=>true,'phone_verified'=>true,'institutional_account'=>false];
 static function assertions($u){return self::$claims;}
 static function contact_verified($u,$c){return $GLOBALS['contact'];}
}
class SMC_Advanced_Trust_2026 {
 static $auth=[],$step=['satisfied'=>true,'risk_context'=>['high_risk'=>false]];
 static function authentication_assurance($u){return self::$auth;}
 static function step_up_requirement($u,$a){return self::$step;}
}
class SMC_Security {static function audit($a,$u,$d){++$GLOBALS['audits'];$GLOBALS['audit_fields']=$d;return $GLOBALS['audit'];}}
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php';
$auth=['owner'=>'file02','contract_version'=>'1.0.0','method'=>'webauthn_passkey','passkey_asserted'=>true,'level'=>2,'verified_at'=>time()-5];
SMC_Advanced_Trust_2026::$auth=$auth;
$p=['identifier'=>'member@example.test','reason'=>'email_collision'];
$c=['contract_version'=>'1.0.0','case'=>'11111111-1111-4111-8111-111111111111','fingerprint'=>str_repeat('a',64)];
$n=0;
function need($v,$label){global $n;if(!$v){fwrite(STDERR,"FAIL ".$label.PHP_EOL);exit(1);}++$n;}
function resolve($p,$c,$commit=false){return SMC_Authentication_Contract_V11::resolve_existing_email_collision_v1($p,$c,$commit);}
need(resolve($p,$c)===['result'=>'allow','user_id'=>17,'scope'=>'same_account_no_merge'],'same authenticated eligible account recognized');
need($GLOBALS['audits']===0,'preflight read-only');
foreach(['actor'=>0,'session'=>'','schema'=>'old','privacy'=>true,'blocked'=>true,'contact'=>false,'owner'=>18] as $field=>$bad){$before=$GLOBALS[$field];$GLOBALS[$field]=$bad;need(resolve($p,$c)['result']==='deny',$field.' denied');$GLOBALS[$field]=$before;}
foreach(['identity_documents_current'=>false,'guardian_verified'=>false,'phone_verified'=>false,'eligible'=>false,'approved'=>false,'suspended'=>true,'institutional_account'=>true] as $field=>$bad){$before=SMC_Contracts::$claims[$field];SMC_Contracts::$claims[$field]=$bad;need(resolve($p,$c)['result']==='deny',$field.' denied');SMC_Contracts::$claims[$field]=$before;}
foreach(['method'=>'password','owner'=>'file00','contract_version'=>'old','level'=>1,'passkey_asserted'=>false,'verified_at'=>time()-301] as $field=>$bad){SMC_Advanced_Trust_2026::$auth=array_replace($auth,[$field=>$bad]);need(resolve($p,$c)['result']==='deny',$field.' denied');}
SMC_Advanced_Trust_2026::$auth=$auth;
SMC_Advanced_Trust_2026::$step['risk_context']['high_risk']=true;need(resolve($p,$c)['result']==='deny','high risk denied');SMC_Advanced_Trust_2026::$step['risk_context']['high_risk']=false;
need(resolve(array_replace($p,['identifier'=>'other@example.test']),$c)['result']==='deny','foreign email denied');
need(resolve(array_replace($p,['reason'=>'identity_collision']),$c)['result']==='deny','identity collision not auto-resolved');
need(resolve($p,array_replace($c,['case'=>'invalid']))['result']==='deny','invalid case denied');
SMC_Completion::$safe=true;need(resolve($p,$c)['result']==='deny','safe mode denied');SMC_Completion::$safe=false;
$GLOBALS['audit']=false;need(resolve($p,$c,true)['result']==='unknown','audit failure denied');
$GLOBALS['audit']=true;need(resolve($p,$c,true)['result']==='allow','verified synthetic commit');
need($GLOBALS['audit_fields']===['case_id'=>$c['case'],'scope'=>'same_account_no_merge'],'audit contains no identifier or fingerprint');
echo "PASS ".$n." same-account owner decision assertions (mock only)".PHP_EOL;

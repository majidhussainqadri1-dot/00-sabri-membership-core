<?php
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/'); define('MINUTE_IN_SECONDS',60); define('SMC_CONTRACT_VERSION','1.2.0');
$claim_filter=null;
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function smc_is_professional_type($t){return 'doctor'===sanitize_key($t);} function apply_filters($tag,$value,...$args){global $claim_filter; if($tag==='smc_file09_doctor_verification_claim_v1' && is_callable($claim_filter)) return $claim_filter($value,...$args); return $value;}
class SPD_Helpers{public static $status='verified';public static function verification_status($id){return self::$status;}}
require __DIR__.'/../source/sabri-membership-core/includes/class-smc-contracts.php';
$T=[];function t($n,$ok){global $T;$T[]=[$n,(bool)$ok];}
$claim_filter=fn($v,$id)=>['status'=>'verified']; t('malformed explicit File09 claim fails closed',!SMC_Contracts::professional_verified(7,'doctor'));
$claim_filter=fn($v,$id)=>['owner'=>'file09','contract_version'=>'1.0.0','status'=>'verified','current'=>true,'asserted_at'=>time()-600,'expires_at'=>time()+3600]; t('stale File09 assertion rejected',!SMC_Contracts::professional_verified(7,'doctor'));
$claim_filter=fn($v,$id)=>['owner'=>'file09','contract_version'=>'1.0.0','status'=>'verified','current'=>true,'asserted_at'=>time(),'expires_at'=>time()+3600]; t('fresh typed File09 claim accepted',SMC_Contracts::professional_verified(7,'doctor'));
$claim_filter=null; SPD_Helpers::$status='verified'; t('canonical compatibility adapter remains supported',SMC_Contracts::professional_verified(7,'doctor'));
$f=0;foreach($T as [$n,$ok]){echo ($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$f++;}echo 'File09 claim runtime: '.(count($T)-$f).' PASS / '.$f." FAIL\n";exit($f?1:0);

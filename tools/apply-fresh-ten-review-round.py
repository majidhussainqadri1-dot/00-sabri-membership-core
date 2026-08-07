#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
contracts=root/'source/sabri-membership-core/includes/class-smc-contracts.php'
test=root/'qa/file09-professional-claim-runtime.php'
s=contracts.read_text()
old="""\t\tif ( 'doctor' === $type ) {\n\t\t\t/* File 09 is canonical. A versioned adapter may provide an explicit current claim. */\n\t\t\t$claim = apply_filters( 'smc_file09_doctor_verification_claim_v1', null, absint( $user_id ) );\n\t\t\tif ( is_array( $claim ) ) {\n\t\t\t\t$status  = sanitize_key( $claim['status'] ?? '' );\n\t\t\t\t$current = ! array_key_exists( 'current', $claim ) || ! empty( $claim['current'] );\n\t\t\t\treturn $current && in_array( $status, array( 'verified', 'active' ), true );\n\t\t\t}\n\t\t\t/* SPD_Helpers is the installed canonical File 09 compatibility adapter. */\n"""
new="""\t\tif ( 'doctor' === $type ) {\n\t\t\t/* File 09 is canonical. Explicit claims must be typed, current and freshly asserted. */\n\t\t\t$claim = apply_filters( 'smc_file09_doctor_verification_claim_v1', null, absint( $user_id ) );\n\t\t\tif ( is_array( $claim ) ) {\n\t\t\t\t$status = sanitize_key( $claim['status'] ?? '' );\n\t\t\t\t$owner = sanitize_key( $claim['owner'] ?? '' );\n\t\t\t\t$contract = (string) ( $claim['contract_version'] ?? '' );\n\t\t\t\t$asserted_at = absint( $claim['asserted_at'] ?? 0 );\n\t\t\t\t$expires_at = absint( $claim['expires_at'] ?? 0 );\n\t\t\t\t$fresh = $asserted_at > 0 && $asserted_at <= time() + 60 && $asserted_at >= time() - 5 * MINUTE_IN_SECONDS;\n\t\t\t\treturn 'file09' === $owner\n\t\t\t\t\t&& '1.0.0' === $contract\n\t\t\t\t\t&& array_key_exists( 'current', $claim ) && ! empty( $claim['current'] )\n\t\t\t\t\t&& $fresh\n\t\t\t\t\t&& ( 0 === $expires_at || $expires_at > time() )\n\t\t\t\t\t&& in_array( $status, array( 'verified', 'active' ), true );\n\t\t\t}\n\t\t\t/* SPD_Helpers is the installed canonical File 09 compatibility adapter. */\n"""
if old not in s: raise SystemExit('round6 File09 block not found')
s=s.replace(old,new,1); contracts.write_text(s)
test.write_text(r'''<?php
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
''')

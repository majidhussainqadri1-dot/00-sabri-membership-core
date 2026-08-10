<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.35');
$GLOBALS['options'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['users'] = [7 => (object)['roles'=>['sabri_member']]];
$GLOBALS['actions'] = [];
function add_filter($a,$b,$c=10,$d=1){} function add_action($a,$b,$c=10,$d=1){}
function apply_filters($tag,$value,...$args){
  /* Simulate a lower-trust extension attempting to delete the mandatory invalidation baseline. */
  if($tag==='smc_projection_invalidation_audit_actions') return [];
  return $value;
}
function do_action($tag,...$args){$GLOBALS['actions'][]=[$tag,$args];}
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function get_userdata($id){return $GLOBALS['users'][(int)$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][(int)$id][$key]??'';}
function smc_privacy_erasure_lock($id){return false;}
function smc_application($id){return ['row_version'=>9];}
class SMC_CF01_Contract { public static function ensure_subject_uuid($id){return $id===7?'123e4567-e89b-42d3-a456-426614174000':'';} }
class SMC_Contracts { public static function assertions($id){return [
  'approved'=>true,'eligible'=>true,'suspended'=>false,'public_profile_allowed'=>true,
  'status'=>'approved','account_class'=>'member','approved_membership_types'=>['member'],
  'professional_verified'=>true,'identity_documents_current'=>true,
  'mfa_required'=>false,'mfa_owner'=>'none','two_factor_ready'=>false,'session_two_factor'=>false,
];}}
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-latest-central-2026.php';
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
$c=smc_latest_central_constitution();
expect($c['single_free_tier']===true && $c['paid_unlocks_enabled']===false,'single-free constitution');
expect($c['commission_percent']===0 && $c['donation_affects_rank']===false && $c['donation_affects_support']===false,'donor-neutral constitution');
expect($c['brand_primary']==='#087A4E' && $c['search_discovery_owner']==='file26','green and File 26 ownership');
expect($c['mfa_owner']==='none' && $c['file00_mfa_required']===false && $c['authentication_owner']==='file02','constitution records File 00 MFA retirement and File 02 authentication ownership');
$p=smc_file26_membership_projection(7);
expect($p['indexable']===true && $p['search_visibility']==='public','eligible public projection is indexable');
expect($p['platform_uuid']==='123e4567-e89b-42d3-a456-426614174000' && !array_key_exists('user_id',$p),'File 26 projection exposes opaque platform UUID, not WordPress ID');
expect($p['donation_rank_signal']===false && $p['paid_rank_signal']===false,'projection cannot encode paid/donor boost');
expect(!isset($p['phone']) && !isset($p['date_of_birth']) && !isset($p['address']),'projection is privacy-minimal');
$guard=SMC_Latest_Central_2026::audit_record_guard(true,'guardian_consent_withdrawn',7,[],44);
expect($guard===true,'mandatory projection invalidation guard succeeds without a retired MFA marker');
expect(count(array_filter($GLOBALS['actions'],fn($x)=>$x[0]==='smc_file26_projection_invalidated'))===1,'security-state change invalidates File 26 projection');
expect(!array_key_exists('_smc_revalidation_required_at',$GLOBALS['meta'][7]??[]),'latest-central layer writes no retired File 00 MFA revalidation marker');
$guard2=SMC_Latest_Central_2026::audit_record_guard(true,'unrelated_event',7,[],45);
expect($guard2===true && count(array_filter($GLOBALS['actions'],fn($x)=>$x[0]==='smc_file26_projection_invalidated'))===1,'unrelated event creates neither projection invalidation nor MFA state');
echo "Latest-central runtime: 12 PASS, 0 FAIL\n";

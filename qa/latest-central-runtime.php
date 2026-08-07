<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.12');
$GLOBALS['options'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['users'] = [7 => (object)['roles'=>['sabri_member']]];
$GLOBALS['actions'] = [];
function add_filter($a,$b,$c=10,$d=1){} function add_action($a,$b,$c=10,$d=1){}
function apply_filters($tag,$value,...$args){return $value;}
function do_action($tag,...$args){$GLOBALS['actions'][]=[$tag,$args];}
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function get_userdata($id){return $GLOBALS['users'][(int)$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][(int)$id][$key]??'';}
function update_user_meta($id,$key,$value){$GLOBALS['meta'][(int)$id][$key]=$value;return true;}
function clean_user_cache($id){}
function smc_privacy_erasure_lock($id){return false;}
function smc_application($id){return ['row_version'=>9];}
class SMC_Contracts { public static function assertions($id){return [
  'approved'=>true,'eligible'=>true,'suspended'=>false,'public_profile_allowed'=>true,
  'status'=>'approved','account_class'=>'member','approved_membership_types'=>['member'],
  'professional_verified'=>true,'identity_documents_current'=>true,
];}}
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-latest-central-2026.php';
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
$c=SMC_Latest_Central_2026::constitution();
expect($c['single_free_tier']===true && $c['paid_unlocks_enabled']===false,'single-free constitution');
expect($c['commission_percent']===0 && $c['donation_affects_rank']===false && $c['donation_affects_support']===false,'donor-neutral constitution');
expect($c['brand_primary']==='#087A4E' && $c['search_discovery_owner']==='file26','green and File 26 ownership');
$p=SMC_Latest_Central_2026::file26_projection(7);
expect($p['indexable']===true && $p['search_visibility']==='public','eligible public projection is indexable');
expect($p['donation_rank_signal']===false && $p['paid_rank_signal']===false,'projection cannot encode paid/donor boost');
expect(!isset($p['phone']) && !isset($p['date_of_birth']) && !isset($p['address']),'projection is privacy-minimal');
SMC_Latest_Central_2026::audit_recorded('guardian_consent_withdrawn',7,[],44);
expect((int)$GLOBALS['meta'][7][SMC_Latest_Central_2026::REVALIDATION_META] > 0,'security-state change advances revalidation marker');
expect(count(array_filter($GLOBALS['actions'],fn($x)=>$x[0]==='smc_file26_projection_invalidated'))===1,'security-state change invalidates File 26 projection');
echo "Latest-central runtime: 8 PASS, 0 FAIL\n";

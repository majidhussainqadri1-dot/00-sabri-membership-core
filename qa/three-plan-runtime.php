<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.12');
define('SMC_CONTRACT_VERSION', '1.2.0');
define('SMC_CF01_CONTRACT_VERSION', '1.0.0');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['options'] = [
    'smc_founder_user_id' => 1,
    'smc_institutional_ai_user_id' => 3,
    'smc_institutional_ai_activated_at' => gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS),
    'smc_institutional_ai_low_risk_auto_publish' => false,
];
$GLOBALS['users'] = [
    1 => (object)['roles'=>['administrator']],
    2 => (object)['roles'=>['sabri_doctor_verified']],
    3 => (object)['roles'=>['sabri_institutional_ai_teacher','sabri_institutional_ai_publisher']],
    4 => (object)['roles'=>['administrator']],
];
function __($v,$d=null){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);} function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;} function get_userdata($id){return $GLOBALS['users'][(int)$id]??false;}
function user_can($user,$cap){return $cap==='manage_options' && in_array('administrator',(array)$user->roles,true);} function apply_filters($tag,$value,...$args){return $value;}
function get_user_meta($id,$key,$single=true){return '';} function current_time($type,$gmt=false){return gmdate('Y-m-d H:i:s');}
function wp_timezone(){return new DateTimeZone('UTC');} function is_wp_error($v){return false;}
class SMC_Security { public static function two_factor_ready($id){return true;} public static function session_is_verified($id){return true;} public static function decrypt($v,$p,$c=[]){return $v;} public static function blind_index($v,$p){return hash('sha256',$v);} }
class FakeWpdb { public string $prefix='wp_'; public function prepare($q,...$a){return $q;} public function get_var($q){return 1;} }
$GLOBALS['wpdb']=new FakeWpdb();
require dirname(__DIR__) . '/source/sabri-membership-core/includes/functions.php';
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-contracts.php';
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} echo "PASS: $label\n";}
$p=smc_policy();
expect($p['free_baseline']===true && $p['paid_unlocks_enabled']===false,'single free baseline is canonical');
expect($p['commission_percent']===0,'commission is zero');
expect($p['donation_affects_entitlement']===false && $p['donation_affects_capability']===false,'donation cannot buy authority');
$ai=smc_institutional_ai_policy();
expect($ai['daily_post_limit']===4 && $ai['doctor_verification']===false,'AI policy is disclosed and not a doctor');
expect($ai['patient_specific_clinical_authority']===false,'AI has no clinical authority');
$base=['eligible'=>true,'session_two_factor'=>true,'suspended'=>false,'approved_membership_types'=>[],'professional_verified'=>true];
$aip=SMC_Contracts::publishing_assertions(3,$base);
expect($aip['authority_class']==='institutional_ai_publisher','AI publisher authority class is explicit');
expect($aip['can_direct_publish']===false && $aip['requires_human_review']===true,'AI direct publication stays closed without explicit policy');
$admin=SMC_Contracts::publishing_assertions(4,$base);
expect($admin['can_direct_publish']===true && $admin['authority_class']==='administrator','authorized administrator can direct publish');
$doctorBase=$base; $doctorBase['approved_membership_types']=['doctor'];
$doctor=SMC_Contracts::publishing_assertions(2,$doctorBase);
expect($doctor['can_submit_for_review']===true && $doctor['can_direct_publish']===false,'verified doctor follows policy, not automatic direct publish');
$transfer=SMC_Contracts::transfer_assertions(2,0,[],$doctorBase);
expect($transfer['max_file_bytes']===1073741824 && $transfer['public_url_allowed']===false,'transfer contract enforces 1 GB and no public URL');
$blocked=SMC_Contracts::transfer_assertions(2,3,[],$doctorBase);
expect($blocked['can_initiate']===false,'recipient transfer fails closed without relationship and consent');
$ent=SMC_Contracts::entitlement_assertions(2,$doctorBase);
expect($ent['base_services']['ai']===true && $ent['base_services']['marketplace']===true,'AI and marketplace are in free baseline');
echo "Three-plan runtime: 12 PASS, 0 FAIL\n";

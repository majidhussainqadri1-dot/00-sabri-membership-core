<?php
/**
 * Disposable integration only: actual File00, File02, File06 and File24.
 * No WebAuthn ceremony is claimed; a synthetic post-verification receipt is
 * created via the real File02 internal implementation, then session-bound.
 * This file is NOT part of the installable plugin.
 */
defined('ABSPATH') || exit;
$guard=[
    'wp_cli'=>defined('WP_CLI') && (bool) WP_CLI,
    'environment'=>wp_get_environment_type()==='staging',
    'hostname'=>wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST)==='file06-consent-ci.local',
    'explicit_opt_in'=>getenv('SABRI_DISPOSABLE_CONSENT_QA')==='1',
    'local_disposable_db'=>defined('DB_NAME') && DB_NAME==='wordpress' && defined('DB_HOST') && DB_HOST==='127.0.0.1:3306',
    'disposable_root'=>realpath(ABSPATH)==='/tmp/wordpress',
];
if(in_array(false,$guard,true)){throw new RuntimeException('Refuse non-disposable test: '.wp_json_encode($guard));}
echo 'PASS: all six disposable-environment guards'.PHP_EOL;
global $wpdb;
function four_check($ok,$label) {
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label.PHP_EOL;
}
four_check(defined('SMC_VERSION') && defined('SAUTH_VERSION') && defined('SPCRC_VERSION') && class_exists('HE_V12_Case_Consent_Owner'),'four actual owners booted');
four_check(SMC_DB_VERSION===(string)get_option('smc_db_version')&&!SMC_Completion::safe_mode(),'File00 ready');
four_check(has_filter('smc_file02_authentication_assurance_v1',array('SAUTH_Passkey_Runtime','assurance_filter'))!==false,'real File02 assurance hook');
four_check(apply_filters('spcrc/file00_contract_state','missing',[])==='compatible','File24 actual File00 contract compatible');
$user=get_user_by('login','file06_consent_verified_ci');
four_check($user instanceof WP_User,'existing disposable adult fixture');
$uid=(int)$user->ID;
$wpdb->update($wpdb->prefix.'smc_applications',['status'=>'approved'],['user_id'=>$uid]);
wp_set_current_user($uid);
four_check(!empty(SMC_Contracts::assertions($uid)['eligible']),'real adult eligibility current');
$uuid='66666666-6666-4666-8666-666666666666';
$post=wp_insert_post(['post_title'=>'Synthetic private case for CI','post_status'=>'private','post_author'=>$uid],true);
four_check(is_int($post)&&$post>0,'disposable case post');
$row=['public_id'=>$uuid,'post_id'=>$post,'record_type'=>'successful-case','status'=>'draft','title'=>'Synthetic case','question'=>'fixture','protocol'=>'fixture','created_by'=>$uid,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)];
foreach(['investigators_json','ethics_json','consent_json','conflicts_json','case_json','metadata_json'] as $field){$row[$field]='{}';}
four_check(1===$wpdb->insert(HE_V2_Schema::table('research'),$row),'actual File06 canonical research table');
$text='Disposable explicit case consent only. Not real patient evidence.';
$policy=['approved'=>true,'version'=>'four-owner-v1','locale'=>'ur-PK','text'=>$text,'text_hash'=>hash('sha256',$text),'approved_by'=>1,'approved_at_utc'=>gmdate('c')];
update_option(HE_V12_Case_Consent_Owner::POLICY_OPTION,$policy,false);
$relkey=HE_V12_Case_Consent_Owner::RELATION_PREFIX.hash('sha256',$uuid);
update_option($relkey,['research_public_id'=>$uuid,'subject_user_id'=>$uid,'relation_version'=>1,'attested_by'=>10001,'approved_by'=>10002,'approved'=>true],false);
$req=static function($data,$withdraw=false){
    $r=new WP_REST_Request('POST','/smc/v1/file06/case-consent'.($withdraw?'/withdraw':''));
    $r->set_header('Content-Type','application/json');
    $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    $r->set_body(wp_json_encode($data));return $r;
};
$payload=['research_public_id'=>$uuid,'accepted'=>true,'policy_version'=>'four-owner-v1','locale'=>'ur-PK','text_hash'=>$policy['text_hash']];
four_check(is_wp_error(SMC_File06_Case_Consent::accept($req($payload))),'no File02 fresh receipt means no new consent');
$expiry=time()+600;
$token=WP_Session_Tokens::get_instance($uid)->create($expiry);
$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($uid,$expiry,'logged_in',$token);
$method=new ReflectionMethod('SAUTH_Passkey_Runtime','store_pending_assurance');
$method->setAccessible(true);
four_check(''!==$method->invoke(null,$uid,false),'synthetic post-ceremony receipt stored by real File02 code');
SAUTH_Passkey_Runtime::bind_pending_assurance_to_session('',0,$expiry,$uid,'logged_in',$token);
$assurance=SMC_Advanced_Trust_2026::authentication_assurance($uid);
four_check(($assurance['owner']??'')==='file02'&&($assurance['level']??0)>=3,'actual File02 session receipt reaches File00');
$result=SMC_File06_Case_Consent::accept($req($payload));
$data=is_wp_error($result)?[]:$result->get_data();
four_check(($data['state']??'')==='accepted','native File00 accepts bound case from actual File06 owner');
$ref=$data['evidence_ref'];$verification=$data['verification_id'];
$att=HE_V12_Completion::consent_attestation(['research_public_id'=>$uuid,'consent_version'=>'four-owner-v1','consent_locale'=>'ur-PK','consent_evidence_ref'=>$ref],1,true,'successful-case');
four_check(is_array($att)&&($att['verified_by']??'')==='file-00','actual File06 consumes durable receipt');
unset($_COOKIE[LOGGED_IN_COOKIE]);wp_set_current_user(0);
four_check(SMC_File06_Case_Consent::current_for_file06(false,$verification,$ref,$uuid,'patient-story-research')===true,'native current receipt survives logout');
$privacy=apply_filters('spcrc/privacy_request/file-00-membership-core',null,'access',['requester_user_id'=>$uid]);
four_check(is_array($privacy)&&($privacy['status']??'')==='pending','File24 identifies registered native exporter without executing');
$erase=apply_filters('spcrc/privacy_request/file-00-membership-core',null,'deletion',['requester_user_id'=>$uid]);
four_check(is_array($erase)&&($erase['status']??'')==='pending','File24 identifies registered native eraser without executing');
wp_set_current_user($uid);
$withdraw=SMC_File06_Case_Consent::withdraw($req(['research_public_id'=>$uuid,'evidence_ref'=>$ref],true));
four_check(!is_wp_error($withdraw)&&($withdraw->get_data()['state']??'')==='withdrawn','actual DB consent withdrawn');
wp_set_current_user(0);
four_check(SMC_File06_Case_Consent::current_for_file06(false,$verification,$ref,$uuid,'patient-story-research')===false,'withdrawn receipt no longer accepted');
update_option('smc_ci_revoked_case_proof',['uuid'=>$uuid,'verification_id'=>$verification,'evidence_ref'=>$ref],false);
echo "PASS four-owner disposable integration; synthetic post-verification receipt, not a hardware ceremony or human consent".PHP_EOL;

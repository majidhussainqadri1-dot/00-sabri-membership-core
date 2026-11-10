<?php
/**
 * File00 PR56 + File06 PR14 actual source class contract in disposable WordPress/MariaDB.
 * File06's whole plugin/migration is intentionally NOT claimed installed by this fixture.
 * File06 research table and independent attestation are seeded, NOT patient evidence.
 */
defined( 'ABSPATH' ) || exit;
global $wpdb;
$passed=0; $failures=array();
$check=static function($ok,$label) use (&$passed,&$failures) {
    if($ok){$passed++;echo "PASS File00↔File06 PR14 real WP: {$label}\n";}
    else {$failures[]=$label;fwrite(STDERR,"FAIL File00↔File06 PR14 real WP: {$label}\n");}
};
$root=getenv('FILE06_PR14_SOURCE_ROOT');
$owner=$root . '/homeopathy-encyclopedia/includes/class-he-v12-case-consent-owner.php';
$completion=$root . '/homeopathy-encyclopedia/includes/class-he-v12-completion.php';
if(!$root || !is_file($owner) || !is_file($completion) || !class_exists('SMC_File06_Case_Consent')){
    fwrite(STDERR,"Pinned PR14 source or real File00 provider unavailable\n");
    exit(2);
}
if(!class_exists('HE_V2_Schema')){
    class HE_V2_Schema {
        const OPTION_SAFE_MODE='file06_pr14_test_safe_mode';
        public static function runtime_status(){return 'active';}
        public static function table($name){global $wpdb;return $wpdb->prefix.'pr14_fixture_'.$name;}
    }
}
if(!class_exists('HE_V2_Auth')){
    class HE_V2_Auth {
        public static function membership_allowed($id=0){
            $id=$id?:get_current_user_id();
            $a=SMC_Contracts::assertions($id);
            return !empty($a['eligible']) && empty($a['suspended']);
        }
    }
}
require_once $owner;
require_once $completion;
HE_V12_Case_Consent_Owner::hooks();

$user=get_user_by('login','file06_consent_verified_ci');
if(!$user || !smc_application($user->ID)){fwrite(STDERR,"Previous real File00 subject fixture unavailable\n");exit(2);}
$uid=(int)$user->ID;
$wpdb->update($wpdb->prefix.'smc_applications', array('status'=>'approved'), array('user_id'=>$uid));
$authentication=static function($base,$id) use($uid){
    if((int)$id!==$uid)return $base;
    return array('contract_version'=>'1.0.0','owner'=>'file02','level'=>2,
        'method'=>'pr14-ci-session','verified_at'=>time(),
        'passkey_asserted'=>false,'hardware_backed'=>false);
};
add_filter('smc_file02_authentication_assurance_v1',$authentication,10,2);
wp_set_current_user($uid);
$claims=SMC_Contracts::assertions($uid);
$check(!empty($claims['eligible']), 'actual File00 membership is eligible for a synthetic approved adult');

$table=HE_V2_Schema::table('research');
$create="CREATE TABLE IF NOT EXISTS {$table} (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 post_id bigint unsigned NOT NULL,
 public_id char(36) NOT NULL,
 record_type varchar(40) NOT NULL,
 status varchar(40) NOT NULL,
 row_version bigint unsigned NOT NULL,
 created_by bigint unsigned NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY public_id (public_id)
) ENGINE=InnoDB";
$check(false!==$wpdb->query($create), 'disposable File06 canonical case fixture SQL table created');
$uuid='44444444-4444-4444-8444-444444444444';
$post=wp_insert_post(array('post_title'=>'PR14 internal study case fixture',
    'post_status'=>'private','post_type'=>'post','post_author'=>$uid),true);
if(is_wp_error($post)){fwrite(STDERR,"Unable to seed test-only WordPress case post\n");exit(2);}
$inserted=$wpdb->insert($table,array('post_id'=>(int)$post,'public_id'=>$uuid,
    'record_type'=>'successful-case','status'=>'draft','row_version'=>1,'created_by'=>$uid));
$check(1===(int)$inserted, 'real SQL case record bound to immutable UUID');
$policyText=str_repeat('An adult patient may withdraw use of this one case without removing ordinary membership. ',3);
$policy=array('approved'=>true,'version'=>'pr14-policy-v1','locale'=>'ur-PK',
    'text'=>$policyText,'text_hash'=>hash('sha256',$policyText),
    'approved_by'=>5,'approved_at_utc'=>gmdate('c'),
    'approval_reference'=>'test-founder-approval-2026');
update_option(HE_V12_Case_Consent_Owner::POLICY_OPTION,$policy,false);
$relationKey=HE_V12_Case_Consent_Owner::RELATION_PREFIX.hash('sha256',$uuid);
$binding=array('research_public_id'=>$uuid,'subject_user_id'=>$uid,
    'relation_version'=>1,'attested_by'=>5,'approved_by'=>6,
    'approved'=>true,'clinical_match_reference'=>'test-independently-reviewed-subject',
    'attested_at_utc'=>gmdate('c'),'approved_at_utc'=>gmdate('c'));
update_option($relationKey,$binding,false);
$actualPolicy=apply_filters('he_v12_file06_consent_policy_v1',null,'ur-PK');
$actualRelation=apply_filters('he_v12_file06_patient_relation_v1',null,$uid,$uuid);
$check(is_array($actualPolicy)&&$actualPolicy['text_hash']===$policy['text_hash'],
    'real PR14 File06 class supplies approved bounded policy');
$check(is_array($actualRelation)&&($actualRelation['verified']??false)===true
    &&$actualRelation['research_public_id']===$uuid
    &&$actualRelation['subject_user_id']===$uid,
    'real PR14 File06 class supplies case-bound independent subject relation');
$request=static function($id,$textHash,$version='pr14-policy-v1'){
    $r=new WP_REST_Request('POST','/smc/v1/file06/case-consent');
    $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    $r->set_header('Content-Type','application/json');
    $r->set_body(wp_json_encode(array('research_public_id'=>$id,
        'accepted'=>true,'policy_version'=>$version,'locale'=>'ur-PK',
        'text_hash'=>$textHash)));
    return $r;
};
$accepted=SMC_File06_Case_Consent::accept($request($uuid,$policy['text_hash']));
$reply=is_wp_error($accepted)?array():$accepted->get_data();
$ref=(string)($reply['evidence_ref']??'');
$check(($reply['state']??'')==='accepted' && strpos($ref,'smc-f06-r')===0,
    'File00 accepts canonical PR14 source relation and approved policy to real consent ledger');
$attestation=HE_V12_Completion::consent_attestation(array(
    'research_public_id'=>$uuid,'consent_version'=>'pr14-policy-v1',
    'consent_locale'=>'ur-PK','consent_evidence_ref'=>$ref
),71,true,'successful-case');
$check(is_array($attestation)&&($attestation['verified_by']??'')==='file-00'
    &&($attestation['research_public_id']??'')===$uuid
    &&!empty($attestation['verification_id']),
    'real PR14 File06 completion transmits exact case UUID and receives verified File00 receipt');
$check(is_array($attestation)&&SMC_File06_Case_Consent::current_for_file06(
    false,$attestation['verification_id'],$ref,$uuid,'patient-story-research')===true,
    'real File00 read-time verification preserves authorized PR14 case');
$wrongUuid=HE_V12_Completion::consent_attestation(array(
    'research_public_id'=>'55555555-5555-4555-8555-555555555555',
    'consent_version'=>'pr14-policy-v1','consent_locale'=>'ur-PK',
    'consent_evidence_ref'=>$ref
),71,true,'successful-case');
$check(is_wp_error($wrongUuid),'wrong study UUID cannot inherit a valid receipt');
$binding['approved']=false;
update_option($relationKey,$binding,false);
$check(SMC_File06_Case_Consent::current_for_file06(
    false,$attestation['verification_id'],$ref,$uuid,'patient-story-research')===false,
    'independent subject relation withdrawal invalidates File00 receipt at read time');
$binding['approved']=true;
update_option($relationKey,$binding,false);
$policy['approved']=false;
update_option(HE_V12_Case_Consent_Owner::POLICY_OPTION,$policy,false);
$check(SMC_File06_Case_Consent::current_for_file06(
    false,$attestation['verification_id'],$ref,$uuid,'patient-story-research')===false,
    'approved policy withdrawal invalidates File00 receipt at read time');
$policy['approved']=true;
update_option(HE_V12_Case_Consent_Owner::POLICY_OPTION,$policy,false);
$wpdb->update($wpdb->prefix.'smc_applications',array('status'=>'suspended'),array('user_id'=>$uid));
$check(SMC_File06_Case_Consent::current_for_file06(
    false,$attestation['verification_id'],$ref,$uuid,'patient-story-research')===false,
    'File00 suspension denies PR14 producer case read despite still-accepted ledger row');
wp_set_current_user(0);
remove_filter('smc_file02_authentication_assurance_v1',$authentication,10);
if($failures){fwrite(STDERR,"File00/File06 PR14: {$passed} PASS / ".count($failures)." FAIL\n");exit(1);}
echo "File00/File06 PR14 real-source+WPDB: {$passed} PASS / 0 FAIL\n";

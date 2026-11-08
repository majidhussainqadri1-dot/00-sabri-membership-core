<?php
/** Isolated File 00 v12 subject-bound patient consent provider (not live WP). */
declare(strict_types=1);
define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A');
class WP_Error { private $code;public function __construct($c,$m='',$x=array()){$this->code=$c;}public function get_error_code(){return $this->code;}}
class SMC_Contracts{public static function assertions($uid){return array('eligible'=>!empty($GLOBALS['member_eligible']),'suspended'=>false,'institutional_account'=>false);}}
class SMC_Advanced_Trust_2026{public static function assurance_profile($uid){return array('identity_assurance_level'=>3,'authentication_assurance_level'=>1);}}
class SMC_Authorization{public static function is_hard_blocked($uid){return false;}}
class SMC_Security{public static function decrypt($value,$purpose,$context){return '1990-01-01';}}
function smc_application($user_id){return array('date_of_birth_enc'=>'encrypted');}
function smc_age_from_dob($v){return $GLOBALS['age'];}
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim((string)$v);}
function wp_salt($key){return 'ci-salt';}
function is_wp_error($v){return $v instanceof WP_Error;}
function apply_filters($name,$default,...$params){
	if($name==='he_v12_file06_patient_relation_v1'){
		[$uid,$uuid]=$params;return $GLOBALS['relation_valid']?array(
		'verified'=>true,'owner_file'=>'file-06','subject_user_id'=>$uid,'research_public_id'=>$uuid,
		'relation_version'=>2,'attested_by'=>5,'approved_by'=>6):null;
	}
	if($name==='he_v12_file06_consent_policy_v1'){
		return $GLOBALS['policy_valid']?array('approved'=>true,'owner_file'=>'file-06','purpose'=>'patient-story-research',
			'version'=>'case-policy-v1','locale'=>$params[0],'text'=>str_repeat('case consent terms ',12),
			'text_hash'=>hash('sha256',str_repeat('case consent terms ',12))):null;
	}
	return $default;
}
class File00DB {
	public $prefix='wp_';public $last_error='';public $row;
	public function prepare($query,...$params){return $params;}
	public function get_row($query,$format=null){return $this->row;}
}
$wpdb=new File00DB();
function must($ok,$m){$GLOBALS['count']++;if(!$ok){fwrite(STDERR,"FAILED {$m}\n");exit(1);}}
$GLOBALS['count']=0;$GLOBALS['member_eligible']=true;$GLOBALS['relation_valid']=true;$GLOBALS['policy_valid']=true;$GLOBALS['age']=36;
$uuid='11111111-1111-4111-8111-111111111111';
$wpdb->row=array('id'=>7,'user_id'=>17,'actor_type'=>'self',
	'actor_reference_hash'=>hash_hmac('sha256','file06-case-v1|17|'.$uuid,'ci-salt'),
	'purpose'=>'patient-story-research','locale'=>'ur-PK','channel'=>'file06-case',
	'text_hash'=>hash('sha256',str_repeat('case consent terms ',12)),
	'policy_version'=>'case-policy-v1','accepted_at'=>'2026-11-08 01:00:00','withdrawn_at'=>null);
require dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-file06-case-consent.php';
$data=array('evidence_ref'=>'smc-f06-r7','research_public_id'=>$uuid,'policy_version'=>'case-policy-v1','locale'=>'ur-PK','purpose'=>'patient-story-research');
$proof=SMC_File06_Case_Consent::verify_for_file06(null,$data);
must(is_array($proof)&&$proof['authority']==='file-00','Exact current case consent receipt');
must($proof['verification_id']==='smc-file06-v1:7','Typed owner verification ID');
must(SMC_File06_Case_Consent::current_for_file06(false,'smc-file06-v1:7','smc-f06-r7',$uuid,'patient-story-research')===true,'Current receipt reverified');
$wrong=$data;$wrong['research_public_id']='22222222-2222-4222-8222-222222222222';
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$wrong),'No unrelated patient case');
$wrong=$data;$wrong['policy_version']='not-the-policy';
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$wrong),'Version mismatch forbidden');
$wrong=$data;$wrong['locale']='en-US';
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$wrong),'Locale mismatch forbidden');
$wrong=$data;$wrong['purpose']='research-dataset';
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$wrong),'Purpose mismatch forbidden');
$GLOBALS['relation_valid']=false;
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$data),'Unapproved File06 subject relation denied');
$GLOBALS['relation_valid']=true;$GLOBALS['policy_valid']=false;
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$data),'Withdrawn/unapproved policy denied');
$GLOBALS['policy_valid']=true;$GLOBALS['age']=16;
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$data),'Minor needs dedicated authorization, no inferred consent');
$GLOBALS['age']=36;$GLOBALS['member_eligible']=false;
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$data),'Suspended or ineligible identity cannot issue case consent');
$GLOBALS['member_eligible']=true;$wpdb->row['withdrawn_at']='2026-11-08 02:00:00';
must(null===SMC_File06_Case_Consent::verify_for_file06(null,$data),'Withdrawn receipt rejects publication');
must(SMC_File06_Case_Consent::current_for_file06(false,'smc-file06-v1:7','smc-f06-r7',$uuid,'patient-story-research')===false,'Revocation reflected in every current check');
echo "File00→File06 consent: {$GLOBALS['count']} isolated assertions PASS.\n";

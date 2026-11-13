<?php
/**
 * File 00 / File 09 RED-first contract regression.
 * A doctor approved for membership but awaiting professional verification
 * must be eligible ONLY to lodge a professional credential application.
 * This is not clinical authorization and does not change global membership eligibility.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__.'/');
define('SMC_VERSION', '1.2.44');
$GLOBALS['smc_intake_user_exists'] = true;
$GLOBALS['smc_intake_base'] = array();
$GLOBALS['smc_intake_app'] = array();
function absint($value) { return abs((int)$value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$value)); }
function get_userdata($id) { return $GLOBALS['smc_intake_user_exists'] && 7 === (int)$id ? (object)array('ID'=>7) : false; }
function __($value,$domain='') { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function smc_application($id) { return 7 === (int)$id ? $GLOBALS['smc_intake_app'] : false; }
function smc_age_from_dob($date) { if ('2010-01-01'===$date) return 16; if('invalid'===$date) return false; return 30; }
function apply_filters($hook,$value,...$args) { return $value; }
class WP_Error { public function __construct($code='',$msg='') {} }
class SMC_Contracts { public static function assertions($id) { return 7===(int)$id ? $GLOBALS['smc_intake_base'] : array(); } }
class SMC_CF01_Contract { public static function ensure_subject_uuid($id) {return 7===(int)$id ? '123e4567-e89b-42d3-a456-426614174000' : ''; } }
class SMC_Security { public static function decrypt($value,$purpose,$ctx=array()) { return $value; } }
class File09IntakeDb {
    public $prefix='wp_';
    public $last_error='';
    public $canonical='PK';
    public $fail=false;
    public function prepare($sql,$user_id) { return array($sql,$user_id); }
    public function get_var($query) { if($this->fail){$this->last_error='synthetic-db-error';return null;}return $this->canonical; }
}
$wpdb = new File09IntakeDb();
$plugin = dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-file09-intake-contract.php';
if (!is_file($plugin)) { fwrite(STDERR,"RED: File00 dedicated doctor-intake contract does not exist.\n");exit(1); }
require $plugin;
function prove($yes,$name) {
    global $passed;
    if(!$yes) {fwrite(STDERR,"FAIL: ".$name."\n");exit(1);}
    ++$passed; echo "PASS: ".$name."\n";
}
$passed=0;
$base=array(
    'contract_version'=>'1.2.3','user_id'=>7,'application_exists'=>true,
    'account_class'=>'member','membership_type'=>'doctor','status'=>'approved',
    'approved'=>true,'suspended'=>false,'eligible'=>false,'professional_verified'=>false,
    'requested_membership_types'=>array('doctor'),'approved_membership_types'=>array(),
    'identity_documents_current'=>true,'email_verified'=>true,'phone_verified'=>true,
    'guardian_verified'=>true,'two_factor_ready'=>false,'mfa_required'=>false,'mfa_owner'=>'none'
);
$app=array('row_version'=>7,'date_of_birth_enc'=>'1990-01-01','guardian_required'=>false);
$GLOBALS['smc_intake_base']=$base;
$GLOBALS['smc_intake_app']=$app;
$request=array('action'=>'doctor_verification_intake','purpose'=>'professional_credential_application','jurisdiction'=>'PK');
$allow=SMC_File09_Intake_Contract::assertion(7,$request);
prove(($allow['result']??'')==='allow' && ($allow['reason_code']??'')==='doctor_intake_prerequisites_satisfied','preverified approved doctor may enter only File09 intake despite File00 global eligibility=false');
prove(($allow['contract']??'')==='smc.file09.doctor-intake' && ($allow['contract_version']??'')==='1.0.0','separate exact versioned File00→File09 contract');
prove(($allow['authorization_scope']??'')==='file09_doctor_application_intake_only','authorization scope explicitly excludes ordinary membership privileges');
foreach(array('grants_clinical_authorization','grants_professional_verification','grants_patient_record_access','grants_prescription_authority','grants_public_badge','grants_appointment_authority') as $key) {
    prove(array_key_exists($key,$allow) && false === $allow[$key],$key.' cannot be granted');
}
prove(($allow['subject']['platform_uuid']??'')==='123e4567-e89b-42d3-a456-426614174000' && ($allow['subject']['record_version']??0)===7,'bounded real File00 subject UUID and positive row version');
prove(strtotime($allow['expires_at']??'')>time() && strtotime($allow['expires_at']??'')<=time()+60,'time bounded 60-second assertion');
prove(!array_key_exists('user_id',$allow) && !array_key_exists('date_of_birth',$allow) && !array_key_exists('license_number',$allow),'private File00 and credential data never included in public-safe envelope');
$no=$request; $no['action']='clinical_read';
prove((SMC_File09_Intake_Contract::assertion(7,$no)['result']??'')==='unknown','clinical actions are unavailable on intake contract');
$no=$request; $no['purpose']='patient_care';
prove((SMC_File09_Intake_Contract::assertion(7,$no)['result']??'')==='unknown','wrong purpose is not accepted');
$mutations=array(
    array('status','suspended','suspended membership denied'),
    array('approved',false,'unapproved membership denied'),
    array('identity_documents_current',false,'missing identity docs denied'),
    array('phone_verified',false,'missing phone verification denied'),
    array('email_verified',false,'missing email verification denied'),
    array('guardian_verified',false,'missing guardian verification denied'),
    array('requested_membership_types',array(),'unrequested doctor role denied')
);
foreach($mutations as $mutation) {
    $GLOBALS['smc_intake_base']=$base; $GLOBALS['smc_intake_base'][$mutation[0]]=$mutation[1];
    prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')==='deny',$mutation[2]);
}
$GLOBALS['smc_intake_base']=$base; $GLOBALS['smc_intake_base']['eligible']=true; $GLOBALS['smc_intake_base']['requested_membership_types']=array('member');
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')==='deny','global eligible true cannot grant unrequested File09 onboarding');
$GLOBALS['smc_intake_base']=$base;
$GLOBALS['smc_intake_base']['user_id']=999;
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')!=='allow','wrong actor binding fails closed');
$GLOBALS['smc_intake_base']=$base;
$GLOBALS['smc_intake_app']['row_version']=0;
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')!=='allow','invalid File00 row version fails closed');
$GLOBALS['smc_intake_app']=$app;
$GLOBALS['smc_intake_app']['date_of_birth_enc']='2010-01-01';
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')==='deny','minor professional denied');
$GLOBALS['smc_intake_app']=$app;
$no=$request;$no['jurisdiction']='US';
prove((SMC_File09_Intake_Contract::assertion(7,$no)['result']??'')==='deny','jurisdiction mismatch denied');
$wpdb->canonical='';
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')!=='allow','missing canonical jurisdiction cannot silently grant');
$wpdb->canonical='PK';$wpdb->fail=true;
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')!=='allow','database query error fails closed');
$wpdb->fail=false;$GLOBALS['smc_intake_user_exists']=false;
prove((SMC_File09_Intake_Contract::assertion(7,$request)['result']??'')!=='allow','missing principal fails closed');
echo 'File 00 → File 09 dedicated intake contract: '.$passed." PASS, 0 FAIL\n";

<?php
/** Isolated File 00 v12 subject-bound patient consent provider (not live WP). */
declare(strict_types=1);
define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A');
class WP_Error { private $code;public function __construct($c,$m='',$x=array()){$this->code=$c;}public function get_error_code(){return $this->code;}}
class SMC_Contracts{public static function assertions($uid){return array('eligible'=>!empty($GLOBALS['member_eligible']),'suspended'=>false,'institutional_account'=>false);}}
class SMC_Advanced_Trust_2026{public static function assurance_profile($uid){return array('identity_assurance_level'=>3,'authentication_assurance_level'=>1);}}
class SMC_Authorization{public static function is_hard_blocked($uid){return false;}}
class SMC_Security{public static function decrypt($value,$purpose,$context){return '1990-01-01';}public static function audit($event,$actor,$details){return true;}}
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
	public $prefix='wp_';public $last_error='';public $row;public $rows=null;public $lockAvailable=true;public $locksHeld=0;public $insert_id=0;public $failActivation=false;public $failCount=false;public $failInsert=false;public $lockErrorAfterAcquire=false;public $onAcquire=null;
	public function prepare($query,...$params){return array('sql'=>$query,'args'=>$params);}
	public function get_row($query,$format=null){
		if($this->rows===null)return $this->row;
		$sql=$query['sql'];$args=$query['args'];
		if(strpos($sql,'WHERE user_id=%d')!==false){
			$matches=array_filter($this->rows,function($row)use($args){
				return (int)$row['user_id']===(int)($args[0]??0)
					&&$row['purpose']===($args[1]??'')
					&&$row['channel']===($args[2]??'')
					&&$row['actor_type']==='self'
					&&$row['actor_reference_hash']===($args[3]??'')
					&&empty($row['withdrawn_at']);
			});
			if(!$matches)return null;
			krsort($matches,SORT_NUMERIC);
			return reset($matches);
		}
		$id=(int)($args[0]??0);
		return $this->rows[$id]??null;
	}
	public function get_var($statement){
		if(!is_array($statement))return null;
		$sql=$statement['sql'];$args=$statement['args'];
		if(strpos($sql,'GET_LOCK')!==false){
			if(!$this->lockAvailable)return 0;
			$this->locksHeld++;
			if(is_callable($this->onAcquire))call_user_func($this->onAcquire);
			if($this->lockErrorAfterAcquire)$this->last_error='simulated post-acquisition SQL error';
			return 1;
		}
		if(strpos($sql,'RELEASE_LOCK')!==false){$this->locksHeld--;return 1;}
		if(strpos($sql,'COUNT(*)')!==false){
			if($this->failCount)return null;
			$found=0;
			foreach($this->rows as $row){
				if($row['user_id']===(int)($args[0]??0)&&$row['purpose']===($args[1]??'')
					&&$row['channel']===($args[2]??'')
					&&$row['actor_reference_hash']===($args[3]??'')
					&&$row['actor_type']==='self'&&empty($row['withdrawn_at']))$found++;
			}
			return $found;
		}
		return null;
	}
	public function insert($table,$data){
		if($this->failInsert)return false;
		$id=max(array_keys($this->rows))+1;
		$this->insert_id=$id;
		$this->rows[$id]=array_merge(array('id'=>$id),$data);
		return 1;
	}
	public function query($statement){
		if(!is_array($statement))return false;
		$sql=$statement['sql'];$args=$statement['args'];$changed=0;
		if(strpos($sql,"SET actor_type='self'")!==false){
			if($this->failActivation)return 0;
			$id=(int)($args[0]??0);$uid=(int)($args[1]??0);
			if(isset($this->rows[$id])&&$this->rows[$id]['actor_type']==='pending'
				&&$this->rows[$id]['user_id']===$uid&&empty($this->rows[$id]['withdrawn_at'])){
				$this->rows[$id]['actor_type']='self';return 1;
			}
			return 0;
		}
		if(strpos($sql,"actor_type='pending'")!==false){
			$id=(int)($args[1]??0);
			if(isset($this->rows[$id])&&$this->rows[$id]['actor_type']==='pending'){
				$this->rows[$id]['withdrawn_at']=(string)($args[0]??'');return 1;
			}
			return 0;
		}
		foreach($this->rows as $id=>&$row){
			if($row['actor_type']!=='self'||!empty($row['withdrawn_at']))continue;
			if(strpos($sql,'WHERE id=%d')!==false){
				$match=$id===(int)($args[1]??0)&&$row['user_id']===(int)($args[2]??0);
			}else{
				$match=$row['user_id']===(int)($args[1]??0)
					&&$row['purpose']===($args[2]??'')
					&&$row['channel']===($args[3]??'')
					&&$row['actor_reference_hash']===($args[4]??'');
			}
			if($match){$row['withdrawn_at']=(string)$args[0];$changed++;}
		}
		unset($row);
		return $changed;
	}
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

/* A concurrent duplicate must not survive a subject's case-specific withdrawal.
 * Red regression: original implementation updates id=7 only; id=8 remains valid.
 * Tests include isolation from an unrelated case and an unrelated user.
 */
function wp_verify_nonce($nonce,$action){return 1;}
function is_user_logged_in(){return true;}
function get_current_user_id(){return 17;}
function current_time($format,$gmt=false){return '2026-11-10 01:00:00';}
function rest_ensure_response($value){return $value;}
function do_action($hook,...$args){$GLOBALS['last_action']=$hook;}
function update_option($name,$value,$autoload=false){$GLOBALS['reconciliation_option']=$value;}
class File00ConsentRequest {
	private $data;
	public function __construct($data){$this->data=$data;}
	public function get_header($name){return 'valid-nonce';}
	public function get_json_params(){return $this->data;}
}
$wpdb->row['withdrawn_at']=null;
$first=$wpdb->row;
$second=$first;$second['id']=8;
$unrelated=$first;$unrelated['id']=9;
$unrelated['actor_reference_hash']=hash_hmac('sha256','file06-case-v1|17|22222222-2222-4222-8222-222222222222','ci-salt');
$otherMember=$first;$otherMember['id']=10;$otherMember['user_id']=18;
$otherMember['actor_reference_hash']=hash_hmac('sha256','file06-case-v1|18|'.$uuid,'ci-salt');
$wpdb->rows=array(7=>$first,8=>$second,9=>$unrelated,10=>$otherMember);
must(is_array(SMC_File06_Case_Consent::verify_for_file06(null,array_merge($data,array('evidence_ref'=>'smc-f06-r8')))), 'Duplicate was previously active');
$response=SMC_File06_Case_Consent::withdraw(new File00ConsentRequest(
	array('research_public_id'=>$uuid,'evidence_ref'=>'smc-f06-r7')
));
must(is_array($response)&&($response['state']??'')==='withdrawn','Subject withdrawal committed');
must(SMC_File06_Case_Consent::current_for_file06(false,'smc-file06-v1:7','smc-f06-r7',$uuid,'patient-story-research')===false,'Original receipt revoked');
must(SMC_File06_Case_Consent::current_for_file06(false,'smc-file06-v1:8','smc-f06-r8',$uuid,'patient-story-research')===false,'Duplicate receipt revoked');
must(empty($wpdb->rows[9]['withdrawn_at']),'Other case remains unaffected');
must(empty($wpdb->rows[10]['withdrawn_at']),'Other member remains unaffected');
must(($GLOBALS['last_action']??'')==='smc_file06_patient_consent_withdrawn_v1','Withdrawal event emitted');
must($wpdb->locksHeld===0,'Case mutex released on successful withdrawal');
$wpdb->lockAvailable=false;
$busy=SMC_File06_Case_Consent::withdraw(new File00ConsentRequest(
	array('research_public_id'=>'22222222-2222-4222-8222-222222222222','evidence_ref'=>'smc-f06-r9')
));
must($busy instanceof WP_Error&&$busy->get_error_code()==='smc_file06_consent_busy',
	'Database lock refusal denies withdrawal safely');
must(empty($wpdb->rows[9]['withdrawn_at']),'Lock refusal did not mutate case consent');
must($wpdb->locksHeld===0,'Refused lock does not leak lock ownership');

/* Acceptance is serialized too: a second request reuses the exact active
 * receipt. Database lock refusal must not insert even a pending record.
 */
function case_request($uuid){
	return new File00ConsentRequest(array(
		'research_public_id'=>$uuid,'accepted'=>true,
		'policy_version'=>'case-policy-v1','locale'=>'ur-PK',
		'text_hash'=>hash('sha256',str_repeat('case consent terms ',12))
	));
}
$originalCount=count($wpdb->rows);
$busyAccept=SMC_File06_Case_Consent::accept(case_request('22222222-2222-4222-8222-222222222222'));
must($busyAccept instanceof WP_Error&&$busyAccept->get_error_code()==='smc_file06_consent_busy',
	'Lock refusal denies accept without creating a pending receipt');
must(count($wpdb->rows)===$originalCount,'Denied accept added no row');
$wpdb->lockAvailable=true;
$existing=SMC_File06_Case_Consent::accept(case_request('22222222-2222-4222-8222-222222222222'));
must(is_array($existing)&&$existing['evidence_ref']==='smc-f06-r9',
	'Under lock an existing active receipt is reused');
must(count($wpdb->rows)===$originalCount&&$wpdb->locksHeld===0,
	'Reused receipt is idempotent and lock released');
$newUuid='33333333-3333-4333-8333-333333333333';
$created=SMC_File06_Case_Consent::accept(case_request($newUuid));
must(is_array($created)&&$created['state']==='accepted',
	'Fresh consent creates a valid accepted receipt');
$newRef=$created['evidence_ref'];
$newId=(int)str_replace('smc-f06-r','',$newRef);
must(($wpdb->rows[$newId]['actor_type']??'')==='self'&&$wpdb->locksHeld===0,
	'New consent was activated after audit and lock released');
$createdAgain=SMC_File06_Case_Consent::accept(case_request($newUuid));
must($createdAgain['evidence_ref']===$newRef,
	'Consecutive accept requests reuse identical active receipt');
must(count($wpdb->rows)===$originalCount+1,'Idempotent repeat did not insert duplicate');
$wpdb->failActivation=true;
$noActivation=SMC_File06_Case_Consent::accept(case_request('44444444-4444-4444-8444-444444444444'));
must($noActivation instanceof WP_Error&&$noActivation->get_error_code()==='smc_file06_consent_activation_failed',
	'Failed DB activation returns an error, never a valid receipt');
$pending=end($wpdb->rows);
must($pending['actor_type']==='pending'&&$wpdb->locksHeld===0,
	'Failed activation remains non-authoritative and releases lock');
$wpdb->failActivation=false;
$wpdb->failCount=true;
$GLOBALS['last_action']=null;
$GLOBALS['reconciliation_option']=null;
$cannotVerify=SMC_File06_Case_Consent::withdraw(new File00ConsentRequest(
	array('research_public_id'=>$newUuid,'evidence_ref'=>$newRef)
));
must($cannotVerify instanceof WP_Error&&$cannotVerify->get_error_code()==='smc_file06_consent_reconciliation_required',
	'Failed post-revocation count cannot masquerade as verified success');
must($wpdb->locksHeld===0,'Reconciliation failure still releases DB lock');
must(($GLOBALS['reconciliation_option']??null)===1,
	'Uncertain revocation raises persistent operator reconciliation flag');
must(($GLOBALS['last_action']??null)==='smc_file06_patient_consent_withdrawn_v1',
	'Uncertain revocation broadcasts downstream invalidation');
$wpdb->failCount=false;
$wpdb->lockErrorAfterAcquire=true;
$reported=SMC_File06_Case_Consent::accept(case_request('55555555-5555-4555-8555-555555555555'));
must($reported instanceof WP_Error&&$reported->get_error_code()==='smc_file06_consent_busy',
	'Acquired lock with DB error fails closed');
must($wpdb->locksHeld===0,'Acquired lock with DB error must be released');


/*
 * V12 red-first TOCTOU regressions: File06 policy, subject relation and
 * membership eligibility may be revoked while MySQL GET_LOCK waits.
 * Accept must re-check authority *after* the lock, before any write.
 */
$wpdb->lockErrorAfterAcquire=false;
$wpdb->onAcquire=null;
$baseRows=count($wpdb->rows);
$cases=array(
    array('name'=>'eligibility','key'=>'member_eligible','uuid'=>'66666666-6666-4666-8666-666666666666'),
    array('name'=>'case relation','key'=>'relation_valid','uuid'=>'77777777-7777-4777-8777-777777777777'),
    array('name'=>'approved policy','key'=>'policy_valid','uuid'=>'88888888-8888-4888-8888-888888888888'),
);
foreach($cases as $case){
    $key=$case['key'];$uuidDuringWait=$case['uuid'];
    $GLOBALS[$key]=true;
    $wpdb->onAcquire=static function()use($key){$GLOBALS[$key]=false;};
    $outcome=SMC_File06_Case_Consent::accept(case_request($uuidDuringWait));
    must($outcome instanceof WP_Error && 'smc_file06_consent_changed'===$outcome->get_error_code(),
        'After-lock revoked '.$case['name'].' rejects stale consent');
    must(count($wpdb->rows)===$baseRows,
        'After-lock revoked '.$case['name'].' writes no consent record');
    must($wpdb->locksHeld===0,
        'After-lock revoked '.$case['name'].' releases case lock');
    $wpdb->onAcquire=null;
    $GLOBALS[$key]=true;
}

echo "File00→File06 consent: {$GLOBALS['count']} isolated assertions PASS.\n";

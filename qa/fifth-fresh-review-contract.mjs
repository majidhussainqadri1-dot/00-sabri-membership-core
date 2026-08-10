import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const adv=read('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php');
const main=read('source/sabri-membership-core/sabri-membership-core.php');
const readme=read('source/sabri-membership-core/README.txt');
const pkg=JSON.parse(read('package.json'));
const trace=JSON.parse(read('qa/fifth-fresh-ten-review-traceability.json'));
const checks=[
 ['runtime 1.2.37',main.includes('Version: 1.2.37')&&main.includes("SMC_VERSION', '1.2.37")&&pkg.version==='1.2.37'],
 ['adaptive step-up helper is authoritative',adv.includes('private static function actor_meets_step_up')&&adv.includes("step_up_requirement( $actor_id, sanitize_key( $action ) )")],
 ['critical identity uses adaptive step-up',/mark_critical_identity_change[\s\S]{0,1200}actor_meets_step_up[\s\S]{0,100}'identity_change'/.test(adv)&&/resolve_critical_identity_change[\s\S]{0,1000}actor_meets_step_up[\s\S]{0,120}'identity_change'/.test(adv)],
 ['guardian succession uses adaptive step-up',/begin_guardian_succession[\s\S]{0,900}actor_meets_step_up[\s\S]{0,100}'guardian_change'/.test(adv)&&/complete_guardian_succession[\s\S]{0,1200}actor_meets_step_up[\s\S]{0,120}'guardian_change'/.test(adv)],
 ['merge and delegation use adaptive step-up',/propose_account_merge[\s\S]{0,1000}actor_meets_step_up[\s\S]{0,100}'account_merge'/.test(adv)&&/grant_delegated_authority[\s\S]{0,1000}actor_meets_step_up[\s\S]{0,100}'delegation_grant'/.test(adv)],
 ['break-glass uses adaptive hardware policy at all three phases',(adv.match(/actor_meets_step_up\( \$[^,]+, 'break_glass'/g)||[]).length>=3&&adv.includes("'break_glass' => array( 4, 3, true )")],
 ['File24 containment is typed and freshness-bound',adv.includes("smc_file24_security_containment_authorization_v1")&&!adv.includes("smc_file24_security_containment_authorized'")&&adv.includes("'file24' === sanitize_key")&&adv.includes("'1.0.0' === (string)")],
 ['revocation hook exposes opaque subject',adv.includes("do_action( 'smc_trust_revocation_invalidated', $event['subject'], $event )")],
 ['VC chronology is coherent',adv.includes('$issued > $verified_at')&&adv.includes('$expires <= $issued')&&adv.includes('$expires <= $verified_at')],
 ['delegation revalidates grantor authority',adv.includes('delegation_grantor_current')&&/delegated_authorities[\s\S]{0,900}delegation_grantor_current/.test(adv)],
 ['break-glass stores approval times and revalidates approvers',adv.includes("'approval_times'")&&adv.includes('break_glass_approvals_current')&&adv.includes('break_glass_approver_current')],
 ['guardian successor is current-policy bound',adv.includes("smc_guardian_consents WHERE user_id=%d AND is_current=1 AND status='verified' AND policy_version=%s")],
 ['age is recomputed synchronously',adv.includes("SMC_Security::decrypt( $app['date_of_birth_enc']")&&adv.includes('smc_age_from_dob( $dob )')&&adv.includes('smc_effective_minimum_age(')],
 ['release docs current',readme.includes('Stable tag: 1.2.37')&&readme.includes('= 1.2.34 =')],
 ['contracts current',main.includes("SMC_CONTRACT_VERSION', '1.2.2")&&main.includes("SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0")],
 ['ten-round trace closed honestly',trace.review_complete===true&&JSON.stringify(trace.rounds_with_defects)===JSON.stringify([1,2,3,4,5,6,7,8,9,10])&&JSON.stringify(trace.rounds_without_defects)===JSON.stringify([])&&trace.defects_found_total===10&&trace.defects_corrected_total===10&&trace.known_unresolved_repository_defects===0],
 ['external gates remain pending',trace.external_status.staging_accepted===false&&trace.external_status.live_deployed===false&&trace.external_status.operational===false],
];
let fail=0;for(const [n,ok] of checks){console.log(`${ok?'PASS':'FAIL'} ${n}`);if(!ok)fail++;}console.log(`Fifth fresh static: ${checks.length-fail} PASS / ${fail} FAIL`);if(fail)process.exit(1);

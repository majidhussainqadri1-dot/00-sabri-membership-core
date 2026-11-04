/**
 * V12 R011–R090: two exact implementation-source signals per requirement.
 * These are static entrypoint checks, never a claim of 80 behavioral passes.
 */
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
const root=path.resolve(import.meta.dirname,'..');
const base='source/sabri-membership-core/';
const src={
 C:'includes/class-smc-contracts.php',
 A:'includes/class-smc-authorization.php',
 E:'includes/class-smc-events.php',
 AC:'includes/class-smc-authentication-contract.php',
 LV:'includes/class-smc-lifecycle.php',
 F:'includes/functions.php',
 CF:'includes/class-smc-cf01-contract.php',
 AD:'includes/class-smc-advanced-trust-2026.php',
 M:'includes/class-smc-mfa-retirement.php',
 W:'includes/class-smc-workflow.php',
 ADMIN:'includes/class-smc-admin.php',
 S:'includes/class-smc-security.php',
 INST:'includes/class-smc-installer.php',
 SCH:'includes/class-smc-schema-compat.php',
 P:'includes/class-smc-privacy.php',
 CMP:'includes/class-smc-completion.php',
 TP:'includes/class-smc-three-plan.php',
 JS:'assets/membership.js',
 CSS:'assets/membership.css'
};
const expectations={
 11:['C:init','A:init'],12:['C:assertions','E:emit'],
 13:['A:deny','A:filter_capabilities'],14:['AC:register_account','M:retire_mfa_assertions'],
 15:['C:assertions','CF:membership_assertion'],16:['E:emit','E:consume'],
 17:['A:filter_capabilities','A:enforce_rest_state'],18:['LV:is_institutional_user','F:smc_institutional_ai_user_id'],
 19:['C:role_grants','C:upsert_role_grant'],20:['A:enforce_frontend_state','A:enforce_rest_state'],
 21:['W:handle_submit_application','W:transition_self_service'],22:['F:smc_account_types','F:smc_account_class_for_user'],
 23:['AC:validate_registration','W:handle_submit_application'],24:['F:smc_age_from_dob','F:smc_effective_minimum_age'],
 25:['W:create_guardian_invitation','W:handle_verify_guardian'],26:['F:smc_is_professional_type','C:professional_verified'],
 27:['LV:repair_institutional_accounts','TP:save_institutional_ai'],28:['ADMIN:review_screen','ADMIN:handle_transition'],
 29:['ADMIN:approve','ADMIN:approval_gate'],30:['W:handle_appeal','W:handle_resubmit'],
 31:['C:identity_documents_current','S:store_uploaded_document'],32:['S:private_dir','S:serve_document'],
 33:['S:encrypt','S:decrypt'],34:['S:store_uploaded_document','S:local_document_scan_fallback'],
 35:['W:handle_verify_contact_otp','C:contact_verified'],36:['M:retire_mfa_assertions','AD:authentication_assurance'],
 37:['S:register_session','S:revoke_all_sessions'],38:['AC:quarantine_account','M:retire_recovery_page'],
 39:['LV:recheck_ages','A:current_age_eligible'],40:['A:file01_authorization_claim','CF:verify_step_up'],
 41:['INST:create_tables','SCH:reconcile_verification_queue_index'],42:['INST:migrate_user','INST:continue_migration'],
 43:['P:privacy_policy','S:minimize_audit_details'],44:['C:filter_profile_visibility','P:export_identity'],
 45:['W:record_consent','AD:consent_dependency_graph'],46:['P:active_hold','P:lock_for_erasure'],
 47:['P:export','P:register_exporter'],48:['P:erase','P:erase_records'],
 49:['S:audit','S:verify_audit_chain'],50:['INST:maybe_upgrade','CMP:post_restore_reconcile'],
 51:['AC:register_account','AD:authentication_assurance'],52:['C:publishing_assertions','C:filter_profile_visibility'],
 53:['CF:membership_assertion','C:clinical_commerce_assertions'],54:['C:professional_verified','C:approve_requested_roles'],
 55:['C:communication_assertions','AD:delegated_authorities'],56:['F:smc_notify','E:emit'],
 57:['CMP:private_headers','W:application_shortcode'],58:['C:publishing_assertions','E:emit'],
 59:['S:audit','A:file01_authorization_claim'],60:['C:filter_profile_visibility','W:status_shortcode'],
 61:['W:application_shortcode','CSS:#.smc-panel'],62:['W:status_shortcode','W:contact_forms'],
 63:['JS:#data-smc-step','JS:#smc-application-progress'],64:['W:security_shortcode','S:revoke_session'],
 65:['W:handle_verify_guardian','W:create_guardian_invitation'],66:['ADMIN:review_screen','ADMIN:audit_page'],
 67:['CSS:#@media (max-width: 640px)','CSS:#min-inline-size'],68:['CSS:#:focus-visible','JS:#aria-hidden'],
 69:['CSS:#[dir="rtl"]','CSS:#direction: rtl'],70:['JS:#offline','JS:#smc-retry-submit'],
 71:['S:audit','A:deny'],72:['A:enforce_rest_state','A:filter_capabilities'],
 73:['W:request_is_same_origin','S:request_hash'],74:['S:sanitize_image','P:export_identity'],
 75:['S:reject_symlink_path','S:path_is_within'],76:['S:rate_limited','M:retire_mfa_assertions'],
 77:['S:revoke_all_sessions','S:session_is_verified'],78:['A:enforce_appeal_reviewer_independence','ADMIN:handle_conflict'],
 79:['S:verify_audit_chain','S:key_ready'],80:['S:audit_key_is_sensitive','S:managed_keyring'],
 81:['CMP:health_snapshot','CMP:service_levels'],82:['E:schedule_processor','S:process_file_jobs'],
 83:['CMP:backup_manifest','CMP:post_restore_reconcile'],84:['CMP:safe_mode','CMP:record_repair'],
 85:['CMP:health_snapshot','INST:continue_migration'],86:['A:rest_route','AD:negotiate_contract'],
 87:['INST:activate','INST:create_tables'],88:['CMP:operational_owners','CMP:service_levels'],
 89:['S:audit_health_snapshot','CMP:record_repair'],90:['CMP:service_levels','CMP:operational_owners']
};
const cache=new Map(),rows=[],errors=[];
function load(name){
 if(!Object.prototype.hasOwnProperty.call(src,name))throw new Error('Undeclared source owner '+name);
 const p=base+src[name];
 if(!cache.has(p)){
  const bytes=fs.readFileSync(path.join(root,p));
  cache.set(p,{path:p,text:bytes.toString('utf8'),
   sha256:createHash('sha256').update(bytes).digest('hex')});
 }
 return cache.get(p);
}
for(let n=11;n<=90;n++){
 const id='F00-R'+String(n).padStart(3,'0');
 const expected=expectations[n];
 if(!Array.isArray(expected)||expected.length!==2){errors.push(id+' missing unique source mapping');continue;}
 const evidence=expected.map(spec=>{
  const sep=spec.indexOf(':'),alias=spec.slice(0,sep),signal=spec.slice(sep+1);
  const source=load(alias);
  const literal=signal.charAt(0)==='#',value=literal?signal.slice(1):signal;
  const checked=literal?source.text.includes(value):
   new RegExp('\\bfunction\\s+'+value+'\\s*\\(').test(source.text);
  return {path:source.path,sha256:source.sha256,
   symbol:value,source_kind:literal?'specific CSS/JS signal':'declared PHP function',
   verified:checked};
 });
 const passed=evidence.every(x=>x.verified);
 rows.push({id,source_entrypoints_verified:passed,evidence,
   per_requirement_behavior_accepted:false,external_consumer_accepted:false,staging_accepted:false});
 if(!passed)errors.push(id+': '+evidence.filter(x=>!x.verified).map(x=>x.path+' '+x.symbol).join(', '));
 else console.log('PASS '+id+' explicit implementation source');
}
if(rows.length!==80)errors.push('Exactly 80 source-mapped R IDs required');
const summary={
  requirement_ids:rows.length,expected:80,source_entrypoints_verified:rows.filter(x=>x.source_entrypoints_verified).length,
  two_signals_per_requirement:true,individual_behavioral_acceptance:0,
  scope:'R011-R090 code/source entrypoints; R001-R010 governance, R091-R100 QA/release remain separate',
  warning:'This scan does not verify function runtime semantics, consumer installation, UX accessibility, migration rollback or stage/live.'
};
fs.mkdirSync(path.join(root,'build'),{recursive:true});
fs.writeFileSync(path.join(root,'build/file00-v12-r80-source.json'),JSON.stringify({summary,rows},null,2)+'\n');
if(errors.length){errors.forEach(x=>console.error('V12 SOURCE GAP '+x));process.exit(1)}
console.log('File00 V12 R011-R090: 80/80 implementation source mappings PASS; behavioral acceptance pending');

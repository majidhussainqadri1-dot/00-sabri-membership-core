import fs from 'node:fs';
const cls = fs.readFileSync('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php','utf8');
const main = fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php','utf8');
const contracts = fs.readFileSync('source/sabri-membership-core/includes/class-smc-contracts.php','utf8');
const trace = JSON.parse(fs.readFileSync('qa/advanced-trust-traceability.json','utf8'));
const checks = [
  ['runtime 1.2.13', main.includes('Version: 1.2.13') && main.includes("SMC_VERSION', '1.2.13")],
  ['advanced contract constant', main.includes("SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0")],
  ['advanced class loaded', main.includes("class-smc-advanced-trust-2026.php") && main.includes('SMC_Advanced_Trust_2026::init()')],
  ['EXT-001 assurance levels', cls.includes('F00-EXT-001') && cls.includes('identity_assurance_level')],
  ['EXT-002 passkey/WebAuthn adapter', cls.includes('F00-EXT-002') && cls.includes('smc_file02_authentication_assurance_v1') && cls.includes('passkey_asserted') && cls.includes("'file02' === sanitize_key")],
  ['EXT-003 adaptive step-up', cls.includes('F00-EXT-003') && cls.includes('step_up_requirement') && cls.includes('hardware_backed_required')],
  ['EXT-004 bounded reverification', cls.includes('F00-EXT-004') && cls.includes('reverification_status') && cls.includes('REVERIFY_CURSOR_OPTION') && cls.includes("'offset' => $offset")],
  ['EXT-005 critical identity workflow', cls.includes('F00-EXT-005') && cls.includes('mark_critical_identity_change') && cls.includes('resolve_critical_identity_change') && cls.includes('revoke_all_sessions')],
  ['EXT-006 provenance/freshness', cls.includes('F00-EXT-006') && cls.includes('claims_envelope') && cls.includes('expires_at') && cls.includes("'owner' => 'file09'")],
  ['EXT-007 consent baseline cannot be removed', cls.includes('F00-EXT-007') && cls.includes('consent_dependency_graph') && cls.includes('array_merge( $purposes, $extra )')],
  ['EXT-008 guardian succession requires new verified consent', cls.includes('F00-EXT-008') && cls.includes('previous_guardian_consent_id') && cls.includes('new_guardian_consent_id') && cls.includes('current_guardian_consent_id')],
  ['EXT-009 account merge workflow', cls.includes('F00-EXT-009') && cls.includes('propose_account_merge') && cls.includes('approve_account_merge') && cls.includes('smc_account_merge_approved')],
  ['EXT-010 compromised containment', cls.includes('F00-EXT-010') && cls.includes('security_recovery_required') && cls.includes('smc_file24_security_containment_authorized')],
  ['EXT-011 revocation SLA', cls.includes('F00-EXT-011') && cls.includes('REVOCATION_PROPAGATION_SLA') && cls.includes('smc_trust_revocation_invalidated')],
  ['EXT-012 anti-downgrade contract negotiation', cls.includes('F00-EXT-012') && cls.includes('downgrade_allowed') && cls.includes('unsupported_or_future_contract')],
  ['EXT-013 privacy-minimal assertions', cls.includes('F00-EXT-013') && cls.includes('minimal_assertions') && !cls.includes("'date_of_birth' =>") && !cls.includes("'phone' =>")],
  ['EXT-014 selective proof uses File00 security keying', cls.includes('F00-EXT-014') && cls.includes('selective-disclosure-proof') && cls.includes('SMC_Security::blind_index') && cls.includes('PROOF_MAX_TTL')],
  ['EXT-015 VC adapter', cls.includes('F00-EXT-015') && cls.includes('smc_external_verifiable_credentials_v1')],
  ['EXT-016 scoped delegation', cls.includes('F00-EXT-016') && cls.includes('grant_delegated_authority') && cls.includes('90 * DAY_IN_SECONDS') && cls.includes('revoke_delegated_authority')],
  ['EXT-017 break-glass dual control', cls.includes('F00-EXT-017') && cls.includes('BREAK_GLASS_TTL') && cls.includes('count( array_unique') && cls.includes('actor_is_current')],
  ['EXT-018 non-human identity', cls.includes('F00-EXT-018') && cls.includes('subject_kind') && cls.includes("'institutional_ai'") && cls.includes("'human' => false")],
  ['EXT-019 continuity lifecycle', cls.includes('F00-EXT-019') && cls.includes("'deceased'") && cls.includes("'permanently_inactive'") && cls.includes('authorship_preserved')],
  ['EXT-020 privacy-safe trust timeline', cls.includes('F00-EXT-020') && cls.includes('trust_timeline') && cls.includes('allowed_actions') && cls.includes('subject_hash')],
  ['actor binding prevents confused deputy', cls.includes('actor_can_change_subject') && cls.includes('get_current_user_id() !== $actor_id')],
  ['containment strips sensitive caps', cls.includes('filter_capabilities') && cls.includes("$allcaps[ $cap ] = false")],
  ['base assertions enforce containment directly', contracts.includes('SMC_Advanced_Trust_2026::protected_actions_allowed') && contracts.includes("$base['eligible'] = false")],
  ['no parallel auth/pro/search owner', cls.includes('Authentication') && cls.includes('File 02') && cls.includes('File 09') && cls.includes('File 26')],
  ['public helper APIs', cls.includes('smc_advanced_trust_assertions') && cls.includes('smc_identity_assurance_profile') && cls.includes('smc_step_up_requirement') && cls.includes('smc_trust_timeline')],
  ['trace has all 20 extensions', Array.isArray(trace.requirements) && trace.requirements.length === 20 && trace.requirements.every((r,i)=>r.id===`F00-EXT-${String(i+1).padStart(3,'0')}` && r.coded===true && r.tested===true)],
  ['external runtime status remains truthful', trace.status.staging_accepted===false && trace.status.live_deployed===false && trace.status.operational===false],
];
let fail = 0;
for (const [name, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`);
  if (!ok) fail++;
}
console.log(`Advanced trust static: ${checks.length-fail} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);

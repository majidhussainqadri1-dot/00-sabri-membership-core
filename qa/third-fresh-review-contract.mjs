import fs from 'node:fs';
const read=p=>fs.readFileSync(p,'utf8');
const security=read('source/sabri-membership-core/includes/class-smc-security.php');
const contracts=read('source/sabri-membership-core/includes/class-smc-contracts.php');
const advanced=read('source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php');
const events=read('source/sabri-membership-core/includes/class-smc-events.php');
const privacy=read('source/sabri-membership-core/includes/class-smc-privacy.php');
const completion=read('source/sabri-membership-core/includes/class-smc-completion.php');
const bootstrap=read('source/sabri-membership-core/sabri-membership-core.php');
const assertions=[
 ['runtime 1.2.16', bootstrap.includes("Version: 1.2.16") && bootstrap.includes("SMC_VERSION', '1.2.16")],
 ['explicit non-secret key ID', security.includes("SMC_MASTER_KEY_ID") && security.includes("private static function legacy_key_id")],
 ['new encryption fails closed without key ID', security.includes("smc_key_id_missing")],
 ['legacy key id limited to compatibility', security.includes("hash_equals( $legacy_kid, $stored_kid )")],
 ['document scope assignment enforced', security.includes('reviewer_not_assigned') && security.includes('assigned_reviewer=%d')],
 ['arbitrary publishing filter removed', !contracts.includes("apply_filters( 'smc_external_publishing_claims'") && contracts.includes("smc_trusted_publisher")],
 ['doctor direct publish is capability fact', !contracts.includes("apply_filters( 'smc_doctor_direct_publish_allowed'") && contracts.includes("smc_doctor_direct_publish")],
 ['revocation propagation is exception-contained', advanced.includes("trust_revocation_propagation_failed") && advanced.includes('finally {')],
 ['canonical consent purposes', advanced.includes("membership_terms") && advanced.includes("identity_verification") && advanced.includes("ethical_use")],
 ['consent freshness binds policy version', advanced.includes("AND policy_version=%s AND withdrawn_at IS NULL")],
 ['outbox delivery exceptions retry', events.includes("Delivery adapter raised an exception.") && events.includes('catch ( Throwable $error )')],
 ['inbox stale claim recovery', events.includes('Recovered stale consumer claim.') && events.includes("updated_at<%s")],
 ['erasure uses canonical started event', privacy.includes("privacy_erasure_started")],
 ['health requires complete key configuration', completion.includes("SMC_Security::key_ready() && '' !== SMC_Security::key_id()")],
 ['restore evidence read-back verified', completion.includes("get_option( 'smc_last_restore_test', null ) !== $record")],
 ['restore audit is fail closed', completion.includes("Restore reconciliation evidence could not be appended")],
];
let failed=0;
for(const [name,ok] of assertions){ console.log(`${ok?'PASS':'FAIL'} ${name}`); if(!ok) failed++; }
console.log(`Third fresh static: ${assertions.length-failed} PASS / ${failed} FAIL`);
if(failed) process.exit(1);

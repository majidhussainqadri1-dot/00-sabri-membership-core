import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const functions = fs.readFileSync(path.join(plugin, 'includes', 'functions.php'), 'utf8');
const contracts = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-contracts.php'), 'utf8');
const workflow = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-workflow.php'), 'utf8');
const security = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-security.php'), 'utf8');
const privacy = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-privacy.php'), 'utf8');
const lifecycle = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-lifecycle.php'), 'utf8');
const admin = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-admin.php'), 'utf8');
const retirement = fs.readFileSync(path.join(plugin, 'includes', 'class-smc-mfa-retirement.php'), 'utf8');
const failures = [];
let passed = 0;
function assert(condition, name) { if (condition) passed += 1; else failures.push(name); }

assert(main.includes('Version: 1.2.38'), 'Plugin header is 1.2.38');
assert(main.includes("define( 'SMC_VERSION', '1.2.38' )"), 'Runtime version is 1.2.38');
assert(main.includes("define( 'SMC_DB_VERSION', '1.4.4' )"), 'Database version is 1.4.4');
assert(main.includes('SMC_Lifecycle::institutional_repair_complete()'), 'Release repair marker is conditional on a complete repair pass');
assert(functions.includes("if ( ! isset( smc_allowed_genders()[ $gender ] )"), 'Unknown gender fails closed');
assert(functions.includes('return false;'), 'Minimum-age resolver can reject corrupt gender');
assert(contracts.includes('$guardian_verified ='), 'Canonical eligibility computes guardian validity');
assert(contracts.includes('$contacts_verified ='), 'Canonical eligibility computes contact ownership');
assert(contracts.includes('$identity_documents_current ='), 'Canonical eligibility computes current identity evidence');
assert(contracts.includes('$eligible = $approved && $professional_verified && $guardian_verified && $contacts_verified && $identity_documents_current;'), 'All public can_* assertions share complete effective non-MFA eligibility');
assert(contracts.includes("'mfa_required'           => false"), 'Canonical File 00 contract explicitly retires MFA');
assert(contracts.includes("'appeal_review', 'erasure_pending', 'invalid_application'"), 'Public hard-block census is fail closed');
assert(contracts.includes("$wpdb->prefix . 'smc_contact_otps'"), 'Contact change invalidates canonical OTP assertion rows');
assert(contracts.includes("policy_version=%s AND withdrawn_at IS NULL"), 'Guardian assertion requires current unwithdrawn policy consent');
assert(contracts.includes("'phone_e164_enc' => $phone_enc"), 'Canonical phone follows the authentication phone change');
assert(workflow.includes("$applicant_version = (int) $app['row_version'] + 1;"), 'Initial submission advances applicant generation');
assert(workflow.includes('$user_id, $status, $queue_type, $trace_id, $sla_due, $applicant_version, $now, $now, $now'), 'Request binds queue, trace, SLA and resulting applicant generation');
assert(workflow.includes('SET status=%s,submitted_at=%s,updated_at=%s,row_version=row_version+1 WHERE user_id=%d AND row_version=%d'), 'Application submission advances the exact locked generation');
assert(workflow.indexOf("INSERT INTO {$wpdb->prefix}smc_contact_otps") < workflow.indexOf('SMC_Contact_Delivery::send_otp') && workflow.includes("DELETE FROM {$wpdb->prefix}smc_contact_otps WHERE user_id=%d AND channel=%s AND target_hash=%s AND code_lookup_hash=%s"), 'OTP verifier row is persisted before delivery and removed on delivery failure');
assert(workflow.indexOf("INSERT INTO {$wpdb->prefix}smc_guardian_consents") < workflow.indexOf("apply_filters( 'smc_send_guardian_invitation'") && workflow.includes('delivery_receipt_hash IS NOT NULL AND delivered_at IS NOT NULL'), 'Guardian invitation remains unusable until provider receipt evidence is committed');

// Retired MFA helpers remain dormant only for historical migration/regression evidence.
assert(retirement.includes("remove_action( 'admin_post_smc_' . $action"), 'Founder retirement removes historical MFA handlers from runtime');
assert(retirement.includes("remove_shortcode( 'smc_membership_recovery' )"), 'Founder retirement removes lost-factor recovery surface');
assert(workflow.includes('write_user_meta_verified'), 'Historical factor metadata helper remains internally read-after-write verified');
assert(workflow.includes('delete_user_meta_verified'), 'Historical one-time metadata deletion helper remains verified');
assert(workflow.includes("'_smc_recovery_receipt_v2'"), 'Historical recovery receipt implementation remains available only as dormant migration provenance');
assert(workflow.includes('true !== $challenge'), 'Dormant historical recovery helper requires exact TOTP result');
assert(workflow.includes('consume_recovery_code_for_session'), 'Dormant historical recovery-code service remains internally atomic');
assert(workflow.includes("applicant_version=%d,row_version=row_version+1"), 'Guardian verification and self-service generation changes invalidate stale votes');
assert(workflow.includes("status='suspended',reviewer_note=%s,applicant_version=%d"), 'Guardian withdrawal synchronizes the verification request');
assert(security.includes('public static function recovery_codes( $user_id, $count = 8, $receipt_callback = null )'), 'Dormant historical recovery service retains atomic receipt persistence');
assert(security.includes("return new WP_Error( 'smc_recovery_receipt'"), 'Dormant historical receipt failure rolls back code replacement');
assert(security.includes("self::audit( 'recovery_codes_rotated', $user_id )"), 'Dormant historical recovery rotation remains auditable');
assert(security.indexOf("self::audit( 'recovery_codes_rotated'") < security.indexOf("$owns_transaction && false === $wpdb->query( 'COMMIT' )", security.indexOf('public static function recovery_codes')), 'Dormant historical recovery audit remains atomic with codes');
assert(security.includes('public static function consume_recovery_code_for_session'), 'Dormant historical atomic recovery-session method exists');
assert(security.includes('LIMIT 1 FOR UPDATE'), 'Dormant historical recovery rows remain locked during consumption');
assert(privacy.includes("'containment_at' => ''"), 'Erasure lock records containment completion separately');
assert(privacy.includes("if ( ! empty( $lock['containment_at'] ) )"), 'Existing incomplete erasure locks retry containment');
assert(privacy.includes("'smc_erasure_containment_state'"), 'Durable containment confirmation is fail closed');
assert(lifecycle.includes('private static $repair_failures = 0;'), 'Institutional repair records failures');
assert(lifecycle.includes('public static function institutional_repair_complete()'), 'Institutional repair exposes completion state');
assert(lifecycle.includes('false === $minimum_age'), 'Lifecycle rejects corrupt gender policy state');
assert(lifecycle.includes("$wpdb->query( 'START TRANSACTION' );"), 'Lifecycle critical transitions are transactional');
assert(admin.includes('false === $minimum_age'), 'Approval rejects corrupt gender policy state');

console.log(`Ilhami cycle contract assertions passed: ${passed}`);
if (failures.length) {
  console.error(`Ilhami cycle contract assertions failed: ${failures.length}`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}
console.log('Ilhami cycle contract assertions failed: 0');

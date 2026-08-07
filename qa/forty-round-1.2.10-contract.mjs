import fs from 'node:fs';

const root = new URL('../', import.meta.url);
const load = (path) => fs.readFileSync(new URL(path, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const workflow = load('source/sabri-membership-core/includes/class-smc-workflow.php');
const completion = load('source/sabri-membership-core/includes/class-smc-completion.php');
const events = load('source/sabri-membership-core/includes/class-smc-events.php');
const review = load('docs/FORTY-ROUND-REVIEW-1.2.10.md');
const packageJson = JSON.parse(load('package.json'));

const checks = [
  ['runtime version', plugin.includes("define( 'SMC_VERSION', '1.2.14' );")],
  ['package version', packageJson.version === '1.2.14'],
  ['historical forty-round evidence retained', review.includes('1.2.10')],
  ['undefined guardian cleanup removed', !/guardian_consent_transaction_failed[\s\S]{0,500}submission_receipt_key/.test(workflow)],
  ['guardian write blocked by safe mode', !completion.match(/\$allowed\s*=\s*array\([\s\S]*?smc_verify_guardian[\s\S]*?\);/)],
  ['stale submission reclaim', workflow.includes("stale_application_submission_reclaimed") && workflow.includes('15 * MINUTE_IN_SECONDS')],
  ['stale repair reclaim', completion.includes('Recovered stale processing claim.')],
  ['targeted repair retry', completion.includes('reconcile_applications( 1, $id )')],
  ['targeted outbox retry', completion.includes('process_outbox( 1, $id )') && events.includes('$only_id = absint( $only_id );')],
  ['restore evidence server validation', completion.includes("strlen( $reference ) < 8")],
  ['no master-secret-derived key identifier', !completion.includes("hash( 'sha256', (string) SMC_MASTER_KEY )")],
  ['explicit non-secret key ID', completion.includes('SMC_MASTER_KEY_ID')],
  ['eligible document state', completion.includes("status IN ('submitted','approved')") && completion.includes('expiry_date>=UTC_DATE()')],
  ['corrupt draft cleanup', completion.includes('application_draft_decryption_failed') && completion.includes('application_draft_invalid')],
  ['complete backup table inventory', completion.includes("'smc_contact_otps'") && completion.includes("'smc_rate_limits'")],
  ['forty recorded rounds', (review.match(/^\|\s*\d+\s*\|/gm) || []).length === 40],
  ['external gates remain explicit', /Hostinger staging[\s\S]*remain external mandatory gates/.test(review)],
];
let failed = 0;
for (const [name, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}`);
  if (!ok) failed += 1;
}
if (failed) process.exit(1);
console.log(`${checks.length}/${checks.length} retained forty-round corrective assertions passed for runtime 1.2.14.`);

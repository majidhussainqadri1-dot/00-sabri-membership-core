import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const security = fs.readFileSync(path.join(plugin, 'includes/class-smc-security.php'), 'utf8');
const installer = fs.readFileSync(path.join(plugin, 'includes/class-smc-installer.php'), 'utf8');
const workflow = fs.readFileSync(path.join(plugin, 'includes/class-smc-workflow.php'), 'utf8');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const readme = fs.readFileSync(path.join(plugin, 'README.txt'), 'utf8');

const checks = [
  ['runtime 1.2.32', main.includes('Version: 1.2.32') && main.includes("SMC_VERSION', '1.2.32")],
  ['stable tag 1.2.32', readme.includes('Stable tag: 1.2.32') && readme.includes('= 1.2.32 =')],
  ['exact legacy schema signature', security.includes("array( 'subject_user_id', 'object_type', 'object_id' )") && security.includes("CHARACTER_MAXIMUM_LENGTH") && security.includes("auto_increment") && security.includes("allowed_bridge_columns")],
  ['legacy rows remain lower assurance', security.includes("'assurance'                  => 'legacy_snapshot_only'")],
  ['domain-separated keyed anchor', security.includes("smc:audit-legacy-anchor:v1|") && security.includes('hash_hmac')],
  ['anchor is create-once', security.includes("add_option( self::LEGACY_AUDIT_ANCHOR_OPTION") && !security.includes("update_option( self::LEGACY_AUDIT_ANCHOR_OPTION")],
  ['new anchor requires pre-HMAC source schema', security.includes("legacy_source_schema") && security.includes("smc_audit_legacy_anchor_source")],
  ['legacy history is never rewritten', !installer.match(/UPDATE\s+\{\$audit_table\}/i) && !installer.match(/DELETE\s+FROM\s+\{\$audit_table\}/i)],
  ['missing columns are additive only', installer.includes('ALTER TABLE {$audit_table} {$definition}') && installer.includes("ADD COLUMN previous_hash char(64) NOT NULL DEFAULT ''")],
  ['modern corruption still fails closed', security.includes('unhashed_row_after_chain_start') && security.includes('previous_hash_mismatch') && security.includes('row_hash_mismatch')],
  ['recovery rechecks the exact snapshot', installer.includes("'legacy_snapshot_hash', 'last_id', 'last_hash'") && installer.includes('smc_audit_partial_rows_changed')],
  ['tail binds only to modern last hash', installer.includes("$last = (string) ( $normalized['last_hash'] ?? '' )")],
  ['administrator receives real legacy state', workflow.includes('smc_legacy_state') && workflow.includes('Legacy audit snapshot: %s.')],
];

const failed = checks.filter(([, pass]) => !pass);
if (failed.length) {
  for (const [name] of failed) console.error(`FAIL: ${name}`);
  process.exit(1);
}
console.log(`${checks.length}/${checks.length} legacy audit anchor contract assertions passed.`);

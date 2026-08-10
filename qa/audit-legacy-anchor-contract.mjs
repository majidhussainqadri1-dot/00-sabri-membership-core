import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const plugin = path.join(root, 'source', 'sabri-membership-core');
const security = fs.readFileSync(path.join(plugin, 'includes/class-smc-security.php'), 'utf8');
const installer = fs.readFileSync(path.join(plugin, 'includes/class-smc-installer.php'), 'utf8');
const workflow = fs.readFileSync(path.join(plugin, 'includes/class-smc-workflow.php'), 'utf8');
const main = fs.readFileSync(path.join(plugin, 'sabri-membership-core.php'), 'utf8');
const readme = fs.readFileSync(path.join(plugin, 'README.txt'), 'utf8');
const row16Fixture = fs.readFileSync(path.join(root, 'qa/audit-historical-transition-wordpress.php'), 'utf8');
const workflowDir = path.join(root, '.github/workflows');
const workflowText = fs.readdirSync(workflowDir).filter((name) => name.endsWith('.yml')).map((name) => fs.readFileSync(path.join(workflowDir, name), 'utf8')).join('\n');

const checks = [
  ['runtime 1.2.37', main.includes('Version: 1.2.37') && main.includes("SMC_VERSION', '1.2.37")],
  ['schema 1.4.4', main.includes("SMC_DB_VERSION', '1.4.4")],
  ['stable tag 1.2.37', readme.includes('Stable tag: 1.2.37') && readme.includes('= 1.2.37 =')],
  ['exact legacy schema signature', security.includes("array( 'subject_user_id', 'object_type', 'object_id' )") && security.includes("CHARACTER_MAXIMUM_LENGTH") && security.includes("auto_increment") && security.includes("allowed_bridge_columns")],
  ['legacy rows remain lower assurance', security.includes("'assurance'                  => 'legacy_snapshot_only'")],
  ['versioned domain-separated anchors', security.includes("smc:audit-legacy-anchor:v1|") && security.includes("smc:audit-legacy-anchor:v2|") && security.includes('hash_hmac')],
  ['anchor is create-once', security.includes("add_option( self::LEGACY_AUDIT_ANCHOR_OPTION") && !security.includes("update_option( self::LEGACY_AUDIT_ANCHOR_OPTION")],
  ['verified interrupted bridge is explicit', security.includes('smc-audit-legacy-v1-bridge-columns') && security.includes('bridge_recovery_eligible') && security.includes('first_modern_hash')],
  ['encoded historical key transition is verification-only', security.includes('legacy-literal-') && security.includes('matching_audit_key') && security.includes("'write_key'")],
  ['new rows authenticate key generation', security.includes("'audit_key_id' => $audit_key_id") && installer.includes('audit_key_id varchar(64) NULL')],
  ['legacy history is never rewritten', !installer.match(/UPDATE\s+\{\$audit_table\}/i) && !installer.match(/DELETE\s+FROM\s+\{\$audit_table\}/i)],
  ['missing columns are additive only', installer.includes('ALTER TABLE {$audit_table} {$definition}') && installer.includes("ADD COLUMN previous_hash char(64) NOT NULL DEFAULT ''") && installer.includes('ADD COLUMN audit_key_id varchar(64) NULL')],
  ['modern corruption still fails closed', security.includes('unhashed_row_after_chain_start') && security.includes('previous_hash_mismatch') && security.includes('row_hash_mismatch') && security.includes('audit_key_generation_unavailable')],
  ['recovery rechecks rows and key epochs', installer.includes("'audit_key_epoch_digest'") && installer.includes('smc_audit_partial_rows_changed')],
  ['transaction-owned audit never performs DDL', installer.includes('audit_infrastructure_ready') && security.includes('$outer_transaction ? SMC_Installer::audit_infrastructure_ready() : SMC_Installer::ensure_audit_infrastructure()')],
  ['real row-16 WordPress fixture', row16Fixture.includes('historical row-16 transition') && row16Fixture.includes("'smc-audit-legacy-v1-bridge-columns'") && row16Fixture.includes('historical_transition_forward_append')],
  ['active CI is immutable and v1.2.37-aligned', !workflowText.includes('contents: write') && workflowText.includes('00-sabri-membership-core-1.2.37.zip')],
  ['tail binds only to modern last hash', installer.includes("$last = (string) ( $normalized['last_hash'] ?? '' )")],
  ['administrator receives real legacy state', workflow.includes('smc_legacy_state') && workflow.includes('Legacy audit snapshot: %s.')],
];

const failed = checks.filter(([, pass]) => !pass);
if (failed.length) {
  for (const [name] of failed) console.error(`FAIL: ${name}`);
  process.exit(1);
}
console.log(`${checks.length}/${checks.length} legacy audit anchor contract assertions passed.`);

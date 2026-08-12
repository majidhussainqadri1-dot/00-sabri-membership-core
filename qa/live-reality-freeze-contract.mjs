import fs from 'node:fs';

const source = fs.readFileSync('tools/live-reality-freeze.php', 'utf8');
const failures = [];
const expect = (condition, message) => { if (!condition) failures.push(message); };

expect(source.includes('smc-live-reality-freeze-v1'), 'collector format marker is missing');
expect(source.includes("defined( 'WP_CLI' )"), 'tool must be restricted to WP-CLI');
expect(source.includes("'read_only'    => true"), 'collector must declare read_only=true');
expect(source.includes("'repository_head' => 'unverified_from_deployed_runtime'"), 'runtime must not pretend to know repository HEAD');

for (const required of [
  'runtime_version',
  'expected_db_version',
  'actual_db_version',
  'release_marker',
  'entrypoint_sha256',
  'payload_fingerprint_sha256',
  'manifest_sha256',
  'activation_pending',
  'bootstrap',
  'deferred',
  'last_failure',
  'file01_runtime_version',
  'file01_contract_version',
  'file01_authorization_bridge_registered',
  'file02_consumer_version',
  'file02_provider_version',
  'wordpress_version',
  'php_version',
  'database_server',
  'environment_type',
  'smc_tables',
  'active_project_plugins',
  'live_verification_status',
]) {
  expect(source.includes(`'${required}'`), `required evidence key missing: ${required}`);
}

for (const forbidden of [
  /\bupdate_option\s*\(/,
  /\badd_option\s*\(/,
  /\bdelete_option\s*\(/,
  /\bset_transient\s*\(/,
  /\bdelete_transient\s*\(/,
  /\bupdate_user_meta\s*\(/,
  /\badd_user_meta\s*\(/,
  /\bdelete_user_meta\s*\(/,
  /\bwp_insert_user\s*\(/,
  /\bwp_update_user\s*\(/,
  /\bwp_delete_user\s*\(/,
  /\bactivate_plugin\s*\(/,
  /\bdeactivate_plugins\s*\(/,
  /\bwp_schedule_(?:single_)?event\s*\(/,
  /\$wpdb\s*->\s*(?:insert|update|delete)\s*\(/,
  /\bINSERT\s+INTO\b/i,
  /\bUPDATE\s+[^\n]+\s+SET\b/i,
  /\bDELETE\s+FROM\b/i,
  /\bALTER\s+TABLE\b/i,
  /\bDROP\s+(?:TABLE|INDEX)\b/i,
  /\bCREATE\s+(?:TABLE|INDEX)\b/i,
  /SMC_Installer::maybe_upgrade\s*\(/,
  /SMC_Schema_Compat::reconcile_/,
]) {
  expect(!forbidden.test(source), `read-only invariant violated by pattern: ${forbidden}`);
}

expect(source.includes('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'), 'table-shape query missing');
expect(source.includes('never row contents') || source.includes('Never row contents'), 'table-shape privacy comment missing');
expect(source.includes("$table_name = substr( $table_name, strlen( $wpdb->prefix ) );"), 'database prefix must be stripped from table names');
expect(source.includes("$field . '_sha256'"), 'diagnostic messages must be digested');
expect(!source.includes("get_option( 'admin_email'"), 'admin email must not be collected');
expect(!source.includes('wp_get_current_user'), 'user identity must not be collected');

if (failures.length) {
  console.error(`FAIL live-reality-freeze-contract (${failures.length})`);
  for (const failure of failures) console.error(` - ${failure}`);
  process.exit(1);
}

console.log('PASS live-reality-freeze-contract');

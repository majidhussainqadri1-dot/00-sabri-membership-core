import fs from 'node:fs';

const contract = fs.readFileSync('source/sabri-membership-core/includes/class-smc-cf01-contract.php','utf8');
const plugin = fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php','utf8');

const required = [
  "add_filter( 'sabri_file00_platform_uuid_v1'",
  "add_filter( 'sabri_file00_legacy_author_placeholder_v1'",
  "add_filter( 'wp_authenticate_user'",
  "ensure_legacy_author_placeholder_user",
  "_smc_legacy_author_placeholder_v1",
  "legacy_author_placeholder_created",
  "smc_legacy_author_placeholder_login_denied",
  "purpose' => 'file04_legacy_publication_migration'",
];
for (const needle of required) {
  if (!contract.includes(needle)) throw new Error('Missing File 04 authorship contract control: '+needle);
}
if (!contract.includes("return self::ensure_subject_uuid( $user_id );")) throw new Error('File 04 UUID bridge must resolve through File 00 immutable UUID owner.');
if (!contract.includes("'verified'         => true")) throw new Error('Placeholder provider must return a bound verified envelope.');
if (!plugin.includes("* Version: 1.2.45") || !plugin.includes("define( 'SMC_VERSION', '1.2.45' );")) throw new Error('File 00 release identity is not 1.2.45.');
if (contract.includes("'two_factor_ready' => true") || contract.includes("'session_two_factor' => true")) throw new Error('File 04 authorship bridge must not resurrect retired File 00 MFA.');
console.log('PASS File 00 governed File 04 authorship contracts');

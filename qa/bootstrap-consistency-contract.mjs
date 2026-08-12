import fs from 'node:fs';

const source = fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php', 'utf8');
const required = [
  'function smc_finalize_institutional_release_state()',
  "get_option( 'smc_institutional_repair_version', '' )",
  "update_option( 'smc_institutional_repair_version', SMC_VERSION, false )",
  "update_option( 'smc_release_version', SMC_VERSION, false )",
  'if ( ! smc_finalize_institutional_release_state() )',
  "'status' => 'ready'",
];
for (const needle of required) {
  if (!source.includes(needle)) {
    throw new Error(`Missing bootstrap-consistency invariant: ${needle}`);
  }
}

const finalizeCall = source.indexOf('if ( ! smc_finalize_institutional_release_state() )');
const readyState = source.indexOf("'status' => 'ready'", finalizeCall);
if (finalizeCall < 0 || readyState < 0 || readyState < finalizeCall) {
  throw new Error('Bootstrap may publish ready before institutional/release finalization.');
}

const fnStart = source.indexOf('function smc_finalize_institutional_release_state()');
const adminStart = source.indexOf("add_action(\n\t'plugins_loaded'", fnStart);
const fn = source.slice(fnStart, adminStart > fnStart ? adminStart : undefined);
if (!fn.includes("SMC_DB_VERSION !== (string) get_option( 'smc_db_version', '' )")) {
  throw new Error('Release-marker finalization is not bound to the current DB schema.');
}
if (!fn.includes('SMC_Lifecycle::institutional_repair_complete()')) {
  throw new Error('Release marker can be published without proving institutional repair completion.');
}

console.log('PASS bootstrap release-marker crash consistency contract');

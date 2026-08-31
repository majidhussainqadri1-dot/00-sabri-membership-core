import fs from 'node:fs';

const repair = fs.readFileSync('source/sabri-membership-core/includes/class-smc-live-document-transport-repair.php', 'utf8');
const main = fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php', 'utf8');
const js = fs.readFileSync('source/sabri-membership-core/assets/membership-evidence-stage.js', 'utf8');
const completion = fs.readFileSync('source/sabri-membership-core/includes/class-smc-completion.php', 'utf8');
const security = fs.readFileSync('source/sabri-membership-core/includes/class-smc-security.php', 'utf8');

const requiredRepairTokens = [
  "const BUILD_ID = '1.2.44-live-20260831-document-transport-r1'",
  "'smc_save_application_draft'",
  "'smc_clear_application_draft'",
  "'smc_stage_identity_document'",
  "remove_action( 'admin_init', array( 'SMC_MFA_Retirement', 'enforce_admin_state' ), 1 )",
  "SMC_Security::store_uploaded_document( 'evidence_file'",
  "'application_evidence_stage_failed'",
  "'application_evidence_staged'",
  "'smc_runtime_build_id'",
  "'phone_exists'",
  "'identity_exists'",
  "'document_transport_missing'",
];
for (const token of requiredRepairTokens) {
  if (!repair.includes(token)) throw new Error(`missing live repair contract: ${token}`);
}

if (!main.includes("require_once SMC_PATH . 'includes/class-smc-live-document-transport-repair.php';")) {
  throw new Error('live document transport repair is not loaded');
}
if (!main.includes("array( 'SMC_Live_Document_Transport_Repair', 'init' )")) {
  throw new Error('live document transport repair is not initialized');
}
if (!completion.includes("add_action( 'wp_ajax_smc_save_application_draft'")) {
  throw new Error('canonical draft AJAX handler is missing');
}
if (!security.includes("public static function store_uploaded_document")) {
  throw new Error('canonical evidence storage handler is missing');
}

const requiredJsTokens = [
  'new FormData()',
  "body.append('action', 'smc_stage_identity_document')",
  "body.append('evidence_file', file, file.name)",
  "input.dataset.smcStaged = '1'",
  'input.required = false',
  'event.stopImmediatePropagation()',
];
for (const token of requiredJsTokens) {
  if (!js.includes(token)) throw new Error(`missing staged-evidence browser contract: ${token}`);
}

const forbiddenRepairPatterns = [
  /0\s*===\s*strpos\(\s*\$action\s*,\s*['"]smc_['"]\s*\)/,
  /\$_FILES[^\n]*\['name'\][^\n]*audit/i,
  /'original_name'\s*=>/,
  /'file_name'\s*=>/,
];
for (const pattern of forbiddenRepairPatterns) {
  if (pattern.test(repair)) throw new Error(`forbidden broad/privacy pattern detected: ${pattern}`);
}

console.log('PASS File 00 live document transport + autosave routing + actionable errors regression');

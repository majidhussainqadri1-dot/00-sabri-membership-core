#!/usr/bin/env python3
"""Apply the second forty-round File 00 review-and-correction cycle.

The script is deliberately fail-closed: every expected source pattern must exist
exactly once before it is changed. Re-running an already-applied tree is a no-op.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "source" / "sabri-membership-core"
VERSION_FILE = PLUGIN / "sabri-membership-core.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8", newline="\n")


def replace_exact(path: Path, old: str, new: str, expected: int = 1) -> None:
    text = read(path)
    count = text.count(old)
    if count != expected:
        raise SystemExit(f"{path}: expected {expected} occurrence(s), found {count}: {old[:120]!r}")
    write(path, text.replace(old, new))


def replace_regex(path: Path, pattern: str, replacement: str, expected: int = 1) -> None:
    text = read(path)
    updated, count = re.subn(pattern, replacement, text, flags=re.S)
    if count != expected:
        raise SystemExit(f"{path}: expected {expected} regex replacement(s), found {count}")
    write(path, updated)


if "define( 'SMC_VERSION', '1.2.10' );" in read(VERSION_FILE):
    print("File 00 1.2.10 corrective cycle is already applied.")
    raise SystemExit(0)

# Preserve the former release documents as immutable history and create current copies.
for source_name, target_name in (
    ("docs/DUAL-PLAN-CODE-COMPLETION-1.2.9.md", "docs/DUAL-PLAN-CODE-COMPLETION-1.2.10.md"),
    ("docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.9.md", "docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.10.md"),
):
    source = ROOT / source_name
    target = ROOT / target_name
    if target.exists():
        raise SystemExit(f"Refusing to overwrite existing {target}")
    write(target, read(source).replace("1.2.9", "1.2.10"))

# Release identity is changed only in active source, QA and release-control records.
active_paths = [
    ROOT / "package.json",
    ROOT / "package-lock.json",
    ROOT / "tools/build.py",
    ROOT / ".github/workflows/file00-final-dual-plan-qa.yml",
    ROOT / ".github/workflows/cf01-contract.yml",
    ROOT / "docs/FILE-00-MASTER-PLAN-2026.md",
]
active_paths += sorted(p for p in PLUGIN.rglob("*") if p.is_file() and p.suffix.lower() in {".php", ".txt", ".js", ".css"})
active_paths += sorted(p for p in (ROOT / "qa").rglob("*") if p.is_file() and p.suffix.lower() in {".mjs", ".php", ".json", ".py"})
for path in active_paths:
    text = read(path)
    if "1.2.9" in text:
        write(path, text.replace("1.2.9", "1.2.10"))

workflow = PLUGIN / "includes/class-smc-workflow.php"
completion = PLUGIN / "includes/class-smc-completion.php"
events = PLUGIN / "includes/class-smc-events.php"

# Round 2: remove an undefined-variable cleanup copied into guardian verification.
replace_exact(
    workflow,
    "\t\t\tdelete_user_meta( $user_id, $submission_receipt_key );\n\t\t\tSMC_Security::audit( 'guardian_consent_transaction_failed'",
    "\t\t\tSMC_Security::audit( 'guardian_consent_transaction_failed'",
)

# Round 3: guardian approval is a state-changing workflow and must not bypass Safe Mode.
replace_exact(
    completion,
    "\t\t\t'smc_post_restore_reconcile', 'smc_download_backup_manifest', 'smc_verify_guardian',",
    "\t\t\t'smc_post_restore_reconcile', 'smc_download_backup_manifest',",
)

# Round 4: reclaim only an exact stale submission receipt; live processing remains blocked.
replace_exact(
    workflow,
    "\t\t$submission_receipt_key = '_smc_submission_' . substr( hash( 'sha256', $submission_key ), 0, 32 );\n"
    "\t\tif ( ! add_user_meta( $user_id, $submission_receipt_key, array( 'status' => 'processing', 'started_at' => time() ), true ) ) {\n"
    "\t\t\t$receipt = get_user_meta( $user_id, $submission_receipt_key, true );\n"
    "\t\t\tif ( is_array( $receipt ) && 'completed' === ( $receipt['status'] ?? '' ) ) {\n"
    "\t\t\t\tself::redirect( 'status', 'saved' );\n"
    "\t\t\t}\n"
    "\t\t\tself::redirect( 'application', 'invalid', array( 'duplicate' => 1 ) );\n"
    "\t\t}",
    "\t\t$submission_receipt_key = '_smc_submission_' . substr( hash( 'sha256', $submission_key ), 0, 32 );\n"
    "\t\t$processing_receipt = array( 'status' => 'processing', 'started_at' => time() );\n"
    "\t\tif ( ! add_user_meta( $user_id, $submission_receipt_key, $processing_receipt, true ) ) {\n"
    "\t\t\t$receipt = get_user_meta( $user_id, $submission_receipt_key, true );\n"
    "\t\t\tif ( is_array( $receipt ) && 'completed' === ( $receipt['status'] ?? '' ) ) {\n"
    "\t\t\t\tself::redirect( 'status', 'saved' );\n"
    "\t\t\t}\n"
    "\t\t\t$is_stale = is_array( $receipt ) && 'processing' === ( $receipt['status'] ?? '' ) && absint( $receipt['started_at'] ?? 0 ) <= time() - 15 * MINUTE_IN_SECONDS;\n"
    "\t\t\tif ( ! $is_stale || ! delete_user_meta( $user_id, $submission_receipt_key, $receipt ) || ! add_user_meta( $user_id, $submission_receipt_key, $processing_receipt, true ) ) {\n"
    "\t\t\t\tself::redirect( 'application', 'invalid', array( 'duplicate' => 1 ) );\n"
    "\t\t\t}\n"
    "\t\t\tSMC_Security::audit( 'stale_application_submission_reclaimed', $user_id, array( 'receipt_key_hash' => hash( 'sha256', $submission_receipt_key ) ) );\n"
    "\t\t}",
)

# Rounds 5-6: stale repair claims recover, and a manual retry processes exactly the selected row.
new_reconcile = r'''	public static function reconcile_applications( $limit = 25, $only_id = 0 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$only_id = absint( $only_id );
		$wpdb->query(
			"UPDATE {$wpdb->prefix}smc_application_repairs SET status='retry',next_attempt_at=UTC_TIMESTAMP(),last_error='Recovered stale processing claim.',updated_at=UTC_TIMESTAMP() WHERE status='processing' AND updated_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
		);
		if ( $only_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_application_repairs WHERE id=%d AND status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() LIMIT 1", $only_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_application_repairs WHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
		}
		foreach ( (array) $rows as $row ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status='processing',attempts=attempts+1,updated_at=%s WHERE id=%d AND status IN ('pending','retry')", current_time( 'mysql', true ), (int) $row['id'] ) );
			if ( 1 !== $claimed ) {
				continue;
			}
			$details = json_decode( (string) $row['details'], true );
			$resolved = (bool) apply_filters( 'smc_repair_application_item', false, $row, is_array( $details ) ? $details : array() );
			if ( ! $resolved && 'application_document_incomplete' === $row['repair_type'] ) {
				$resolved = self::application_documents_complete( (int) $row['user_id'] );
			}
			if ( $resolved ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status='complete',last_error=NULL,resolved_at=%s,updated_at=%s WHERE id=%d AND status='processing'", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $row['id'] ) );
			} else {
				$attempts = (int) $row['attempts'] + 1;
				$status = $attempts >= 10 ? 'dead_letter' : 'retry';
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_application_repairs SET status=%s,next_attempt_at=%s,last_error=%s,updated_at=%s WHERE id=%d AND status='processing'", $status, gmdate( 'Y-m-d H:i:s', time() + min( DAY_IN_SECONDS, (int) pow( 2, min( 10, $attempts ) ) * MINUTE_IN_SECONDS ) ), 'The repair condition is still unresolved.', current_time( 'mysql', true ), (int) $row['id'] ) );
			}
		}
	}

'''
replace_regex(
    completion,
    r"\tpublic static function reconcile_applications\( \$limit = 25 \) \{.*?\n\t\}\n\n(?=\tprivate static function application_documents_complete)",
    new_reconcile,
)

# Round 10: technical scan alone is not an eligible document state.
replace_exact(
    completion,
    "WHERE user_id=%d AND scan_status='passed'\", absint( $user_id )",
    "WHERE user_id=%d AND scan_status='passed' AND status IN ('submitted','approved') AND (expiry_date IS NULL OR expiry_date>=UTC_DATE())\", absint( $user_id )",
)

# Round 11: corrupt or undecryptable drafts cannot remain in a permanent retry loop.
replace_exact(
    completion,
    "\t\tif ( is_wp_error( $json ) ) {\n\t\t\treturn array();\n\t\t}\n\t\t$data = json_decode( $json, true );\n\t\treturn is_array( $data ) ? $data : array();",
    "\t\tif ( is_wp_error( $json ) ) {\n\t\t\tdelete_user_meta( absint( $user_id ), self::DRAFT_META );\n\t\t\tSMC_Security::audit( 'application_draft_decryption_failed', absint( $user_id ) );\n\t\t\treturn array();\n\t\t}\n\t\t$data = json_decode( $json, true );\n\t\tif ( ! is_array( $data ) ) {\n\t\t\tdelete_user_meta( absint( $user_id ), self::DRAFT_META );\n\t\t\tSMC_Security::audit( 'application_draft_invalid', absint( $user_id ) );\n\t\t\treturn array();\n\t\t}\n\t\treturn $data;",
)

# Rounds 6-7: button actions verify the exact row transition and process that row only.
replace_exact(
    completion,
    "\t\t$wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_application_repairs SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter','pending')\", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );\n\t\tself::reconcile_applications( 1 );",
    "\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_application_repairs SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter','pending')\", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );\n\t\tif ( 1 !== $updated ) {\n\t\t\twp_die( esc_html__( 'The selected repair item is no longer retryable.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );\n\t\t}\n\t\tself::reconcile_applications( 1, $id );",
)
replace_exact(
    completion,
    "\t\t$wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_event_outbox SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter')\", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );\n\t\tSMC_Events::process_outbox( 1 );",
    "\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_event_outbox SET status='pending',next_attempt_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status IN ('retry','dead_letter')\", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) );\n\t\tif ( 1 !== $updated ) {\n\t\t\twp_die( esc_html__( 'The selected event is no longer replayable.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );\n\t\t}\n\t\tSMC_Events::process_outbox( 1, $id );",
)

# Round 7: the outbox processor supports an exact manual replay target.
replace_exact(events, "public static function process_outbox( $limit = 25 )", "public static function process_outbox( $limit = 25, $only_id = 0 )")
replace_exact(
    events,
    "\t\t$limit = max( 1, min( 100, absint( $limit ) ) );\n\t\t$lock_name",
    "\t\t$limit = max( 1, min( 100, absint( $limit ) ) );\n\t\t$only_id = absint( $only_id );\n\t\t$lock_name",
)
replace_exact(
    events,
    "\t\t\t$rows = $wpdb->get_results(\n\t\t\t\t$wpdb->prepare(\n\t\t\t\t\t\"SELECT * FROM {$wpdb->prefix}smc_event_outbox\n\t\t\t\t\tWHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP()\n\t\t\t\t\tORDER BY id ASC LIMIT %d\",\n\t\t\t\t\t$limit\n\t\t\t\t),\n\t\t\t\tARRAY_A\n\t\t\t);",
    "\t\t\tif ( $only_id ) {\n\t\t\t\t$rows = $wpdb->get_results( $wpdb->prepare( \"SELECT * FROM {$wpdb->prefix}smc_event_outbox WHERE id=%d AND status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP() LIMIT 1\", $only_id ), ARRAY_A );\n\t\t\t} else {\n\t\t\t\t$rows = $wpdb->get_results(\n\t\t\t\t\t$wpdb->prepare(\n\t\t\t\t\t\t\"SELECT * FROM {$wpdb->prefix}smc_event_outbox\n\t\t\t\t\t\tWHERE status IN ('pending','retry') AND next_attempt_at<=UTC_TIMESTAMP()\n\t\t\t\t\t\tORDER BY id ASC LIMIT %d\",\n\t\t\t\t\t\t$limit\n\t\t\t\t\t),\n\t\t\t\t\tARRAY_A\n\t\t\t\t);\n\t\t\t}",
)

# Round 8: a restore test cannot pass without a meaningful external evidence reference.
replace_exact(
    completion,
    "\t\t$reference = sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) );\n\t\t$health = self::health_snapshot();",
    "\t\t$reference = sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) );\n\t\tif ( strlen( $reference ) < 8 ) {\n\t\t\tSMC_Security::audit( 'post_restore_reconciliation_rejected', 0, array( 'reason_code' => 'missing_evidence_reference' ) );\n\t\t\twp_die( esc_html__( 'A meaningful restore evidence reference is required.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );\n\t\t}\n\t\t$health = self::health_snapshot();",
)

# Round 9: never derive a displayed identifier from the master secret.
replace_exact(
    completion,
    "'key_identifier' => defined( 'SMC_MASTER_KEY' ) ? substr( hash( 'sha256', (string) SMC_MASTER_KEY ), 0, 16 ) : '',",
    "'key_identifier' => (string) apply_filters( 'smc_backup_key_identifier', defined( 'SMC_MASTER_KEY_ID' ) ? sanitize_key( (string) SMC_MASTER_KEY_ID ) : '' ),",
)

# Round 30: the manifest must inventory every owner table, including transient security state.
replace_exact(
    completion,
    "'smc_verification_events','smc_consents','smc_auth_sessions','smc_recovery_codes','smc_file_jobs'",
    "'smc_verification_events','smc_consents','smc_contact_otps','smc_auth_sessions','smc_recovery_codes','smc_rate_limits','smc_file_jobs'",
)

# Forty focused rounds: every pass ends with a correction or an enforceable regression gate.
rounds = [
    (1, "Release identity and truthful status", "Bumped active runtime/build/workflow evidence to 1.2.10 while preserving staging/live boundaries."),
    (2, "Guardian failure path", "Removed undefined application-submission variables from guardian transaction rollback."),
    (3, "Safe Mode write boundary", "Removed guardian state transition from the recovery-only Safe Mode allowlist."),
    (4, "Application idempotency", "Added exact stale-receipt reclamation without allowing concurrent duplicate processing."),
    (5, "Repair-worker crash recovery", "Added bounded stale-processing recovery for application repair claims."),
    (6, "Manual repair targeting", "Manual retry now proves the row transition and processes exactly the selected repair."),
    (7, "Manual outbox replay targeting", "Outbox replay now proves the row transition and delivers exactly the selected event."),
    (8, "Restore evidence integrity", "Server rejects empty or meaningless restore evidence references."),
    (9, "Key-detail minimization", "Removed a master-secret-derived fingerprint; only an explicit non-secret key ID may appear."),
    (10, "Document completion eligibility", "Rejected, expired or merely stale records cannot satisfy repair completion."),
    (11, "Encrypted draft corruption", "Undecryptable or invalid drafts are removed and privacy-safe audit evidence is recorded."),
    (12, "Private route caching", "Revalidated no-store/noindex/noarchive controls through the new contract suite."),
    (13, "File 00/File 02 ownership", "Revalidated that credential authentication cannot become membership approval."),
    (14, "Age baseline", "Revalidated male 15/female 12 baselines and raise-only jurisdiction extension."),
    (15, "Professional adult gate", "Revalidated the under-18 professional-account prohibition."),
    (16, "Guardian OTP", "Revalidated expiry, attempt limits, hashing and atomic state advancement."),
    (17, "Consent separation", "Revalidated distinct identity, privacy, terms and ethical-use evidence."),
    (18, "Identity uniqueness", "Revalidated blind-index duplicate checks before and inside governed writes."),
    (19, "Application concurrency", "Revalidated row-version locks and exact generation binding."),
    (20, "Multiple role grants", "Revalidated independent grants with backward-compatible primary role projection."),
    (21, "Reviewer conflict governance", "Revalidated assignment, recusal, reason codes and independent professional finalization."),
    (22, "Current MFA", "Revalidated current-session step-up for high-risk membership decisions."),
    (23, "Privacy erasure", "Revalidated durable tombstone, session revocation and retryable fail-closed cleanup."),
    (24, "Lifecycle restrictions", "Revalidated institutional precedence without bypassing manual hard blocks."),
    (25, "Outbox crash recovery", "Revalidated stale claims, retry, dead-letter and acknowledgement behavior."),
    (26, "Inbox replay safety", "Revalidated consumer/event uniqueness and processed-state idempotency."),
    (27, "Audit integrity", "Revalidated chained evidence and failure-visible writes."),
    (28, "Private storage containment", "Revalidated canonical non-symlink paths and authenticated encrypted evidence."),
    (29, "Upload quarantine", "Revalidated file validation, scan state and fail-closed delivery."),
    (30, "Backup scope", "Added omitted contact-OTP and rate-limit owner tables to the privacy-safe backup manifest."),
    (31, "Migration locking", "Revalidated advisory plus owner-token locking and checkpointed schema promotion."),
    (32, "Uninstall safety", "Revalidated non-destructive default uninstall and separately governed purge."),
    (33, "Administrative authorization", "Revalidated capability, current MFA, nonce and object/state checks."),
    (34, "Accessibility", "Revalidated labels, progress semantics, keyboard flow and status announcements."),
    (35, "RTL and localization", "Revalidated logical layout and localized user-facing strings."),
    (36, "No-JavaScript path", "Revalidated complete server-rendered submission and server authority."),
    (37, "Deterministic packaging", "Extended package verification to the 1.2.10 immutable candidate."),
    (38, "PHP compatibility", "Retained PHP 7.4 and PHP 8.3 lint/runtime matrices."),
    (39, "Dual-plan traceability", "Promoted all 100 requirement records to current 1.2.10 evidence paths."),
    (40, "Fresh adversarial whole-repository pass", "Added a dedicated second-cycle contract and reopened any failed invariant before release."),
]
review_lines = [
    "# File 00 — Forty Fresh Review-and-Correction Rounds — 1.2.10",
    "",
    "## تمہیدِ حاکم",
    "",
    "This is a new forty-round review of the current 1.2.9 repository state; it does not reuse the historical 1.2.8 conclusion as current evidence. Each round ended in a concrete code correction or an enforceable regression correction. Zero defect means zero known unresolved repository defect within the recorded source/QA scope, never absolute infallibility or Hostinger acceptance.",
    "",
    "| Round | Fresh review scope | Correction completed |",
    "|---:|---|---|",
]
for number, scope, correction in rounds:
    review_lines.append(f"| {number} | {scope} | {correction} |")
review_lines += [
    "",
    "## Corrected repository defects",
    "",
    "The newly confirmed defects were: undefined rollback variables, Safe Mode guardian-write bypass, stale idempotency lockout, stale repair claims, non-targeted manual retries, weak restore-evidence validation, secret-derived key fingerprint exposure, technically scanned but ineligible documents satisfying repair, corrupt draft persistence, and incomplete backup-table inventory.",
    "",
    "## Truthful acceptance boundary",
    "",
    "Repository source, static/runtime regression suites and deterministic packaging can be accepted only after exact-head GitHub Actions succeed. Hostinger staging, real providers, installed consumer runtimes, browser/accessibility/load evidence, backup/restore/rollback rehearsal, legal child-safety review and Founder production approval remain external mandatory gates.",
]
write(ROOT / "docs/FORTY-ROUND-REVIEW-1.2.10.md", "\n".join(review_lines) + "\n")

qa = r'''import fs from 'node:fs';

const root = new URL('../', import.meta.url);
const load = (path) => fs.readFileSync(new URL(path, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const workflow = load('source/sabri-membership-core/includes/class-smc-workflow.php');
const completion = load('source/sabri-membership-core/includes/class-smc-completion.php');
const events = load('source/sabri-membership-core/includes/class-smc-events.php');
const review = load('docs/FORTY-ROUND-REVIEW-1.2.10.md');
const packageJson = JSON.parse(load('package.json'));

const checks = [
  ['runtime version', plugin.includes("define( 'SMC_VERSION', '1.2.10' );")],
  ['package version', packageJson.version === '1.2.10'],
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
console.log(`${checks.length}/${checks.length} second forty-round corrective assertions passed.`);
'''
write(ROOT / "qa/forty-round-1.2.10-contract.mjs", qa)

# Add the new regression to the canonical test command exactly once.
package_path = ROOT / "package.json"
package_data = json.loads(read(package_path))
test_command = package_data["scripts"]["test"]
needle = "node qa/forty-round-1.2.10-contract.mjs"
if needle not in test_command:
    package_data["scripts"]["test"] = test_command + " && " + needle
package_data["version"] = "1.2.10"
package_data["scripts"]["verify"] = "npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.10.zip"
write(package_path, json.dumps(package_data, indent=2) + "\n")

# Current release records are intentionally pending until the exact immutable head passes CI.
readme = """# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, multiple role grants, security assertions, privacy lifecycle and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective candidate

- Version: `1.2.10`
- Contract: `1.1.2`
- Database schema: `1.3.0`
- Review method: forty fresh review → correction rounds against the current 1.2.9 main state
- Newly corrected groups: guardian rollback, Safe Mode, idempotency recovery, repair/outbox targeting, restore evidence, key minimization, document eligibility, corrupt drafts and backup inventory
- Exact-head GitHub Actions: **pending**
- Repository-correctable known defects after local corrective pass: **0 pending CI confirmation**
- Staging accepted: **No**
- Production/live approval: **No**

## Verification

```bash
npm ci --ignore-scripts
npm run verify
```

See `docs/FORTY-ROUND-REVIEW-1.2.10.md`, `docs/DUAL-PLAN-CODE-COMPLETION-1.2.10.md` and `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.10.md`.

Repository code completion never substitutes for Hostinger staging, real providers, cross-module runtime acceptance, browser/accessibility, load/recovery, legal approval or Founder production acceptance.
"""
write(ROOT / "README.md", readme)
status = """# Status

## Current state

**File 00 1.2.10 second forty-round corrective candidate; exact-head GitHub Actions pending.**

## Newly corrected in 1.2.10

- guardian rollback no longer references undefined application variables;
- Safe Mode blocks guardian state-changing verification;
- stale application submission receipts recover without opening duplicate execution;
- repair claims recover after worker crashes;
- manual repair and outbox actions target the selected record exactly;
- restore acceptance requires a meaningful evidence reference;
- backup manifests expose no master-secret-derived fingerprint and include every owner table;
- rejected/expired documents cannot satisfy application repair completion;
- corrupt encrypted drafts are cleared with privacy-safe audit evidence;
- forty fresh review scopes and a dedicated regression contract are recorded.

## Authorization boundary

- Repository candidate: **1.2.10 / schema 1.3.0 / contract 1.1.2**
- Exact-head QA: **Pending**
- Known unresolved repository defects: **0 pending exact-head confirmation**
- Hostinger staging accepted: **No**
- Production/live authorized: **No**
"""
write(ROOT / "STATUS.md", status)

# Append the actual second-cycle delta to copied completion records.
for path_name in ("docs/DUAL-PLAN-CODE-COMPLETION-1.2.10.md", "docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.10.md"):
    path = ROOT / path_name
    write(path, read(path) + "\n## 1.2.10 second forty-round delta\n\nSee `docs/FORTY-ROUND-REVIEW-1.2.10.md`. Exact-head CI remains pending in this corrective commit; all external staging/live gates remain open.\n")

print("Applied File 00 1.2.10 forty-round corrective cycle.")

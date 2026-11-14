# File 00 V12.3 — defect ledger (source-only audit)

**Incident ID:** F00-V123-20261114-D01
**Category:** File00→File09 doctor preverification intake / authenticated principal binding.
**Severity:** High source authorization boundary; live exposure unknown.
**Exact source main:** `1fad051c86d530d558be3eb1890d3c6e67bce6e8`.
**Source path:** `source/sabri-membership-core/includes/class-smc-file09-intake-contract.php`.

## Original reproducible problem
The `SMC_File09_Intake_Contract::assertion($user_id,$context)` provider checked that the submitted WordPress `$user_id` exists and that File00 membership, doctor role, identity, age, country and trust predicates were valid. It did **not** require `is_user_logged_in()` nor equality with `get_current_user_id()`. A direct provider call made for a valid doctor could therefore produce `result=allow` even if the active caller was anonymous/different. This is an intake-only permission, **not** a grant of clinical access; the real consumer's independent checks may mitigate the end-to-end exploit. A live incident has not been demonstrated.

## RED-first source proof
At Draft PR #59 SHA `6f44a2a4aec99270b51a4382e8ba8b5b030da08b`, `qa/file09-intake-contract-runtime.php` adds anonymous/cross-principal denial and positive correct-principal regression cases. GitHub run `43551059796` failed precisely at:
`FAIL: anonymous caller cannot borrow another user intake claim`.
Earlier `1d040535...` intentionally had a **test fixture syntax typo**; that did **not** establish the authorization bug, and was repaired before the meaningful RED run.

## Limited source fix
SHA `686bc5a1c7520892d95e3a95815152241b178f1c` requires WordPress current-principal helpers, authenticated session, and exact current-subject equality before issuing the claim; non-matching subjects return `unknown` with `authenticated_subject_mismatch`. Existing doctor request membership/identity/age/trust/jurisdiction guards and all six `grants_*=false` flags remain intact. The exact updated source SHA-256 in the deterministic manifest is `7246a3307323cd31cc60670ffeaeba0cb325ac01597e131db99b8502be8d09c1`.

## GREEN source evidence and boundary
At SHA `d91a8a0c639a20e1f41ef78d791c6511434e6fbb`, **9/9 GitHub Actions workflows** completed SUCCESS, including PHP 7.4/8.3 source QA, package check, existing real WordPress/MySQL CI and account contract tests. This proves the tested repository fix only. An independent installed current File09 consumer, real principal and role switching, actual Staging and Live plugin/DB parity remain **UNVERIFIED**.

## Counts in this audit
- Newly established source defect: **1**.
- Source fix on Draft PR #59: **1**.
- Targeted red/green regression: **1 corrected source boundary with 3 new actor cases**.
- Fix merged into main: **0**.
- Live repair confirmed: **0**.
- Other outstanding PR/release/consumer risks: separately tracked, not invented as newly confirmed source defects.

**Mandatory rule:** Do not mark this Live Resolved without exact deployed package, schema, migration and re-test evidence.

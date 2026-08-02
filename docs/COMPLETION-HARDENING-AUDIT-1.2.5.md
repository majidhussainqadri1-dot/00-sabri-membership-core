# File 00 — Completion Hardening Audit and Correction — 1.2.5

## Scope

This release performs a fresh post-1.2.4 adversarial review against the File 00 master plan, especially F00-R025–R030, F00-R041–R050, F00-R071–R080 and F00-R091–R100. It corrects repository-verifiable defects without manufacturing Hostinger, provider, browser, legal or Founder-acceptance evidence.

## Review round 1 — professional approval integrity

### Confirmed defects

1. The second independent professional approval vote was rolled back when its actor lacked the senior-finalizer capability. If the senior reviewer voted first, the workflow could deadlock because a different ordinary reviewer could not retain the second vote and the senior reviewer could not become a second distinct voter.
2. Approval votes were counted by request only. Votes from an older applicant/evidence generation could therefore survive resubmission or evidence replacement.
3. The reviewer screen displayed legacy contact usermeta instead of the canonical target-bound contact-verification assertions.

### Corrections

- Independent votes now commit and remain `approval_pending`; once the required count exists, a senior reviewer may finalize in a later action even if that senior reviewer cast the first vote.
- Vote counting requires an exact canonical evidence snapshot containing submitted applicant generation, policy version, sorted document key/version/hash/expiry data, identity hash/update time, guardian evidence and professional state.
- A re-vote refreshes the reviewer’s vote to the current snapshot; stale snapshots remain audit evidence but cannot count.
- Reviewer contact status uses the canonical File 00 assertions.

## Review round 2 — privacy erasure and audit integrity

### Confirmed defects

1. Erasure deleted application rows without a persistent account-level lock. A Founder or WordPress Administrator could therefore become implicitly verified again after their row disappeared.
2. File 00 account import and managed-role mutation did not guard against an erased account being recreated.
3. Erasure deleted subject-linked rows from the hash-chained audit table, which could break tamper-evident chain integrity.
4. Record deletion was progressive rather than transactionally retryable.

### Corrections

- Erasure writes a persistent fail-closed lock before deleting files or records and immediately revokes all sessions.
- The lock outranks institutional identity and application absence, always projecting `erasure_pending` and `approved=false`.
- Account import and managed-role mutation refuse to recreate an erased File 00 identity.
- Active File 00 records are deleted in a database transaction; any failure rolls back and returns `done=false` for retry.
- The erasure lock and anonymous receipt remain; audit rows remain unchanged under the published security/legal retention schedule so the hash chain is not corrupted.
- The WordPress privacy response truthfully reports retained minimal lock/audit evidence.

## Review round 3 — two-factor recovery reliability

### Confirmed defects

1. A recovery-code receipt was deleted before successful decryption, so a transient key/storage failure could destroy the user’s only display copy.
2. Two-factor setup could remain partially enabled if recovery-code receipt storage failed.
3. Setup did not validate the final current-session challenge result.

### Corrections

- Recovery receipts are deleted only after successful decryption and decoding; expired receipts are removed.
- Failed recovery receipt creation rolls back the enabled flag, encrypted secret and generated recovery-code rows, then revokes sessions.
- The final challenge result is checked before success is reported.

## New regression evidence

- `qa/completion-hardening-contract.mjs`
- `qa/approval-gate-runtime.php`
- `qa/privacy-erasure-runtime.php`

These tests are added to the locked `npm test` and deterministic `npm run verify` path.

## Release boundary

Plugin version: `1.2.5`
Contract version: `1.1.2`
Database schema: `1.2.0` (no structural migration)

This release may become a staging installation candidate only after exact-head GitHub Actions and deterministic package verification pass. Hostinger staging workflows, real providers, cross-plugin runtime, browser/accessibility, backup/restore/rollback, legal/child-safety review and Founder acceptance remain separate mandatory gates.

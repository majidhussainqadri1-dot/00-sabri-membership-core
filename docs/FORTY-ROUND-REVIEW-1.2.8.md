# File 00 — Forty Consecutive Review-and-Correction Cycles — Release 1.2.8

## Governing command

Forty separate review lenses were applied consecutively. At the end of every round, every confirmed repository-correctable defect was corrected before the next round began. A later round was permitted to challenge and correct an earlier correction.

## Governing sources

- Platform Definitive Master Plan v3.0 — SHA-256 `bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0`
- File 00 Four-Round Reviewed Final Master Plan — SHA-256 `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`

## Review protocol

Each round records the lens, confirmed defect, immediate correction and evidence target. “Corrected” is a repository-level statement only; Hostinger staging, real providers, cross-plugin runtime, browser/accessibility, load/recovery, legal review and Founder acceptance remain separate external gates.

## Review Round 01 — Governance and source hierarchy

**Confirmed defect:** The implementation had no dedicated forty-cycle evidence record tying the new review command to both governing plans.

**Immediate correction:** Created this immutable forty-round ledger and require the automated contract to count all forty rounds and both plan checksums.

**Evidence/retest:** `docs/FORTY-ROUND-REVIEW-1.2.8.md; qa/forty-round-contract.mjs`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 02 — Release identity consistency

**Confirmed defect:** Runtime work was still identified as 1.2.7, which could not distinguish the forty-cycle correction release.

**Immediate correction:** Bumped plugin and package identity to 1.2.8 while retaining contract 1.1.2 and schema 1.2.0.

**Evidence/retest:** `plugin header, SMC_VERSION, README.txt, package/build/workflows`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 03 — Bootstrap and deactivation lifecycle

**Confirmed defect:** Activation scheduled background hooks, but the plugin had no deactivation hook to clear them.

**Immediate correction:** Registered a deactivation hook and added SMC_Installer::deactivate() to clear lifecycle, file-job and migration hooks plus activation/repair transients.

**Evidence/retest:** `sabri-membership-core.php; class-smc-installer.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 04 — Uninstall safety

**Confirmed defect:** Uninstall returned before clearing scheduled jobs when destructive data purge was not authorized.

**Immediate correction:** Moved scheduled-hook cleanup ahead of the retention-aware early return; default uninstall still preserves identity data.

**Evidence/retest:** `uninstall.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 05 — Installer lock ownership

**Confirmed defect:** A failed schema-lock acquisition could delete another process’s owner-token option.

**Immediate correction:** The lock is deleted only when the stored owner token equals the current process token.

**Evidence/retest:** `class-smc-installer.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 06 — Role and capability mutation

**Confirmed defect:** The public exact-role helper accepted arbitrary role strings, permitting accidental or hostile privilege assignment by a caller.

**Immediate correction:** Restricted mutations to the canonical File 00 membership role allowlist, preserved administrator exclusion and verified exactly one managed membership role.

**Evidence/retest:** `class-smc-contracts.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 07 — Eligibility evidence freshness

**Confirmed defect:** Approved membership could remain eligible after identity evidence expired or ceased to be approved/scan-clean.

**Immediate correction:** Added identity_documents_current and made current approved scan-passed unexpired evidence an eligibility predicate for non-institutional accounts.

**Evidence/retest:** `class-smc-contracts.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 08 — Institutional and erasure precedence

**Confirmed defect:** The initial role-hardening patch risked removing existing institutional/privacy safeguards.

**Immediate correction:** Fresh review restored erasure-lock rejection and WordPress administrator exclusion before managed-role mutation.

**Evidence/retest:** `class-smc-contracts.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 09 — Approval request concurrency

**Confirmed defect:** Approval originally read the verification request before starting a transaction.

**Immediate correction:** The request is now re-read FOR UPDATE inside the transaction and exact row_version mismatch fails with 409.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 10 — Application concurrency

**Confirmed defect:** Approval updates were keyed too broadly by user/status and did not bind the exact application row version.

**Immediate correction:** Locked the application row and require exact application id, user id, status and row_version in pending/final approval updates.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 11 — Identity-record concurrency

**Confirmed defect:** Identity proof could change between predicate evaluation and final decision.

**Immediate correction:** Locked the identity record, included identity id/update/hash in the snapshot and updates target the exact identity id and user.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 12 — Document snapshot integrity

**Confirmed defect:** Approval evidence selected documents without locking and omitted document record IDs/scan state.

**Immediate correction:** Selected approved scan-passed unexpired documents FOR UPDATE and snapshot id, key, version, hash and expiry.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 13 — Guardian snapshot integrity

**Confirmed defect:** Guardian state could change during approval.

**Immediate correction:** Locked guardian consent and bound status, consent hash, policy, verification and withdrawal evidence into the approval snapshot.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 14 — Dual-review atomicity

**Confirmed defect:** Approval-pending/final transitions did not consistently couple exact state updates, event evidence and audit evidence.

**Immediate correction:** All votes, exact state updates, events, audit and session invalidation remain in one transaction and fail closed.

**Evidence/retest:** `class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 15 — Private storage canonicalization

**Confirmed defect:** Configured private paths could contain dot segments or resolve through a public/symlink path.

**Immediate correction:** Require absolute dot-free paths, perform lexical and realpath public-root containment checks and reject symlinks before and after creation.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 16 — Private file naming

**Confirmed defect:** Document and orphan patterns admitted names looser than the intended UUID-v4 storage contract.

**Immediate correction:** Tightened atomic targets, deferred jobs and lifecycle orphan matching to UUID-v4 .smcdoc identities.

**Evidence/retest:** `class-smc-security.php; class-smc-lifecycle.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 17 — Image re-encoding plaintext cleanup

**Confirmed defect:** Image editors may write to a different extension/path, leaving either the requested temporary file or actual saved plaintext behind.

**Immediate correction:** Track and verified-delete both paths; inability to prove cleanup fails closed.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 18 — Document commit and audit coupling

**Confirmed defect:** A stored document could commit before mandatory audit evidence, leaving an unaudited canonical file.

**Immediate correction:** The identity_document_stored audit is written before transaction commit; failure rolls back metadata and removes prepared files.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 19 — Superseded-file cleanup semantics

**Confirmed defect:** Failure to delete an old superseded ciphertext after the new document committed could falsely report the upload itself as failed.

**Immediate correction:** Keep the committed canonical document, queue bounded verified cleanup and record identity_document_cleanup_pending.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 20 — Deferred file-job allowlist

**Confirmed defect:** The cleanup queue accepted arbitrary job-type/name combinations and initially omitted privacy_delete used by erasure.

**Immediate correction:** Added strict per-job UUID patterns including privacy_delete and reject/audit all mismatches.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 21 — Deferred file-job integrity

**Confirmed defect:** Queued deletion trusted the stored filename and could delete a currently referenced canonical document.

**Immediate correction:** Recompute path blind index, enforce containment, reject marker/arbitrary names and refuse deletion of referenced .smcdoc files.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 22 — Deferred worker claim race

**Confirmed defect:** Two workers could select and process the same due job; the first patch still allowed reclaiming a freshly processing row.

**Immediate correction:** Use an atomic conditional claim limited to due pending/retry or stale processing rows, then update only status=processing.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 23 — Lifecycle overlap

**Confirmed defect:** Daily lifecycle processing could overlap and duplicate expiry/reconciliation work.

**Immediate correction:** Added a global advisory lock, bounded execution and verified lock release with audit on release failure.

**Evidence/retest:** `class-smc-lifecycle.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 24 — Contact OTP ordering

**Confirmed defect:** The provider received a code before canonical persistence; a subsequent DB failure made the delivered code unusable.

**Immediate correction:** Persist exact OTP first, then deliver; provider failure deletes that exact unverified row and records failure without holding a transaction open across I/O.

**Evidence/retest:** `class-smc-workflow.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 25 — TOTP replay state

**Confirmed defect:** A zero-row replay update called register_session, whose upsert reset last_totp_slice and reopened the same time slice.

**Immediate correction:** Lock the session row, reject last_totp_slice >= matching slice, update challenge and audit atomically, and never reset replay state on retry.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 26 — Revoked-session resurrection

**Confirmed defect:** Session registration’s duplicate update cleared revoked_at, allowing a revoked token to become active again.

**Immediate correction:** Duplicate registration refreshes only an already-active row and never clears revocation or replay markers.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 27 — Exact session revocation

**Confirmed defect:** Only token-hash rows were available, so remote revoke-one could not destroy the corresponding WordPress session token.

**Immediate correction:** Store an encrypted, context-bound exact token envelope and use WP_Session_Tokens::destroy for revoke-one; legacy rows fail safe to revoke-all.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 28 — Session revocation transaction nesting

**Confirmed defect:** revoke_all_sessions started a new transaction inside approval/privacy/lifecycle transactions, risking implicit commit and broken atomicity.

**Immediate correction:** Detect an existing transaction and own commit/rollback only when no outer transaction exists.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 29 — Session envelope cleanup atomicity

**Confirmed defect:** Exact/revoke-all cleanup initially occurred after standalone commit, allowing a revoked session with leftover encrypted token metadata.

**Immediate correction:** Delete token envelopes before commit, make cleanup part of the success predicate and clean user cache on rollback/success.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 30 — MFA freshness and inactivity

**Confirmed defect:** Session MFA checked only an absolute freshness window and wrote activity unsafely.

**Immediate correction:** Enforce twelve-hour MFA freshness plus thirty-minute inactivity, with five-minute write throttling and no-op-safe update handling.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 31 — Standalone recovery-code atomicity

**Confirmed defect:** The standalone recovery-code helper consumed a code without row locking and mandatory audit coupling.

**Immediate correction:** Lock the unused code FOR UPDATE, consume once, audit in the same transaction and roll back on any failure.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 32 — Audit-chain verification

**Confirmed defect:** The audit screen displayed hashes but did not verify chain continuity or row HMACs.

**Immediate correction:** Added streaming 500-row integrity verification and an explicit success/failure admin notice.

**Evidence/retest:** `class-smc-security.php; class-smc-admin.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 33 — CF-01 clinical identity assurance

**Confirmed defect:** clinical_identity_link was allowed on eligibility alone, without a fresh session-bound second factor.

**Immediate correction:** Require current session MFA for clinical identity linking and all sensitive clinical capabilities.

**Evidence/retest:** `class-smc-cf01-contract.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 34 — CF-01 assertion timing

**Confirmed defect:** Step-up responses lacked explicit issuance/expiry evidence.

**Immediate correction:** Added issued_at and expires_at to the bounded assertion response.

**Evidence/retest:** `class-smc-cf01-contract.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 35 — CF-01 rate-limit race

**Confirmed defect:** Transient read/modify/write rate limiting was non-atomic across concurrent requests.

**Immediate correction:** Use the canonical DB-backed atomic File 00 rate limiter.

**Evidence/retest:** `class-smc-cf01-contract.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 36 — CF-01 recovery audit atomicity

**Confirmed defect:** A recovery code could be consumed before the final step-up audit, losing the code if that audit failed.

**Immediate correction:** Consume recovery code and write final purpose/scope/method audit in the same transaction.

**Evidence/retest:** `class-smc-cf01-contract.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 37 — CF-01 TOTP audit retry

**Confirmed defect:** A TOTP replay marker remained consumed when mandatory final audit failed even though no successful assertion was issued.

**Immediate correction:** Delete the newly created replay marker on audit failure so a safe retry remains possible.

**Evidence/retest:** `class-smc-cf01-contract.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 38 — Audit and file-processing scalability

**Confirmed defect:** The first audit verifier loaded an unbounded table and cleanup processing lacked bounded/stale-work discipline.

**Immediate correction:** Stream audit verification in 500-row pages and process at most 25 file jobs with bounded backoff and stale-claim recovery.

**Evidence/retest:** `class-smc-security.php`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 39 — Supply chain, package and release gates

**Confirmed defect:** The release needed a dedicated 1.2.8 deterministic package and workflows that execute the forty-round contract on PHP 7.4/8.3.

**Immediate correction:** Update package/build/workflow identities, add forty-round QA and retain pinned actions, secret/archive/symlink/LFS and PHP-compatibility gates.

**Evidence/retest:** `package.json; tools/build.py; GitHub workflows; qa/forty-round-contract.mjs`

**Round status:** Corrected and carried forward to the next fresh review.

## Review Round 40 — Final adversarial cross-system review

**Confirmed defect:** The final fresh pass found post-commit session-envelope cleanup and evidence-ledger regression risks after earlier corrections.

**Immediate correction:** Moved envelope cleanup into transactional success, added anti-regression assertions for every material correction, reran lint/tests and require exact-head CI before merge.

**Evidence/retest:** `class-smc-security.php; qa/forty-round-contract.mjs; exact-head CI`

**Round status:** Corrected and carried forward to the next fresh review.

## Final repository stop condition

The forty rounds stop only after the 1.2.8 source lints, the forty-round static contract passes, all inherited runtime/contract suites pass, the deterministic package verifies and exact-head GitHub Actions are green. Any CI defect reopens the cycle and must be corrected before merge.

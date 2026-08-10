# File 00 — Authoritative Master-Plan Implementation Index 2026

## Governing documents

- Platform Definitive Master Plan v3.0 — SHA-256 `bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0`
- File 00 Four-Round Reviewed Final Master Plan — SHA-256 `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Continuous Value / Global Top-20 Superset — 6 August 2026 — later central constitution for green/free/File 26 decisions.
- File 00 governing implementation addendum — 7 August 2026 — F00-CEN-01/02/03 and latest acceptance/traceability law.
- Founder change-control — 10 August 2026 — File 00 two-factor verification is retired. This later explicit decision supersedes prior File 00 MFA/TOTP/recovery requirements while preserving all non-MFA identity, eligibility, guardian, verification, audit, privacy, containment and continuity controls.

## Current implementation identity

- Runtime implementation release: `1.2.39`
- Public membership contract: `1.2.2`
- Database schema: `1.4.4`
- MFA policy: `2026-08-10-founder-mfa-retirement-v1`
- File 00 MFA owner: `none`
- CF-01 membership-assurance contract: `1.1.0` — membership prerequisite only; File 00 authentication/MFA assurance retired
- Latest-central constitution: `2026-08-07-v1.0`
- File 26 membership projection contract: `1.0.0`
- Advanced trust contract: `1.0.0`
- Exact-head CI: pending until the current commit succeeds
- Main merge: pending until review/PR gates succeed

## Founder-approved MFA retirement boundary

File 00 no longer requires or exposes authenticator/TOTP setup, recovery codes, a user-entered MFA session challenge, authenticator replacement, or governed lost-factor recovery. File 02 remains the canonical normal sign-in/password/account-recovery owner. File 00 continues to enforce membership eligibility, identity assurance, verified guardian consent, professional verification assertions, contact ownership, institutional authority, containment/continuity, privacy/retention, session revocation and tamper-evident audit evidence.

The retirement migration is intentionally fail-safe: obsolete File 00 factor material is removed only after DB schema `1.4.4` and audit infrastructure are ready; cleanup is transactional and historical audit rows are preserved. A retirement audit event is appended rather than rewriting prior MFA history.

CF-01 contract `1.1.0` deliberately returns membership prerequisite evidence only. Any stronger authentication assurance belongs to File 02 or another separately approved authentication owner; File 00 no longer verifies factor codes for CF-01.

## Live-proven schema compatibility correction — 1.2.36

Live Hostinger evidence on 10 August 2026 proved that the surviving `smc_verification_requests` table had the historical non-unique BTREE index `queue(status,assigned_reviewer)` while the current schema requires `queue(status,queue_type,assigned_reviewer)`. WordPress `dbDelta()` attempted to add the changed named index without first replacing the old named index, and MariaDB correctly rejected the migration with `Duplicate key name 'queue'`. The live database therefore remained at `1.2.0` and the later `smc_event_outbox`, `smc_event_inbox`, and `smc_application_repairs` tables were never reached.

Release `1.2.36` adds a narrow fail-closed compatibility preflight. It recognizes only the exact historical queue-index signature proven live, removes that obsolete non-unique secondary index, allows the normal `dbDelta()` schema pass to create the current three-column index, and then read-back verifies both the verification queue and file-job queue signatures. Fresh installs/current schemas are no-ops; unknown index shapes are refused rather than mutated. The DB schema target remains `1.4.4` because this is migration-idempotency logic, not a new schema contract.

## Live-proven schema compatibility correction — 1.2.37

After `1.2.36` was deployed, live Hostinger evidence proved that the queue transition completed successfully and migration advanced to the next historical same-name index. The live `smc_approval_votes` table still had the non-unique BTREE index `decision(request_id,decision)`, while the current schema requires `decision(request_id,approval_generation,decision)`. MariaDB therefore rejected the normal `dbDelta()` pass with `Duplicate key name 'decision'` before downstream table creation and version promotion could finish.

Release `1.2.37` extends the same narrow fail-closed compatibility boundary to this exact live-proven approval-decision signature. Only `decision(request_id,decision)` with the expected non-unique BTREE attributes can be removed automatically; absent/current indexes are no-ops and every unknown shape is refused. The normal `dbDelta()` pass then creates `decision(request_id,approval_generation,decision)`, after which the compatibility verifier checks the exact current decision index together with both queue indexes. The DB schema target remains `1.4.4`; this is an idempotent historical migration bridge, not a new schema shape.

## Live-proven institutional Administrator role-backfill correction — 1.2.38

After `1.2.37` was deployed, live Hostinger evidence proved that the named-index compatibility transitions were crossed but the database still remained at `1.2.0` because migration advanced to `Role-grant backfill failed.` The live Founder account (`user_id=1`) had a surviving File 00 application (`membership_type=member`, `status=draft`) while its native WordPress account retained Administrator authority. No `role-grants-to-1.3.0` checkpoint was created, proving failure occurred inside the first backfill item before checkpoint persistence.

Exact v1.2.37 source showed that `SMC_Contracts::sync_wordpress_roles()` deliberately returned `false` for every `manage_options` user to protect Administrator roles from File 00 membership-role mutation. `SMC_Installer::backfill_role_grants()` interpreted that same `false` as a failed write and aborted. The protection itself was correct; the boolean contract was not.

Release `1.2.38` preserves the institutional boundary and changes only its success semantics: a valid Administrator is a protected successful no-op for WordPress role synchronization, while a missing user or privacy-erasure lock still returns failure. The canonical File 00 role grant can therefore be backfilled without adding/removing native Administrator roles. The real WordPress 7.0.1 + MariaDB 11.4 gate now recreates the Administrator + legacy application condition, removes the role-grant checkpoint, reruns migration, and requires DB `1.4.4`, a complete role-grant checkpoint, the expected pending canonical grant, unchanged Administrator roles, preserved institutional identity precedence, current named indexes, and downstream tables.

## Live-proven orphaned application role-backfill correction — 1.2.39

After `1.2.38` was deployed, fresh Hostinger DB evidence proved the Administrator item was no longer the only relevant state. The live `smc_applications` table contained seven historical rows while WordPress principals for application IDs `3` and `4` (`user_id=7` and `user_id=8`) no longer existed. The only migration checkpoint remained `legacy-users-to-1.2.0=complete`; `role-grants-to-1.3.0` was still absent. Exact runtime code creates/updates a role grant before attempting `sync_wordpress_roles()`, so the first missing principal causes the backfill to abort and can leave a derivative orphan grant even though the historical application row itself must be preserved.

Release `1.2.39` adds a narrowly gated compatibility bridge in `SMC_Schema_Compat`. It runs only when the exact live-proven `Role-grant backfill failed.` marker is present, the legacy `1.2.0` baseline is complete, the required modern tables exist, the keyring is ready, and audit infrastructure verifies successfully. Existing WordPress users continue through the canonical `upsert_role_grant()` + `sync_wordpress_roles()` path. A missing WordPress principal is not fabricated and the historical application is not deleted; instead a deterministic `smc_application_repairs` record is created, any derivative role grant for that nonexistent principal is suspended, an append-only audit event is written, and the migration cursor advances. Privacy-erasure locks remain a separate fail-closed condition and are never reclassified as missing-user orphans. Per-row checkpointing makes the bridge restartable and deterministic.

A post-bootstrap compatibility finalizer retries the normal installer only after this orphan-safe checkpoint reaches `complete`. It clears only the exact stale role-backfill/deferred-key markers after DB promotion actually reaches `1.4.4`; unrelated migration failures are preserved. The DB schema target remains `1.4.4` because this is historical data-state reconciliation, not a new canonical schema contract.

## Current evidence

- `source/sabri-membership-core/includes/class-smc-schema-compat.php`
- `qa/mfa-retirement-contract.mjs`
- `qa/mfa-retirement-wordpress-mysql.php`
- `.github/workflows/mfa-retirement-wordpress-mysql.yml`
- `qa/cf01-contract.mjs`
- `qa/cf01-contract-runtime.php`
- `docs/FILE-00-THIRD-FRESH-TEN-ROUND-REVIEW-1.2.16.md`
- `docs/RELEASE-1.2.16-THIRD-FRESH-TEN-REVIEW.md`
- `qa/third-fresh-ten-review-traceability.json`
- `qa/third-fresh-review-contract.mjs`
- `qa/third-fresh-review-runtime.php`

- `docs/FILE-00-SECOND-FRESH-TEN-ROUND-REVIEW-1.2.15.md`
- `docs/RELEASE-1.2.15-SECOND-FRESH-TEN-REVIEW.md`
- `qa/second-fresh-ten-review-traceability.json`
- `qa/second-fresh-review-contract.mjs`
- `qa/second-fresh-review-runtime.php`

- `docs/FILE-00-FRESH-TEN-ROUND-REVIEW-1.2.14.md`
- `docs/RELEASE-1.2.14-FRESH-TEN-REVIEW.md`
- `qa/fresh-ten-review-traceability.json`

- `docs/FILE-00-ADVANCED-TRUST-EXTENSIONS-1.2.13.md`
- `qa/advanced-trust-traceability.json`
- `qa/advanced-trust-contract.mjs`
- `qa/advanced-trust-runtime.php`

- `docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md`
- `docs/RELEASE-1.2.12-LATEST-CENTRAL.md`
- `qa/latest-central-traceability.json`
- `qa/latest-central-contract.mjs`
- `qa/latest-central-runtime.php`
- `qa/three-plan-runtime-completion-contract.mjs`
- `qa/master-plan-traceability-contract.mjs`

Historical 1.2.11 three-plan evidence remains immutable in `qa/requirements-traceability.json` and `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.11.md`; it is regression/provenance evidence, not the current release identity. Historical 1.2.34 governed-recovery evidence likewise remains provenance only and is superseded operationally by the 10 August 2026 Founder change-control.

## Truthful boundary

The source may be a repository-complete candidate only after exact-head automated QA and the required fresh review/fix rounds. Staging-Accepted, Live-Deployed and Operational remain separate states requiring real environment evidence and Founder approval.

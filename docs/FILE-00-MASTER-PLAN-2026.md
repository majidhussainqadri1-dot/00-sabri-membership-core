# File 00 — Authoritative Master-Plan Implementation Index 2026

## Governing documents

- Platform Definitive Master Plan v3.0 — SHA-256 `bd171fe39da8c10294d7cf1a92bc9ce917b082905b978280a25e1e3c9ec617e0`
- File 00 Four-Round Reviewed Final Master Plan — SHA-256 `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Continuous Value / Global Top-20 Superset — 6 August 2026 — later central constitution for green/free/File 26 decisions.
- File 00 governing implementation addendum — 7 August 2026 — F00-CEN-01/02/03 and latest acceptance/traceability law.
- Founder change-control — 10 August 2026 — File 00 two-factor verification is retired. This later explicit decision supersedes prior File 00 MFA/TOTP/recovery requirements while preserving all non-MFA identity, eligibility, guardian, verification, audit, privacy, containment and continuity controls.

## Current implementation identity

- Runtime implementation release: `1.2.36`
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

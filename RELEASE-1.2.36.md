# File 00 — Sabri Membership Core 1.2.36

Date: 10 August 2026

## Purpose

Correct the live-proven File 00 schema-upgrade failure `Schema migration failed: Duplicate key name 'queue'` without deleting membership data, rewriting audit history, or manually forcing the database version.

## Exact live evidence

The deployed `wp_smc_verification_requests` table contains the historical non-unique BTREE index:

`queue(status,assigned_reviewer)`

The current File 00 schema requires:

`queue(status,queue_type,assigned_reviewer)`

The `queue_type` column already exists. `wp_smc_file_jobs` already has its correct independent `queue(status,next_attempt_at)` index. The later `wp_smc_event_outbox`, `wp_smc_event_inbox`, and `wp_smc_application_repairs` tables are absent because migration stops before reaching them.

## Root cause

WordPress `dbDelta()` does not safely replace this changed same-name secondary index in the observed legacy transition. It attempts to add the current `queue` index while the old `queue` name still exists, and MariaDB rejects the DDL with `Duplicate key name 'queue'`.

## Correction

- Add `SMC_Schema_Compat` as a narrow migration-compatibility preflight.
- Recognize only the exact historical queue signature proven on live.
- Remove only that obsolete non-unique secondary index before the normal `dbDelta()` pass.
- Treat a fresh install, absent index, or already-current index as a no-op.
- Refuse unknown index shapes fail-closed.
- Read-back verify the final verification queue and file-job queue definitions after successful DB promotion.
- Reproduce the exact live legacy index and missing downstream tables in WordPress 7.0.1 + MariaDB 11.4 CI before accepting the release.

## Version identity

- Runtime: `1.2.36`
- DB target: `1.4.4` (unchanged)
- Public contract: `1.2.2` (unchanged)
- CF-01 contract: `1.1.0` (unchanged)
- File 00 MFA owner: `none`

## Acceptance boundary

Repository tests and a deterministic package do not establish production resolution. After deployment, live acceptance still requires exact deployed version confirmation, DB promotion to `1.4.4`, final queue-index read-back, creation of the three downstream tables, absence of new `migration_failed` events, governed MFA-retirement cleanup, and normal account/workflow smoke tests.

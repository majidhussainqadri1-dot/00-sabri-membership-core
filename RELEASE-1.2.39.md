# File 00 — Sabri Membership Core 1.2.39

## Live-first incident basis

This release exists for the next live-proven migration defect discovered after File 00 1.2.38 was deployed to Hostinger. Fresh live DB evidence showed that `legacy-users-to-1.2.0` was complete at cursor 10, while `role-grants-to-1.3.0` still had no checkpoint and the database remained at `1.2.0`. The live `smc_applications` inventory contained seven historical rows; application IDs 3 and 4 referenced WordPress `user_id` 7 and 8, but those WordPress principals no longer existed.

Exact current runtime semantics create/update a File 00 role grant and then call `sync_wordpress_roles()`. Missing WordPress users are a genuine synchronization failure, so the first orphaned application stopped the whole role-grant migration and could leave a derivative role-grant row behind. The historical application itself is source evidence and must not be deleted or rewritten merely to make migration pass.

## Correction

- Adds a narrowly gated orphan-safe role-grant compatibility bridge in `SMC_Schema_Compat`.
- The bridge activates only for the exact live-proven `Role-grant backfill failed.` state, only while the DB is below the current target, only when the legacy `1.2.0` migration baseline is complete, and only after key/audit infrastructure proves ready.
- Existing WordPress users still use the canonical `SMC_Contracts::upsert_role_grant()` plus `sync_wordpress_roles()` path.
- Missing WordPress principals are never fabricated and their historical applications are never deleted.
- Each missing principal receives a deterministic `smc_application_repairs` quarantine record; any derivative role grant for that nonexistent principal is suspended rather than treated as an active entitlement; an append-only audit event records the quarantine.
- Privacy-erasure locks remain a separate fail-closed condition and are never auto-skipped as missing-user orphans.
- Checkpoint persistence occurs after each successfully handled application, making the compatibility migration restartable and deterministic.
- A post-bootstrap finalizer retries the normal installer only after the orphan-safe checkpoint reaches complete and clears only the exact stale role-backfill/deferred-key markers after actual DB promotion succeeds.

## Version identity

- Runtime: `1.2.39`
- DB schema target: `1.4.4`
- Public membership contract: `1.2.2`
- CF-01 membership-assurance contract: `1.1.0`
- File 00 MFA remains retired under the Founder change-control dated 10 August 2026.

## Acceptance boundary

Repository/CI/package success does not prove the Hostinger incident resolved. Final status remains unresolved until the exact merged package is deployed and live read-only verification proves: runtime 1.2.39, DB 1.4.4, `role-grants-to-1.3.0=complete`, orphan repair records exist for the live missing principals, their role grants are not active, stale migration notices are cleared, downstream tables/indexes remain correct, and no fresh `migration_failed` event appears after the successful promotion.

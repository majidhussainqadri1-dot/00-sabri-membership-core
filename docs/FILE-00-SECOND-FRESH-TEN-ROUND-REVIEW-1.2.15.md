# File 00 — Second Fresh Ten-Round Corrective Review — Release 1.2.15

Second fresh ten-round review complete: **Yes**

Date: 8 August 2026

Baseline: `main` at `6f557b764e57509d414d4bdb6c2f654a8a0a7f20` (Release 1.2.14).

Release: **1.2.15**. Database schema remains **1.3.0**; public membership contract remains **1.2.0**; Advanced Trust contract remains **1.0.0**.

| Round | Focus | Defects | IDs | Result |
|---:|---|---:|---|---|
| 1 | tracked release artifact hygiene | 1 | SFR-R1-D01 | Corrected |
| 2 | migration-lock contention availability | 1 | SFR-R2-D01 | Corrected |
| 3 | immutable authorization baselines | 2 | SFR-R3-D01, SFR-R3-D02 | Corrected |
| 4 | mandatory Safe Mode | 1 | SFR-R4-D01 | Corrected |
| 5 | private identity-document step-up access | 1 | SFR-R5-D01 | Corrected |
| 6 | event inbox schema/runtime compatibility | 1 | SFR-R6-D01 | Corrected |
| 7 | application lifecycle replay/idempotency | 2 | SFR-R7-D01, SFR-R7-D02 | Corrected |
| 8 | privacy-export data minimization | 1 | SFR-R8-D01 | Corrected |
| 9 | durable outbox retry scheduling | 1 | SFR-R9-D01 | Corrected |
| 10 | release identity, permanent QA integration and master-index consistency | 3 | SFR-R10-D01, SFR-R10-D02, SFR-R10-D03 | Corrected |

**Rounds with defects:** 1, 2, 3, 4, 5, 6, 7, 8, 9, 10.  
**Rounds without defects:** none.  
**Unique defects:** 14. Corrected: **14/14**.  
**Severity:** 1 Critical, 8 High, 5 Medium, 0 Low.  
**Known unresolved repository defects:** 0.

## Corrective summary

- **SFR-R1-D01 — Medium:** stale generated `dist/1.2.0` release files were tracked beside a newer runtime. Generated release ZIP/checksum outputs are now ignored and untracked; only the immutable original source archive checksum remains tracked.
- **SFR-R2-D01 — High:** `maybe_upgrade()` acquired the migration lock outside its guarded `try`, so ordinary lock contention could escape as a request-fatal exception. Lock acquisition is now caught and safely deferred/recorded.
- **SFR-R3-D01 / D02 — High / High:** filters could remove mandatory restricted capabilities or hard-block statuses. Filters may add policy but cannot subtract the File 00 baseline.
- **SFR-R4-D01 — High:** the Safe Mode filter could turn off a constant/option-declared Safe Mode. Declared Safe Mode is now monotonic and cannot be filtered off.
- **SFR-R5-D01 — High:** the `manage_options` fallback could release a private identity document without a current File 00 two-factor session. Private document release now always requires a fresh verified security session.
- **SFR-R6-D01 — Critical:** the event consumer referenced `dedupe_hash` and `created_at` columns absent from the canonical `smc_event_inbox` schema. Consumer idempotency now uses the existing unique `(consumer,event_id)` contract and canonical columns.
- **SFR-R7-D01 — High:** the application UI enforced controlled lifecycle states but the direct POST handler lacked the equivalent early lifecycle gate. The handler now blocks replay outside draft/more-information/rejected states.
- **SFR-R7-D02 — Medium:** final submission idempotency marker writes were not persistence-verified. Both markers are now read-back verified and failure is audited; lifecycle state independently blocks post-success replay.
- **SFR-R8-D01 — Medium:** every privacy export page eagerly decrypted/queried all four data groups. The exporter now evaluates only the requested page, reducing unnecessary C2/C3 processing.
- **SFR-R9-D01 — High:** when all outbox deliveries failed, `$processed` stayed zero and no prompt retry processor was scheduled. Pending/retry backlog now preserves processor scheduling.
- **SFR-R10-D01 — High:** corrected runtime behavior initially retained the previous 1.2.14 package identity. Runtime/package identity is advanced coherently to **1.2.15**.
- **SFR-R10-D02 — Medium:** the new second-fresh regression suites were initially limited to temporary corrective tooling. They are now permanent `npm test` / read-only CI release gates.
- **SFR-R10-D03 — Medium:** exact-head release QA found the authoritative master implementation index still declaring runtime 1.2.14 after source had become 1.2.15. The current implementation index and evidence pointers are now aligned with 1.2.15 while historical evidence remains immutable.

## Acceptance boundary

Repository coding/package/automated-QA may be marked complete only after read-only exact-head CI is green on the final PR head and again on merged `main`. Hostinger Staging-Accepted, Live-Deployed and Operational remain separate external gates and are not inferred from repository success.

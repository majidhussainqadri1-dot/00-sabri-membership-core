# File 00 — Third Fresh Ten-Round Corrective Review — Release 1.2.16

Third fresh ten-round review complete: **Yes**

Date: 8 August 2026

Baseline: `main` at `c9c299163df49322ae868d35374524ca46a3edca` (Release 1.2.15). Earlier ten-round cycles were not counted.

| Round | Focus | Defects | IDs | Result |
|---:|---|---:|---|---|
| 1 | cryptographic key lifecycle | 1 | TFR-R1-D01 | Corrected |
| 2 | publishing authorization provenance | 1 | TFR-R2-D01 | Corrected |
| 3 | trust revocation propagation | 1 | TFR-R3-D01 | Corrected |
| 4 | private identity evidence object authorization | 1 | TFR-R4-D01 | Corrected |
| 5 | consent registry semantics/freshness | 2 | TFR-R5-D01, TFR-R5-D02 | Corrected |
| 6 | sessions/TOTP/recovery/rate limits | 0 | — | No reproducible defect |
| 7 | durable inbox/outbox exception recovery | 2 | TFR-R7-D01, TFR-R7-D02 | Corrected |
| 8 | privacy erasure downstream invalidation | 1 | TFR-R8-D01 | Corrected |
| 9 | backup/restore operational evidence | 2 | TFR-R9-D01, TFR-R9-D02 | Corrected |
| 10 | release identity and permanent regression QA | 2 | TFR-R10-D01, TFR-R10-D02 | Corrected |

**Rounds with defects:** 1, 2, 3, 4, 5, 7, 8, 9, 10.  
**Rounds without defects:** 6.  
**Unique defects:** 13. Corrected: **13/13**.  
**Severity:** 1 Critical, 7 High, 5 Medium, 0 Low.  
**Known unresolved repository defects:** 0 after exact-head QA closure.

## Corrective summary

- **TFR-R1-D01 — High:** runtime encryption exposed a secret-derived key fingerprint as its key identifier. New writes now require explicit non-secret `SMC_MASTER_KEY_ID`; legacy SMC2 ciphertext remains decrypt-compatible.
- **TFR-R2-D01 — High:** arbitrary publishing filter claims could become authority. Publishing authority is now derived from File 00 capabilities/current canonical facts rather than untyped badge-like filters.
- **TFR-R3-D01 — High:** a throwing revocation consumer could strand the advisory lock and interrupt security transitions. Lock release is unconditional and propagation failure is fail-closed.
- **TFR-R4-D01 — Critical:** private identity-document access lacked reviewer-to-subject assignment binding. Ordinary reviewers now require an active assigned verification request in addition to capability and fresh 2FA.
- **TFR-R5-D01 — High:** EXT-007 consent purposes did not match the canonical consent registry keys. Baseline purposes now align with actual File 00 records.
- **TFR-R5-D02 — Medium:** historical unwithdrawn consent could satisfy current policy. Consent dependencies now require the active File 00 policy version.
- **TFR-R7-D01 — High:** outbox adapter exceptions could leave rows processing and bypass prompt retry scheduling. Exceptions are contained and transition to retry/dead-letter.
- **TFR-R7-D02 — High:** inbox callback/finalization failures could strand idempotency rows. Stale claims are reclaimable and failed processing is replay-safe.
- **TFR-R8-D01 — High:** erasure lock used a non-canonical audit action, so downstream derived projections might not receive immediate invalidation. It now emits canonical `privacy_erasure_started`.
- **TFR-R9-D01 — Medium:** health could report encryption ready without a usable non-secret key ID. Health now requires both key material and key identifier.
- **TFR-R9-D02 — Medium:** restore reconciliation did not verify evidence/audit persistence. Both are now read-back/fail-closed gates.
- **TFR-R10-D01 — Medium:** corrected behavior required a distinct release identity; runtime/package advanced coherently to 1.2.16.
- **TFR-R10-D02 — Medium:** prior QA missed the runtime key-ID path. Third-fresh static/runtime regressions are permanent release gates.

## Acceptance boundary
Repository coding/package/automated-QA may be marked complete only after exact-head read-only CI is green on branch/PR and merged `main`. Hostinger Staging-Accepted, Live-Deployed and Operational remain separate external gates.

# File 00 — Latest-Central Two-Round Corrective Review — 1.2.12

**Governing corpus:** 6–7 August 2026 central constitution + File 00 governing addendum  
**Review branch:** `codex/file00-latest-central-1.2.12`  
**Rule:** review → fix → retest; then a second fresh review on the corrected source.  
**External boundary:** Hostinger staging, real providers, browsers/assistive technology, restore/load drills, live deployment and operational monitoring are not proved by repository review.

## Round 1 — Fresh adversarial review and correction

**Corrected exact head:** `f24f2bce3c0f3182fe6918fbc01a1fde68f68960`  
**Latest-Central QA run:** `31195053357` — success  
**CF-01/Forty-Round run:** `31195053237` — PHP 7.4 success; PHP 8.3 success  
**Deterministic package:** `dist/00-sabri-membership-core-1.2.12.zip`  
**Package SHA-256:** `bfd2841605dae0897003884f7fed56375c5842613a157818208f418d12d63cb7`  
**Core inherited assertions:** 573 PASS / 0 FAIL, plus all named contract/runtime suites green.

### Defects found and corrected

| ID | Severity | Defect found | Correction | Regression evidence |
|---|---:|---|---|---|
| LC-R1-D01 | High | F00-CEN-03 used second-resolution timestamps with `>=`; an old MFA challenge created in the same second as a guardian/consent/security change could satisfy the new revalidation cutoff. | Revalidation marker is now strictly future (`time()+1` minimum); a successful new TOTP or recovery-code challenge clears the marker transactionally before the assurance audit/commit. | `qa/latest-central-contract.mjs`; full `npm run verify` |
| LC-R1-D02 | High | Revalidation marker persistence ran as a passive audit action. If the marker write failed, the original audit could still return success and a transactional state change could commit with stale assurance. | Added mandatory `smc_audit_record_guard`; guard failure propagates through `SMC_Events::from_audit()` to `SMC_Security::audit()` so transactional callers fail closed. | latest-central static/runtime fail-write test + inherited audit/transaction suites |
| LC-R1-D03 | Medium | File 26 membership projection returned the internal WordPress numeric user ID, unnecessarily widening identity exposure across the search boundary. | Projection now returns File 00's opaque `platform_uuid` from the CF-01 identity contract and omits `user_id`; search/index ownership remains File 26. | opaque-UUID + privacy-minimal projection assertions |
| LC-R1-D04 | Medium | The adulthood transition that ends `guardian_required` changed guardian state but was not explicitly in the central revalidation invalidation list. | Added `guardian_requirement_ended_at_adulthood` to mandatory revalidation/projection invalidation actions. | latest-central static assertion |

### Round-1 result

Four reproducible repository defects were found; all four were corrected before the round was closed. The corrected exact head passed both independent active GitHub workflow families. No staging/live claim is made.

## Round 2 — pending fresh review

A second fresh adversarial review must be performed on the post-Round-1 source. It must not merely repeat Round 1. It will re-read ownership, F00-CEN-01/02/03, File 09/File 26 boundaries, privacy/security failure modes, migrations/rollback, package determinism and active CI. Any newly reproducible defect will be corrected and retested before merge.

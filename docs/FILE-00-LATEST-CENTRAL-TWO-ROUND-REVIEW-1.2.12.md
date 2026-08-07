# File 00 — Latest-Central Two-Round Corrective Review — 1.2.12

**Governing corpus:** 6–7 August 2026 central constitution + File 00 governing addendum  
**Review branch:** `codex/file00-latest-central-1.2.12`  
**Rule:** review → fix → retest; then a second fresh review on the corrected source.  
**External boundary:** Hostinger staging, real providers, browsers/assistive technology, restore/load drills, live deployment and operational monitoring are not proved by repository review.

## Round 1 — Fresh adversarial review and correction

**Corrected exact source head:** `f24f2bce3c0f3182fe6918fbc01a1fde68f68960`  
**Latest-Central QA run:** `31195053357` — success  
**CF-01/Forty-Round run:** `31195053237` — PHP 7.4 success; PHP 8.3 success  
**Deterministic package:** `dist/00-sabri-membership-core-1.2.12.zip`  
**Package SHA-256 at this round:** `bfd2841605dae0897003884f7fed56375c5842613a157818208f418d12d63cb7`  
**Core inherited assertions:** 573 PASS / 0 FAIL, plus all named contract/runtime suites green.

### Defects found and corrected

| ID | Severity | Defect found | Correction | Regression evidence |
|---|---:|---|---|---|
| LC-R1-D01 | High | F00-CEN-03 used second-resolution timestamps with `>=`; an old MFA challenge created in the same second as a guardian/consent/security change could satisfy the new revalidation cutoff. | Revalidation marker is now strictly future (`time()+1` minimum); a successful new TOTP or recovery-code challenge clears the marker transactionally before the assurance audit/commit. | `qa/latest-central-contract.mjs`; full `npm run verify` |
| LC-R1-D02 | High | Revalidation marker persistence ran as a passive audit action. If the marker write failed, the original audit could still return success and a transactional state change could commit with stale assurance. | Added mandatory `smc_audit_record_guard`; guard failure propagates through `SMC_Events::from_audit()` to `SMC_Security::audit()` so transactional callers fail closed. | latest-central static/runtime fail-write test + inherited audit/transaction suites |
| LC-R1-D03 | Medium | File 26 membership projection returned the internal WordPress numeric user ID, unnecessarily widening identity exposure across the search boundary. | Projection now returns File 00's opaque `platform_uuid` from the CF-01 identity contract and omits `user_id`; search/index ownership remains File 26. | opaque-UUID + privacy-minimal projection assertions |
| LC-R1-D04 | Medium | The adulthood transition that ends `guardian_required` changed guardian state but was not explicitly in the central revalidation invalidation list. | Added `guardian_requirement_ended_at_adulthood` to mandatory revalidation/projection invalidation actions. | latest-central static assertion |

### Round-1 result

Four reproducible repository defects were found; all four were corrected before the round was closed. The corrected exact source head passed both independent active GitHub workflow families. No staging/live claim is made.

## Round 2 — Fresh adversarial review and correction

Round 2 restarted from the corrected Round-1 source and independently re-read File 00 ownership, F00-CEN-01/02/03, File 09/File 26 boundaries, audit/session invalidation, privacy-minimal projections, package/release evidence and repository workflow permissions. It did not treat Round-1 green CI as proof that the supply-chain and contract surfaces were complete.

**Corrected exact source head:** `ba5f7c41696958542db9fc32e56ddadee80db4b6`  
**Latest-Central QA run:** `31195819506` — success  
**CF-01/Forty-Round run:** `31195820062` — success across the PHP compatibility matrix  
**Artifact ID:** `9000738362`  
**Deterministic plugin-package SHA-256:** `11b6894ed0988faff3d1a7571e7021089fb9678d44bf73e180927011598c9d8e`  
**Artifact-wrapper SHA-256:** `4f29121701d05d7fffd647db0a6bce380ff2d4891ba075400e4cad7e6b133d24`  
**Core inherited assertions:** 573 PASS / 0 FAIL  
**Latest-central additions:** 25/25 static PASS; 10/10 runtime PASS; all inherited named suites PASS.

### Defects found and corrected

| ID | Severity | Defect found | Correction | Regression evidence |
|---|---:|---|---|---|
| LC-R2-D01 | High | Temporary latest-central/self-correction workflows and mutator scripts remained dispatchable after their job was complete; the stale reconciler could rewrite a later corrected release candidate. | Removed the temporary latest-central apply workflow/script and completed Round-1 patch workflow/script from the release branch. | final workflow-directory gate + exact-head CI |
| LC-R2-D02 | Medium | Four obsolete historical write-authorized GitHub workflows remained active in the current repository even though their 1.2.6/1.2.11 corrective jobs were complete. They expanded the mutable repository surface and could still write old branches. | Retired `apply-ilhami-cycle`, `apply-three-plan-1.2.11`, `apply-three-plan-evidence-1.2.11` and `finalize-three-plan-evidence-1.2.11`; final release keeps read-only QA workflows only. | workflow count/permission assertions in `file00-three-plan-qa.yml` |
| LC-R2-D03 | Low | Current CF-01 QA step still called the deterministic build “1.2.10” although it was executing release 1.2.12. | Corrected the active workflow label to 1.2.12; historical review documents remain unchanged. | exact current workflow review |
| LC-R2-D04 | High | File 26 membership projection and latest-central constitution were exposed through filter-mediated helpers, so a later filter could rewrite File 00's canonical membership/free-tier verdict. | Canonical helper functions now call `SMC_Latest_Central_2026` directly; File 26 can consume the projection but cannot mutate File 00 truth. | latest-central static/runtime canonical-projection assertions |
| LC-R2-D05 | High | The `smc_revalidation_audit_actions` extension point could return a reduced list and thereby delete mandatory F00-CEN-03 age/guardian/consent/security actions. | Filter output can now only extend the immutable File 00 baseline; baseline actions are unioned back after filtering. Runtime test deliberately returns an empty filtered list and proves revalidation still occurs. | 25/25 latest-central static + 10/10 runtime PASS |

### Round-2 post-correction result

The corrected source at `ba5f7c41696958542db9fc32e56ddadee80db4b6` passed both independent workflow families. A fresh post-correction check then verified that the repository's workflow directory contains only the two read-only QA workflows, no current self-mutating apply workflow remains, File 26 receives only the opaque privacy-minimal membership projection, File 09 remains professional-verification truth, donor/payment signals cannot enter membership or ranking projection, and mandatory F00-CEN-03 revalidation cannot be downgraded by an extension filter.

**Round 1 defects found:** 4  
**Round 2 defects found:** 5  
**Rounds with defects:** 2  
**Rounds without defects:** 0  
**All defects discovered in the two required fresh rounds corrected before closure:** Yes  
**Zero known repository defects after two fresh rounds: **Yes**

## Repository completion boundary

The two required fresh corrective rounds are complete for the current File 00 repository scope. The final branch/PR/main exact head must still rerun the immutable read-only QA workflows; if those runs remain green, File 00 may truthfully be called **Specified + Coded + Packaged + Automated-QA Green** for the current central-plan repository scope.

It must **not** be called Staging-Accepted, Live-Deployed or Operational until the separate Hostinger/provider/browser/restore/rollback/Founder-approval gates are actually evidenced.

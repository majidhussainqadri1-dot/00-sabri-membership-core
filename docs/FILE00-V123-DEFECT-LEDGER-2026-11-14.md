# File 00 V12.3 — evidence and corrective-review ledger (superseding report)

Updated: 2026-11-14. **This document explicitly retracts the earlier two asserted File00 source defects.** Do not cite earlier commits or 9/9 CI on an unsafe candidate as an approved fix.

## Verified repository facts
- Frozen File00 `main`: `1fad051c86d530d558be3eb1890d3c6e67bce6e8`, runtime 1.2.44, DB target 1.4.5.
- File00 V12.3 Draft PR #59 contains a 123-ID source/acceptance inventory, reality freeze, PR decision and cross-file test matrix. It is **not** a release.
- Final File00 owner class `class-smc-file09-intake-contract.php` and `MANIFEST.sha256` restored to their exact original main bytes in commit `8b49210c0ae8e8b2dff86be71044b401d0bd0fda`; File00 exact SHA run: **9/9 GitHub Actions success**.
- WordPress Staging/Live active plugin, DB, migrations and deployed checksum **UNVERIFIED**.

## Second review invalidated the initial proposed File00 "fix"
The File00 method `SMC_File09_Intake_Contract::assertion($user_id,...)` returns a **read-only, purpose-limited subject prerequisite**. It does not grant clinical authority, a professional badge, prescription, or patient chart. File09 uses this subject check not only for applicant self-service, but also for independently authorised **reviewer/finalizer on-behalf decisions** (`GDO_Admin::finalize`, `change_state`) and **public doctor-verification status** (`GDO_API::latest_decision`). Those callers are not necessarily logged in as the doctor. The attempted File00 self-only actor binding therefore caused a real **legitimate-use regression** (not previously discovered on focused self-service unit tests).

History: initial red tests at `6f44a2a...` and `a74a1e45...` assumed File00 owner must bind the current actor to subject. This assumption was **invalid for the owner contract**. Source guards at `686bc5a...` / `d7d649d...` were correctly **reverted before merge**; do not treat the prior green runs as demonstrating system safety. A 9/9 CI run at the restored source commit confirms the original contract remains valid. The two initial test failures are **reclassified as wrong contract expectations**, not two confirmed File00 defects.

## Correct canonical owner for the protected action: File09
The private *applicant-only* path is `GDO_Policy::eligibility($user_id,...)`. File09 must bind the current authenticated principal to the requested applicant and recheck after potentially state-changing hooks/reads. File09's genuine read-only candidate check must continue working for authorised reviewers and public verified-status projection. File09 Draft PR #17 is responsible for these new actor-bound tests and source changes; the File00 owner class does not need an unapproved new contract/schema/version.

## Corrected defect counts
| Metric | Number / status |
| --- | --- |
| Confirmed distinct File00 defects from initial actor tests | **0**; two candidate assumptions invalidated |
| Candidate unsafe File00 actor restrictions reverted | **2 conceptual guards**; exact original source/manifest restored |
| File00 source changes pending for this actor issue | **0** |
| File09 applicant-context source defect | **1 confirmed at policy boundary**; separate File09 PR #17 |
| File09 source correction | In Draft PR #17, subject to exact final CI and on-behalf regression |
| File00 main source modifications in this audit | **0** |
| File00 Live resolved incidents | **0** |
| Live deployed runtime/DB/migration/staging | **UNVERIFIED** |

**Critical:** Never infer deployed behavior from repository PR, exact-head CI, GitHub workflow names or historical screenshot. Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

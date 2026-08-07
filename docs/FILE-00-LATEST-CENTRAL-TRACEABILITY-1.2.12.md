# File 00 — Latest Central Implementation Traceability 1.2.12

**Governing date:** 7 August 2026  
**Repository release:** 1.2.12 candidate  
**Database schema:** 1.3.0 (unchanged)  
**Public membership contract:** 1.2.0 (backward-compatible)  
**Latest-central constitution:** 2026-08-07-v1.0  
**File 26 projection contract:** 1.0.0

## Governing precedence applied

1. Definitive Islamic/safety rules and the latest explicit Founder decision.
2. Continuous Value / Global Top-20 Superset, 6 August 2026.
3. Recovered directives, 5 August 2026, where not superseded.
4. Definitive Integrated Master Plan v3.0, where not superseded.
5. File 00 Four-Round Reviewed Final plus its 7 August 2026 governing addendum.
6. Runtime evidence establishes implementation status only and never silently changes scope.

## Latest-central delta closure

| ID | Governing requirement | Repository implementation | Automated evidence | External evidence |
|---|---|---|---|---|
| F00-CEN-01 | Single-free-tier only; no checkout/paid renewal/premium/donor privilege authority | `smc_policy()` and entitlement assertions expose free baseline/single-free-tier; paid unlock and legacy pricing are false | `qa/latest-central-contract.mjs`, prior entitlement runtime tests | real cross-module consumer acceptance pending |
| F00-CEN-02 | Donation logically separate from membership/capability/rank/verification/support | donation-affects-* values false; File 26 projection hard-codes no donor/payment ranking signal | latest-central static/runtime + existing three-plan tests | donor/non-donor end-to-end AJ-25 pending staging |
| F00-CEN-03 | age/guardian/consent change rechecked on next protected action with session/cache invalidation | protected actions already re-read `SMC_Contracts::assertions`; durable audit changes advance `_smc_revalidation_required_at`; current 2FA must be newer; existing restriction/contact/guardian paths revoke sessions | latest-central static/runtime + authorization/lifecycle suites | real browser/provider journey pending |
| CV-001–013 | identity/account/verification/recovery/consent/data-rights baseline | existing File 00 lifecycle retained; latest delta adds File 09 canonical doctor-claim boundary and File 26 projection | complete existing File 00 suites + latest-central suite | provider/browser/staging acceptance pending |
| CV-239–245 | localization, RTL/LTR, WCAG/reflow/low bandwidth | File 00 logical CSS and reduced-motion/mobile contracts retained; exact central green fallback corrected | CSS/static contracts | real assistive-tech/slow-network acceptance pending |
| CV-262–280 | zero-trust, encryption, secure SDLC, DR/observability/release/two-review law | native File 00 security/QA/backup/release controls retained; two fresh post-correction reviews required before merge | repository CI + review ledger | restore/load/Hostinger operational evidence pending |
| File 26 | Search/Discovery/Ranking owns index/ranking; File 00 only supplies identity projection | `smc_file26_membership_projection_v1`; no File 26 table/query/index is created by File 00; projection is privacy-minimal and fail-closed | latest-central static/runtime | consumer runtime acceptance pending |
| Sabri Green | central primary `#087A4E`, File 25 owns global visual tokens | exact File 00 fallback is `#087A4E`; File 25 token can override | latest-central static test | visual/browser acceptance pending |
| File 09 | professional verification truth belongs to File 09 | versioned `smc_file09_doctor_verification_claim_v1` + installed `SPD_Helpers`; stale `_spd_verification_status` fallback removed | latest-central static + full regression | real File 09 consumer/provider acceptance pending |

## Acceptance journeys relevant to File 00

- **AJ-02:** truthful email/mobile verification, privacy/language choice and device/session visibility — repository path exists; real providers/browser remain external.
- **AJ-24:** donation appeal ≥7 days, no preselected/recurring default — donor UI/delivery is a cross-owner staging acceptance item; File 00 entitlement remains neutral.
- **AJ-25:** donor and non-donor receive equal features/rank/badge/support — File 00 emits no donor privilege and File 26 projection carries no donation/payment signal.
- **AJ-31/AJ-32:** accessibility and RTL/LTR — automated contracts exist; real assistive-tech acceptance remains pending.
- **AJ-34:** MFA/recovery/session control — File 00 session/recovery controls exist; real-device alert/provider acceptance remains pending.
- **CV-280:** two fresh review/fix/retest rounds are mandatory before release; zero known repository defects is the merge gate.
- **AJ-35:** export/delete/retention exception — native privacy implementation retained; WordPress/Hostinger staging proof pending.

## Truthful status boundary

| Status | Current 1.2.12 state |
|---|---|
| Specified | complete for File 00 latest-central repository scope |
| Coded | complete after the 1.2.12 corrective commit |
| Packaged | proven only by deterministic exact-head CI artifact/checksum |
| Automated-QA Green | proven only when exact-head CI succeeds |
| Staging-Accepted | pending |
| Live-Deployed | pending |
| Operational | pending |

No staging, production, live or operational claim is made by this document.

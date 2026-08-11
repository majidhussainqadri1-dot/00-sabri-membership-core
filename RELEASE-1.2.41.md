# File 00 — Sabri Membership Core 1.2.41

## Repository-only supplemental ten-round corrective release

Baseline: merged `main` `2a596b9b342a448a994be8a7452d113ee8f50825` (File 00 1.2.40).

This release records the supplemental ten-round review requested on 11 August 2026. Every proven defect was corrected before the next review lens proceeded.

### Defect rounds

- **Round 1:** `smc_revoke_all_sessions` was missing from the exact membership/security recovery allowlist. A hard-blocked or ineligible account could reach single-session revocation but could be redirected before revoke-all. The canonical authorization boundary now explicitly preserves both recovery actions and permanent static/runtime regressions cover the behavior.
- **Round 4:** protected authorization could rely on persisted approval until the daily lifecycle age sweep reconciled a changed age/jurisdiction condition. Protected authorization and the versioned public assertion surface now synchronously recompute current age eligibility, jurisdictional minimum age and the 18+ professional-role requirement and fail closed.
- **Round 5:** appeal independence was not enforced at claim entry. The actor responsible for the latest rejection or suspension could reclaim an appeal before the later restore-decision safeguard. The canonical authorization boundary now prevents that actor from claiming or deciding the appeal.
- **Round 10:** release closure exposed repository/QA consistency defects after the functional corrections: the committed deterministic manifest was stale, corrected source was still colliding with the already-verified 1.2.40 release identity, and active current-release QA/workflows still contained 1.2.40 expectations. The corrected candidate was advanced to 1.2.41; runtime/package/readme/manifest/current QA/current workflows/master-plan/release identity were aligned; the combined permanent test gate remains fail-closed and must be green on the exact final head.

### No-new-defect rounds

Rounds **2, 3, 6, 7, 8 and 9** produced no newly proven repository defect after the preceding corrections.

## Current contracts

- Runtime: `1.2.41`
- Database schema target: `1.4.5`
- Public membership contract: `1.2.3`
- CF-01 membership-assurance contract: `1.1.0`
- Advanced Trust contract: `1.0.0`
- File 00 MFA owner: `none` under Founder change-control dated 10 August 2026

## Repository acceptance boundary

Repository acceptance requires the exact final candidate SHA to pass combined `npm test`, PHP lint, WordPress 7.0.1 + MariaDB 11.4 migration/runtime regressions, deterministic package verification, and installable artifact production. A prior green SHA is not evidence for a later head.

## Live boundary

This file is not evidence of deployment. Live/staging code, database/schema version, migration state, active configuration and real workflows remain separate realities. Live may be called resolved only after the exact deployed build and database state are frozen, deployment parity is confirmed, and the relevant workflows are re-tested on the deployed environment.

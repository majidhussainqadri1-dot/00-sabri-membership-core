# File 00 — V12.3 Source Status (15 November 2026)

| Truth surface | Value / status |
|---|---|
| Main HEAD | `1fad051c86d530d558be3eb1890d3c6e67bce6e8` (repository truth only) |
| Draft owner PR | #61, `audit/file00-independent-profile-age-v123-20261114` (unmerged; exact SHA read from PR on each review) |
| Plugin candidate | `1.2.46` (new unique package identity; historic main `1.2.44`) |
| DB schema declaration | `1.4.5` (no migration introduced) |
| Contract declaration | `1.2.3` + additive `smc_profile_age_guardian_claim_v1` |
| Legacy File00 MFA | Retired; authentication remains File02-owned |
| Staging acceptance | NOT RECORDED |
| Deployed Version / package hash | UNKNOWN / UNVERIFIED |
| Live DB Version / migration | UNKNOWN / UNVERIFIED |
| Live Verification Status | NOT EXECUTED |
| Production decision | NO-GO |

## Source changes

- File 00 produces independently scoped, fresh age/guardian assurance from canonical encrypted DOB without calling `assertions()` during `smc_public_profile_opt_in`, avoiding recursive authorization.
- File 03 PR #43 uses only a strict, subject-bound, owner-bound `age_band` claim and rejects legacy numeric age and misleading `is_minor=false` claims.
- Candidate QA includes real disposable WordPress/MariaDB checks for adults, minors, unknown age and File00 erasure-lock containment. The source Build Manifest is regenerated from modified bytes.
- Unique candidate identity is essential: do not install changed files under the unchanged `1.2.44` version.

## Remaining blockers

The connected WordPress/Jetpack site remains disconnected and a WPVibe connection attempt returned HTTP 530. Neither identifies a proven host root cause. Real installed files, DB, migrations, logs, browser paths, backup restore, rollback, independent approval and live re-test are not verifiable from GitHub.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

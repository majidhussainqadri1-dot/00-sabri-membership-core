# File 00 — Source Release Candidate 1.2.46 (V12.3 File 03 compatibility)

**Status:** DRAFT SOURCE CANDIDATE, NOT STAGING-ACCEPTED OR LIVE-VERIFIED.

## Scope
- Integrates the V12.3 current-age/guardian eligibility correction with the independent File 03 age claim; the old registration-era guardian flag is not authorization evidence.
- Adds File 00-owned inverse platform UUID resolution, erasure-lock denial, and the File 04 non-login placeholder provider bound to owner metadata.
- Hardens trust-timeline cross-subject authorization with the exact trust_timeline_read purpose and step-up action.
- Adds the 123-requirement inventory/source evidence, twenty-round review record, and exact File02/File03/File04/File08 consumer checks.
- New unique plugin release identity: `1.2.46`, distinct from the previously documented `1.2.44` and other unmerged 1.2.45 proposals package. Never silently overwrite a deployed `1.2.44` package.
- File 00 canonical ownership remains unchanged: identity, encrypted DOB, age/guardian policy, membership eligibility, revocation and erasure locks.
- Add independent read-only `smc_profile_age_guardian_claim_v1` for File 03’s `smc_public_profile_opt_in` consumer, called without recursively reentering `SMC_Contracts::assertions()`.
- Strongly minimize the assertion to subject, owner, purpose, contract version, freshness, known/minor age band and guardian requirement; no exact age, DOB, medical data or identity evidence.
- Fail closed for unknown age, invalid account, privacy-erasure lock, invalid canonical DOB or malformed owner policy.
- Add real WordPress + MariaDB adult/minor/unknown regression, including encrypted DOB and negative behavior.

## Version boundaries
| Identity | Declared source candidate |
|---|---|
| Plugin header and SMC_VERSION | `1.2.46` |
| SMC_DB_VERSION | `1.4.5` (unchanged) |
| SMC_CONTRACT_VERSION | `1.2.3` (unchanged) |
| Cross-file purpose-bound claim | `smc_profile_age_guardian_claim_v1` (additive) |

## Review and CI gates
1. The legacy eligibility runtime fixture now supplies current encrypted-DOB-equivalent test data; it must not be weakened to bypass the current age policy.
2. File04 must accept only the exact File00 placeholder provider identity and verify UUID-to-user reverse mapping.
3. File08 reverse UUID lookup must fail closed for duplicates, missing users and privacy-erasure locks.
4. File03's age assurance provider must remain independent of recursive membership assertions and must not disclose DOB or numeric age.

## Required hard gates
1. Exact candidate SHA freeze and plugin MANIFEST.sha256 parity. CI must compile, verify current source digests, build a deterministic `1.2.46` ZIP and prove checksum and archive contents.
2. Pair this File 00 candidate with a File 03 candidate that refuses legacy unscoped numeric age records; test actual WordPress adult/minor/unknown/erasure paths.
3. Obtain independent review. No automatic promotion merely because CI passes.
4. On staging freeze actual installed File00/File03 packages, schema/options/migration state and source/package parity. Test all mandatory File03 acceptance cases.
5. Backup, **restore**, rollback rehearsal, Founder acceptance, controlled deployment and a fresh live re-test are mandatory before saying "Resolved".

**Required truth:** Repository HEAD and candidate SHA are source-only. **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

# File 00 — Source Release Candidate 1.2.45 (V12.3 File 03 compatibility)

**Status:** DRAFT SOURCE CANDIDATE, NOT STAGING-ACCEPTED OR LIVE-VERIFIED.

## Scope
- New unique plugin release identity: `1.2.45`, distinct from the previously documented `1.2.44` package. Never silently overwrite a deployed `1.2.44` package.
- File 00 canonical ownership remains unchanged: identity, encrypted DOB, age/guardian policy, membership eligibility, revocation and erasure locks.
- Add independent read-only `smc_profile_age_guardian_claim_v1` for File 03’s `smc_public_profile_opt_in` consumer, called without recursively reentering `SMC_Contracts::assertions()`.
- Strongly minimize the assertion to subject, owner, purpose, contract version, freshness, known/minor age band and guardian requirement; no exact age, DOB, medical data or identity evidence.
- Fail closed for unknown age, invalid account, privacy-erasure lock, invalid canonical DOB or malformed owner policy.
- Add real WordPress + MariaDB adult/minor/unknown regression, including encrypted DOB and negative behavior.

## Version boundaries
| Identity | Declared source candidate |
|---|---|
| Plugin header and SMC_VERSION | `1.2.45` |
| SMC_DB_VERSION | `1.4.5` (unchanged) |
| SMC_CONTRACT_VERSION | `1.2.3` (unchanged) |
| Cross-file purpose-bound claim | `smc_profile_age_guardian_claim_v1` (additive) |

## Required hard gates
1. Exact candidate SHA freeze and plugin MANIFEST.sha256 parity. CI must compile, verify current source digests, build a deterministic `1.2.45` ZIP and prove checksum and archive contents.
2. Pair this File 00 candidate with a File 03 candidate that refuses legacy unscoped numeric age records; test actual WordPress adult/minor/unknown/erasure paths.
3. Obtain independent review. No automatic promotion merely because CI passes.
4. On staging freeze actual installed File00/File03 packages, schema/options/migration state and source/package parity. Test all mandatory File03 acceptance cases.
5. Backup, **restore**, rollback rehearsal, Founder acceptance, controlled deployment and a fresh live re-test are mandatory before saying "Resolved".

**Required truth:** Repository HEAD and candidate SHA are source-only. **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

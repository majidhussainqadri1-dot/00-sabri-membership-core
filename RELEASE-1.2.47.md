# File 00 — Integrated Source Release Candidate 1.2.47 (V12.3)

**Status:** DRAFT SOURCE CANDIDATE. Not merged, staging-accepted, Hostinger-deployed or live-verified.

## Why 1.2.47
This candidate reconciles the previously separate, unmerged File00 age/guardian enforcement work and File03 nonrecursive age-assurance provider. It supersedes the separate unmerged 1.2.46 proposal and the unversioned 1.2.44 source-change candidate. It does not silently overwrite any deployed package.

## Integrated source scope
1. **Current age and guardian eligibility (F00-CEN-03):** re-decrypt current DOB at protected decision time; apply the approved sex-specific baseline and stricter jurisdictional minimum; unknown, malformed or undecryptable DOB fails closed; a guardian cannot override the minimum age; professional roles remain adult-only; current guardian consent is checked for eligible minors.
2. **Independent File03 age assurance:** add the internal PHP smc_profile_age_guardian_claim_v1 projection. It does not recursively call SMC_Contracts::assertions(). It discloses only a short-lived subject-bound age band, minor/guardian flags, owner/purpose and versioned File03 consumer contract; never exact age, DOB, medical data or identity evidence. It denies unknown/invalid consumers, missing subjects and privacy-erasure locks.
3. **Advanced-trust hardening:** cap reverification intervals at one year; do not let persisted due dates extend the policy ceiling; require typed, short-lived, subject/source-bound authorization for system reverification; require step-up and a purpose-bound owner claim for cross-subject trust-timeline access; recheck the current WordPress principal after reads; require explicit authenticated actor identity even for empty-capability flows.
4. **Canonical subject resolution:** expose a read-only internal UUID-to-WordPress-user resolver that requires one unique valid canonical UUID match. It grants no membership, guardian, professional, appointment or clinical permission.
5. **Legacy author isolation:** reserved File04 legacy-author sentinel accounts are not imported as ordinary membership applicants.
6. **Evidence and QA:** retain the V12.3 123-ID inventory, source-pointer checks, negative/positive runtime fixtures, File08 inverse-subject consumer tests, File09 intake boundary tests and deterministic package build.

## Version boundaries
| Identity | Candidate value |
|---|---|
| Plugin header and SMC_VERSION | 1.2.47 |
| SMC_DB_VERSION | 1.4.5 — unchanged |
| SMC_CONTRACT_VERSION | 1.2.3 — unchanged |
| File03 minimum consumer contract | 1.4.0 |
| File03 age claim | smc_profile_age_guardian_claim_v1 |
| Database migration | None introduced by this candidate |

## Exact source review and integration requirements
- File00's age provider must remain read-only and nonrecursive; File03 must validate consumer=file03, the exact current consumer contract, owner, purpose, subject, freshness and no-DOB shape.
- File08 UUID lookup is identity mapping only; the consuming action must separately authorize the actor, subject, object and purpose.
- File09's read-only subject prerequisite must remain usable by authorized reviewers/finalizers and public verification; applicant-only writes remain File09 actor-bound.
- The legacy author sentinel behavior must be paired with File04's canonical sentinel lifecycle; do not infer full File04 acceptance from the File00 guard alone.
- All overlapping PRs and manifests must be reconciled against exact current main before merge. No blind merge or patch stacking.

## Mandatory release gates
1. Freeze exact candidate SHA; fresh exact-head CI must pass after the final change.
2. Rebuild deterministic 1.2.47 ZIP; regenerate and verify MANIFEST.sha256; record archive SHA-256 and package contents.
3. Run genuine disposable WordPress/MariaDB activation, migration, current-age, guardian, erasure, restore, rollback and cross-file tests on the exact candidate.
4. Run the paired File03 seven-module WordPress/MariaDB integration using this exact File00 SHA and the exact File03 candidate SHA.
5. Obtain two post-coding reviews and independent security/privacy review; resolve every critical/high finding.
6. On authorized staging, freeze installed plugin versions/checksums, DB/schema, relevant tables/columns/indexes, wp_options migration state, runtime logs and dependencies. Any mismatch triggers Deployment-Parity Audit before code diagnosis.
7. Complete the 123 requirement-specific positive/negative acceptance records and all applicable cross-file/browser/RTL/accessibility cases. Inventory coverage is not individual acceptance.
8. Verify backup and execute restore/rollback rehearsal. Founder promotion approval precedes controlled deployment.
9. After deployment, re-read the exact deployed package/checksum, DB and migration state and perform live re-tests. Only then may a defect be called resolved.

## Current truth
- Repository branch: audit/file00-v123-integrated-1.2.47-20261124
- Plugin source version: candidate 1.2.47
- DB target: 1.4.5; deployed DB version remains unverified
- Migration state: unverified
- Hostinger deployed version/checksum: unverified
- Staging acceptance: not established
- Live verification: not performed

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

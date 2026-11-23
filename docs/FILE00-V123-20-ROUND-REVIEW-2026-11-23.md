# File 00 V12.3 — Twenty-Round Independent Review
Date: 2026-11-23
Candidate: integrated V12.3 review branch based on the 1.2.46 source candidate.
Scope: source, tests, release identity, and exact companion-source contracts. This report is not Staging or Live acceptance.

## Review results

| Round | Review domain | Finding / disposition | State |
|---:|---|---|---|
| 01 | V12.3 requirement traceability | The register contains 123 IDs (100 R, 3 CEN, 20 EXT). The source-evidence script identifies 103 source-backed signals; this is not equivalent to 123 runtime acceptances. | PARTIAL |
| 02 | Canonical ownership | File 00 remains the owner of membership, identity, encrypted DOB, guardian consent, eligibility, UUID and revocation assertions. Consumer plugins must not infer these from roles or local metadata. | PASS |
| 03 | Membership lifecycle | The current assertion path is checked synchronously; the stale registration-era guardian flag is no longer sufficient to authorize a minor. | PASS |
| 04 | Age and jurisdiction | Current decrypted DOB, the effective jurisdictional minimum, and the 18+ professional floor are evaluated at the protected assertion boundary. Unknown DOB fails closed for ordinary accounts. | PASS |
| 05 | Guardian consent | A minor requires current verified guardian consent; withdrawal blocks the next assertion without waiting for a daily sweep. An adult is not indefinitely bound by an obsolete minor flag. | PASS |
| 06 | Contact and identity prerequisites | The ordinary-account regression fixture now carries explicit current DOB evidence; contact ownership and current identity evidence remain separate prerequisites. | PENDING CI |
| 07 | Privacy and data minimization | The File 03 age projection returns only subject-bound age band, known/minor/guardian booleans, owner, purpose, version and freshness; it does not return DOB or numeric age. | PASS |
| 08 | File 03 profile consumer | The independent age provider avoids recursion into the assertion method and denies unknown/minor/erasure-locked subjects for public-profile age eligibility. Exact integrated WordPress/MariaDB run is pending. | PENDING CI |
| 09 | File 02 authentication consumer | The exact pinned File 02 1.3.3 compatibility path is retained. The old test fixture that omitted required DOB was corrected rather than weakening the age policy. Exact integrated workflow is pending. | PENDING CI |
| 10 | File 04 legacy authorship | The placeholder is identified by File 00-owned metadata, not a guessed login string. It is excluded from membership registration, blocked from authentication, and the consumer now requires the exact provider plus reverse UUID and placeholder identity. | PENDING CI |
| 11 | File 08 reverse subject consumer | The resolver returns only one existing File 00 UUID owner, rejects duplicates/malformed IDs, and denies subjects under privacy-erasure lock. Exact integrated consumer workflow is pending. | PENDING CI |
| 12 | File 09 intake and actor binding | Subject read-only prerequisites are distinguished from actor-bound mutations; an arbitrary or different current principal cannot borrow another user's actor ID. | PENDING CI |
| 13 | Advanced trust and reverification | The annual maximum is enforced and system reverification requires a fresh typed claim. The external system-authority provider is not yet present in File 01/File 24, so system action remains fail-closed. | PARTIAL |
| 14 | Trust timeline access | Cross-subject access now requires the exact trust_timeline_read purpose and matching step-up action. No external claim provider is currently installed; cross-subject access therefore remains fail-closed. | PARTIAL |
| 15 | CF-01 subject resolver | Lookup is read-only, detects ambiguous UUID ownership, verifies the stored UUID and refuses privacy-erasure-locked accounts. It grants no clinical authority. | PENDING CI |
| 16 | Database and schema ownership | The candidate does not change the DB schema version (1.4.5); no new migration is introduced by the reviewed source changes. | PASS |
| 17 | Migration, rollback and restore | Source changes do not authorize a production migration. Staging backup, restore rehearsal, rollback and deployed schema/options inspection remain separate gates. | PARTIAL |
| 18 | Package and manifest integrity | Source manifest entries have been recomputed for the modified package files; deterministic package build and archive verification must pass on the final candidate SHA. | PENDING CI |
| 19 | Cross-file contract parity | Exact pinned File 02, File 03, File 04 and File 08 source consumers are wired as separate gates; a green source contract does not prove installed plugin parity. | PARTIAL |
| 20 | Staging and Live acceptance | Hostinger deployed package, runtime, DB/schema, migration state, logs, configuration and checksum have not been frozen in this audit. No Live fix or resolution is claimed. | BLOCKED |

## Confirmed corrections in this review
1. Replaced stale-flag age/guardian authorization with current DOB-derived age and guardian requirements.
2. Added the independent File 03 age-assurance projection without recursive assertion calls or DOB disclosure.
3. Replaced the incorrect File 04 login-name sentinel with the owner-set _smc_legacy_author_placeholder_v1 metadata contract.
4. Removed trust of earlier unowned filter values in File 00's File 04 UUID and placeholder providers.
5. Added inverse UUID verification for File 08/04 consumers, placeholder-identity proof, and privacy-erasure-lock denial.
6. Restricted cross-subject trust-timeline claims to the exact trust_timeline_read purpose.
7. Corrected the legacy eligibility test fixture to supply the current DOB evidence now required by policy.

## Acceptance interpretation
- Twenty review domains have been examined; this does not mean all twenty are accepted.
- Repository tests, exact companion-source tests, Staging acceptance and Live acceptance are separate.
- No deployment is authorized by this report.

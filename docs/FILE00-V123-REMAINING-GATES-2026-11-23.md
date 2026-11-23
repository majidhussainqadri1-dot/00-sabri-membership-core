# File 00 V12.3 — Remaining Acceptance Gates
Date: 2026-11-23
Candidate identity: source candidate 1.2.46; DB 1.4.5; membership contract 1.2.3.

## Repository work
- Integrate the age/guardian correction, File 03 age projection, CF-01 UUID resolver, File 04 metadata-bound placeholder, and advanced-trust hardening on one exact candidate SHA.
- Correct the obsolete eligibility test fixture so tests reflect the current DOB policy rather than weakening the policy.
- Recompute every affected source digest in source/sabri-membership-core/MANIFEST.sha256.
- Run all exact-HEAD workflows after the final commit; record each workflow and failed step.
- Do not merge PR #59 or #61 blindly. They are separate draft candidates based on the same old Main SHA and overlap in source and release metadata.

## Cross-file gates
- File 02: pinned 1.3.3 account-contract consumer; current age-aware test fixtures.
- File 03: pinned profile consumer; adult/minor/unknown/erasure age contract.
- File 04: exact provider identity and reverse UUID mapping for normal and placeholder authors.
- File 08: exact reverse subject resolver consumer; mismatch, duplicate, unavailable and erasure-lock cases.
- File 09: actor-bound mutation versus subject-bound read-only prerequisite.
- File 01/File 24: provide or explicitly retire the typed system reverification and trust-timeline access providers. Until then those cross-subject/system operations must remain denied.

## Deployment gates (not completed)
1. Hostinger Live/Stage reality freeze: installed plugin/runtime version, exact deployed package and checksum, WordPress/PHP/dependencies, DB/schema/tables/columns/rows, wp_options and migration state, logs and active configuration.
2. Prove deployment parity between the exact GitHub candidate and the deployed artifact. If parity differs, stop and perform deployment-parity audit before source debugging.
3. Staging install with real plugin order and current database; verify schema, migration, permissions, real role journeys and cross-plugin contracts.
4. Backup and restore rehearsal, rollback rehearsal, and Founder acceptance.
5. Controlled deployment, then fresh Live retest. Only then may a defect be called Resolved.

## Required final truth fields
- Repository HEAD: exact candidate SHA (source truth only).
- Deployed Version: unverified until host evidence is captured.
- DB Version: source candidate declares 1.4.5; installed DB remains unverified.
- Migration State: source candidate tests only; installed state unverified.
- Live Verification Status: NOT VERIFIED.

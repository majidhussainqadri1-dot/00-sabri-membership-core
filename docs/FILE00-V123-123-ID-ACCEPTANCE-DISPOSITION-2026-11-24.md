# File 00 V12.3 — 123-ID acceptance disposition (2026-11-24)

This matrix summarizes the existing PR #59 inventory. It does not turn historical code/test labels into accepted behavior.

| Requirement group | IDs | Scope | Current evidence disposition |
|---|---|---|---|
| Governance | F00-R001–R010 | source hierarchy, governance, scope, change control | source pointers are historical; exact current symbol/runtime acceptance unverified |
| Architecture/contracts | F00-R011–R020 | layers, dependencies, fail-safe, File00/File02 boundary, authorization | candidate source files exist; per-ID runtime acceptance unverified |
| Membership operations | F00-R021–R030 | lifecycle, classes, registration, age, guardian, founder/admin, reviewer, dual approval, suspension/appeal/erasure | source paths exist; full state/role/age/guardian workflow acceptance unverified |
| Identity/security | F00-R031–R040 | identity assurance, private evidence, cryptography, malware, contacts, MFA, sessions, recovery, lifecycle rechecks, consumer assertions | implementation files exist; adversarial and operational proof incomplete |
| Data/privacy | F00-R041–R050 | canonical model, integrity, classification, visibility, consent, holds, export, erasure, audit, migration | implementation files exist; staged data lifecycle acceptance unverified |
| Cross-file integration | F00-R051–R060 | Files 02,03,08/18,09,17,19,20,21/22/23,24,25 | consumer matrix exists; multiple paired PRs remain draft/conflicted/unaccepted |
| UX/browser | F00-R061–R070 | routes, UX, status/security/guardian/reviewer UX, responsive, accessibility, RTL, weak network/offline | source cannot prove device/browser acceptance; staging/device evidence unverified |
| Threat/adversarial | F00-R071–R080 | threat model, IDOR, CSRF/replay, injection/XSS, filesystem, OTP/TOTP, session/privilege, insider abuse, key loss/corruption, supply chain | test obligations exist; full exact-build adversarial acceptance unverified |
| Operations/release | F00-R081–R090 | observability, jobs, backup/restore, safe mode, performance, API security, package, release phases, runtime lineage, service levels | source and manifest exist; deployment and operational proof unverified |
| QA/acceptance | F00-R091–R100 | automated baseline, fresh activation, upgrade/migration, regression, integration, security, browser, staging, release evidence, founder acceptance | source pointers/test suites exist; no complete final-candidate acceptance |
| Central | F00-CEN-001–003 | exact plan authority, publication and release governance | plan-level authority; implementation acceptance unverified |
| Extension | F00-EXT-001–020 | advanced trust, revocation, contract negotiation, selective disclosure, delegation, service identities, lifecycle, user trust timeline | prior source pointers only; method-specific source/runtime acceptance unverified |

## Per-ID rule
For each of the 123 IDs, acceptance requires:
- exact current source path + symbol on frozen SHA;
- positive and negative runtime test on exact package;
- paired consumer test where applicable;
- staging evidence for schema/migration/device/runtime requirements;
- live evidence only where the requirement concerns deployed operation;
- named owner, evidence timestamp, result and defect/repair lineage.

**No percentage completion is assigned** until the exact current symbols and tests are independently enumerated. The existing 123-row JSON inventory remains the canonical ID list.

## Updated exact-head evidence (File00 PR #66, SHA edfb91abdcbf6870d3981bba4e223084d6c43c13)
- 123 unique requirement IDs are inventoried; 493 source-pointer occurrences were checked.
- R011–R090 explicit implementation-source mappings: 80/80 PASS.
- Current exact source evidence signals: 103/123.
- Independent requirement-specific acceptances: **0/123**.
- F00-CEN-03 current-age/guardian runtime: 8/8 PASS.
- Actor-boundary runtime: 15/15 PASS.
- T38 canonical subject resolver fixtures: 13/13 PASS.
- Real WordPress 7.0.1 + MariaDB 11.4.13 audit32: 45 PASS / 0 FAIL.
- These tests improve evidence coverage but do not convert inventory rows into acceptance. Remaining 20 IDs without source evidence signals and all 123 independent acceptance records must be reconciled against their exact criteria and consumers.

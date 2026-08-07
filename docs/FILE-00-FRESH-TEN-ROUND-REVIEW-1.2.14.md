# File 00 — Fresh Ten-Round Corrective Review — Release 1.2.14

Fresh ten-round review complete: **Yes**

Date: 7 August 2026

Baseline reviewed: `main` at `5fc291fb4de3ecc03b5e544553503646cb5ae99c` (Release 1.2.13).

Release under closure: **1.2.14**. Database schema remains **1.3.0**; public membership contract remains **1.2.0**; Advanced Trust contract remains **1.0.0**.

## Governing boundaries retained

- File 00: membership and identity assurance.
- File 02: authentication/passkey ceremonies.
- File 09: professional verification truth.
- File 24: security/risk assurance.
- File 26: search/discovery/ranking.
- Single complete free tier; donor-neutral; zero commission; Sabri Green `#087A4E`.

## Fresh review rounds

| Round | Focus | Defects | IDs | Result |
|---:|---|---:|---|---|
| 1 | periodic reverification and protected-action freshness | 1 | FR-R1-D01 | Corrected |
| 2 | revocation epoch concurrency | 1 | FR-R2-D01 | Corrected |
| 3 | selective-disclosure purpose and revocation freshness | 2 | FR-R3-D01, FR-R3-D02 | Corrected |
| 4 | containment/continuity transition atomicity | 2 | FR-R4-D01, FR-R4-D02 | Corrected |
| 5 | non-human identity and emergency-authority binding | 2 | FR-R5-D01, FR-R5-D02 | Corrected |
| 6 | File 09 professional-claim provenance and freshness | 1 | FR-R6-D01 | Corrected |
| 7 | overdue sweep propagation and retry-safe cursor | 2 | FR-R7-D01, FR-R7-D02 | Corrected |
| 8 | emergency-governance mutex concurrency | 1 | FR-R8-D01 | Corrected |
| 9 | cross-path protected-action authorization coherence | 3 | FR-R9-D01, FR-R9-D02, FR-R9-D03 | Corrected |
| 10 | final architecture, privacy, package and supply-chain closure | 1 | FR-R10-D01 | Corrected |

**Rounds with defects:** 1, 2, 3, 4, 5, 6, 7, 8, 9, 10.

**Rounds without defects:** none.

**Unique product/repository defects found:** 16.  
**Unique defects corrected:** 16/16.  
**Severity:** 1 Critical, 12 High, 3 Medium, 0 Low.  
**Known unresolved repository defects after Round 10:** 0.

## Corrective details

### FR-R1-D01 — High
Periodic reverification could remain effectively usable until the daily sweep ran, and existing approved members had no approval-derived reverification baseline. The corrected source derives a baseline from the latest approved role grant/application and synchronously blocks stale applicable reverification at the protected-action boundary.

### FR-R2-D01 — High
Revocation epoch increment used a read/modify/write sequence that could collide under concurrent security mutations. The corrected source serializes per-subject epoch mutation with a database advisory lock and verifies persistence before emitting the privacy-minimal invalidation event.

### FR-R3-D01 / FR-R3-D02 — High / High
Selective-disclosure proofs were audience-bound but not purpose-bound, and requested proof validity could outlive the 60-second revocation propagation SLA. Proof v1.1 is now audience + purpose bound, cryptographically covered, and constrained to the effective revocation window.

### FR-R4-D01 / FR-R4-D02 — High / High
A containment or continuity relaxation could persist its less-restrictive state before a later session/audit/revocation failure. A fail-closed transition hold now starts before the mutation and is cleared only after all required security evidence and propagation complete.

### FR-R5-D01 — Medium
A previously declared service identity with `approved=false` could fall back to human classification. Service identity kind is now sticky as non-human; approval is a separate state.

### FR-R5-D02 — High
Emergency authority could be opened with an empty purpose and the consumed authority result did not carry subject/purpose binding. Both are now mandatory and returned as bounded authorization context.

### FR-R6-D01 — High
An explicit File 09 professional-verification array could be accepted without strict owner, contract, explicit current state, assertion freshness and expiry. Explicit claims are now typed and fresh or fail closed; the installed canonical compatibility adapter remains available when no explicit claim is supplied.

### FR-R7-D01 — High
The overdue reverification sweep could set a local hold without mandatory audit + revocation propagation, allowing downstream caches to remain stale. First transition to overdue now emits both durable audit evidence and a revocation invalidation.

### FR-R7-D02 — Medium
The reverification sweep cursor was not persistence-verified/retry-safe. It now advances only through successfully processed subjects and preserves the last safe cursor on failure.

### FR-R8-D01 — Critical
The former option-based emergency-governance mutex had a stale-lock deletion race in which a competing worker could delete another worker's newly acquired lock. Emergency dual-control governance now uses a serialized database advisory mutex.

### FR-R9-D01 — High
A File 02 elevated authentication assertion could be fresh in general while still predating File 00's newer `_smc_revalidation_required_at` boundary. Elevated File 02 assurance is now rejected unless its verified timestamp satisfies the local revalidation boundary.

### FR-R9-D02 — High
The direct protected-action/step-up path did not itself make the local revalidation timestamp part of the operational authorization verdict. Protected actions and `step_up_requirement().satisfied` now require current post-boundary authentication as well as containment/continuity/reverification/critical-change/merge safety.

### FR-R9-D03 — Medium
The boolean delegated-scope authorization helper could return true for a principal whose membership was currently protected/restricted. Active grant inventory remains inspectable, but authorization via `has_delegated_scope()` is suspended whenever the principal is not operational.

### FR-R10-D01 — High
Release/runtime/package metadata had moved to 1.2.14, but `tools/build.py` still hard-coded `VERSION = "1.2.13"`, so the deterministic builder emitted a 1.2.13 archive and the exact 1.2.14 verifier correctly failed. The builder now derives the release version directly from the plugin header and `SMC_VERSION`, requires both to exist and match, and names the deterministic archive from that canonical runtime identity. This removes the stale-version class of packaging defect instead of merely changing one hard-coded number.

## Regression evidence added or strengthened

The active test chain now covers, among other inherited suites:

- expired approval baseline synchronous denial;
- recent approval baseline acceptance;
- strictly monotonic serialized revocation epochs;
- purpose-bound/revocation-fresh selective disclosures;
- containment and continuity relaxation failure remaining blocked;
- malformed/stale/fresh File 09 claims;
- overdue sweep hold + revocation propagation;
- emergency database advisory serialization;
- disabled service identity remaining non-human;
- emergency subject/purpose binding;
- File 02 assertions predating the local revalidation boundary;
- direct protected-action revalidation enforcement;
- step-up denial for non-operational membership;
- delegated-scope suspension while protected;
- deterministic package filename derived from canonical runtime identity.

## Exact green branch evidence

The pre-evidence branch head `2bd99579d6907570faff88103dbb9a01680ce0ee` passed both read-only workflow families:

- File 00 1.2.14 Fresh-Ten-Review QA — run `31211441087` — success.
- File 00 CF-01 and Forty-Round Contract Integrity — run `31211441109` — success.
- Core assertions: 575 PASS / 0 FAIL.
- Advanced Trust static: 31 PASS / 0 FAIL.
- Advanced Trust runtime: 25 PASS / 0 FAIL.
- Fresh hardening runtime: 26 PASS / 0 FAIL.
- File 09 professional claim runtime: 4 PASS / 0 FAIL.
- Deterministic plugin ZIP SHA-256: `d62aac41ec8b752d151b1846196ed7a6cc43386d3978ed0c74a59789cd088a10`.
- Archive: 21 entries; 0 unsafe entries; 0 symlinks; 0 manifest mismatches; 0 CRC failures.

This evidence-only documentation commit does not change plugin source or package contents; the final PR and merged-main heads must still rerun exact-head CI.

## Tooling observations not counted as product/repository defects

During release closure, the expanded QA harness initially lacked a database-advisory-lock stub required by the newly hardened source. The harness was aligned and the complete inherited suite then passed. Separately, GitHub correctly rejected a temporary bot attempt to push protected workflow YAML without workflow permission; workflow metadata was updated through the repository-authorized path instead. The temporary write-mutator script was removed. Because workflow-file deletion was not approved by the repository operation available in this environment, its former workflow file was converted into a manual-only, `contents: read` sentinel with no mutation/push step. These are QA/repository-tooling closure events, not additional product defects.

## Final acceptance boundary

This review closes repository coding/package/automated-QA only after PR and merged-main exact-head read-only CI are green with deterministic package verification. Hostinger staging acceptance, live deployment and operational acceptance remain separate external gates and must not be inferred from repository success.

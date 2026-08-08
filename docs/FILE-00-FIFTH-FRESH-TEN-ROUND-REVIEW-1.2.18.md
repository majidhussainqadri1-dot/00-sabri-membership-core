# File 00 — Fifth Completely Fresh Ten-Round Review — Release 1.2.18

Baseline: `40e411f291eb2949b7906b4c39b65577a7f490b6` (1.2.17). Earlier review cycles were not counted. Governing scope: current File 00 Advanced Trust plan, consolidated central plan, and current File 02/19/20/24 boundaries.

| Round | Result | Defect | Correction |
|---:|---|---|---|
| 1 | Defect | F5R-R1-D01 — Critical: declared adaptive step-up was not enforced by sensitive workflow methods | Bound critical identity, guardian, merge, delegation and break-glass mutations to `step_up_requirement`; break-glass requires File02 level-3 hardware-backed assurance |
| 2 | Defect | F5R-R2-D01 — High: age/jurisdiction could remain stale until background reconciliation | Recompute canonical DOB, gender, residence jurisdiction and guardian requirement synchronously in minimal assurance |
| 3 | Defect | F5R-R3-D01 — Medium: revocation hook first argument exposed local WordPress user ID | Hook now carries the same opaque platform subject as the event payload |
| 4 | Defect | F5R-R4-D01 — High: File24 system containment could be authorized by an untyped bare boolean filter | Require typed File24 owner/version/subject/state/freshness authorization claim |
| 5 | Defect | F5R-R5-D01 — Medium: external VC accepted impossible timestamp ordering | Require issued <= verified < expiry in addition to existing freshness/owner/proof checks |
| 6 | Defect | F5R-R6-D01 — High: delegation could survive loss of grantor authority | Active delegation revalidates grantor existence, protected state and current File00 membership authority |
| 7 | Defect | F5R-R7-D01 — High: break-glass approvals were IDs without approval time/current-authority revalidation at consume | Persist approval timestamps and revalidate both distinct privileged approvers at consumption |
| 8 | Defect | F5R-R8-D01 — Medium: guardian succession successor lookup did not bind to current consent-policy version | Current verified guardian lookup now requires current `smc_policy()['version']` |
| 9 | Defect | F5R-R9-D01 — Medium: hardening regression test encoded a stale File00 assurance-level assumption and falsely treated a correctly rejected File02 elevation as failure | Preserve the current File00 MFA baseline while asserting that stale File02 passkey/hardware elevation is rejected; regression now checks owner plus absence of elevated passkey/hardware claims |
| 10 | Defect | F5R-R10-D01 — Medium: committed plugin `MANIFEST.sha256` still contained pre-1.2.18 digests for changed release files, causing the clean exact-head contract gate to fail | Regenerated the committed manifest from the exact 1.2.18 source and retained deterministic package/source integrity verification |

Total: **10 unique defects; corrected 10/10**. Severity: **1 Critical, 4 High, 5 Medium, 0 Low**. Known unresolved repository defects after corrected-source closure: **0**.

Staging-Accepted, Live-Deployed and Operational remain pending external acceptance gates and are not inferred from repository QA.

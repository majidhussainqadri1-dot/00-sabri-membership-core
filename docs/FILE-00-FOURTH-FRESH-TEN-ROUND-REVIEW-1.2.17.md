# File 00 — Fourth Fresh Ten-Round Review and Corrective Closure — 1.2.17

Baseline main: `3c82152fe787eeb1830ed3feaf156f0306f4beef` (1.2.16). Earlier review cycles are not counted.

## Result
Rounds with defects: **1, 2, 3, 4, 5, 9, 10**. Rounds without a reproducible repository defect: **6, 7, 8**.

Total: **8 unique defects; corrected 8/8**. Severity: **1 Critical, 3 High, 4 Medium, 0 Low**. Known unresolved repository defects after corrected-source closure: **0**.

## Round ledger
1. FFR-R1-D01 Critical — institutional Administrator ordinary wp-admin early return bypassed File 00 hard-block/current-MFA enforcement. Removed the bypass; exact recovery allowlist remains the lockout-safe path.
2. FFR-R2-D01 High — `smc_membership_recovery_actions` could remove mandatory baseline recovery actions. Baseline is now unioned back after filtering.
3. FFR-R3-D01 Medium — public assurance-profile helper exposed local WordPress `user_id`. It now returns an opaque platform subject.
4. FFR-R4-D01 High — local MFA provenance stamped `verified_at=time()` rather than the actual session `two_factor_at`. A current-session timestamp accessor now supplies factual provenance.
5. FFR-R5-D01 High — institutional account with `expired` application evidence could collapse to `verified`. `expired` is now a controlling institutional hard block.
6. Event transport/retry/inbox idempotency review — no new reproducible defect.
7. Private-document/cryptography/key-ID compatibility review — no new reproducible defect.
8. Guardian succession/account merge/containment/continuity review — no new reproducible defect.
9. FFR-R9-D01 Medium — required configuration documented `SMC_MASTER_KEY` but omitted mandatory `SMC_MASTER_KEY_ID`, allowing staging setup to fail unexpectedly. Documentation corrected.
10. FFR-R10-D01 Medium — active Advanced Trust runtime QA still declared stale 1.2.15. FFR-R10-D02 Medium — README had a duplicated 1.2.15 heading for the first-fresh release; repaired to 1.2.14. Current executable QA/release identity synchronized to 1.2.17.

## External gates
Repository closure does not establish Hostinger staging acceptance, live deployment or operational acceptance. Those remain pending.

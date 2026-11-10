# File 00 × File 06: case-consent concurrency and revocation safety (2026-11-10)

## Scope and truth boundary

**Repository:** `majidhussainqadri1-dot/00-sabri-membership-core`.  
**Working branch:** `fix/file00-file06-case-consent-provider-20261108`, stacked Draft PR #56 on Draft PR #55.  
**Reference main:** `2fa7c022ee9cd1b65432e900579512f304532442`.  
**Plan:** Sabri Website Master Plan v12, File 00 consent/privacy/authorization clauses; File 06 research subject consent is distinct from ordinary platform membership.

This is an **exact-source candidate remediation**, not proof of deployed-code parity or live Hostinger outcome. It makes no change to main, production, staging, or another repository. Software 1.2.44 / source schema 1.4.5 are source-identifiers, not verified deployed versions.

## Root causes established from source and red-first QA

1. **Duplicate accepted consent race:** the old `accept()` did an unlocked SELECT followed by INSERT and activation. The `smc_consents` table has a `user_purpose` index but no uniqueness constraint for live `channel + user + subject-case` receipts. Two concurrent requests can pass the precondition and create separate active receipts.
2. **Incomplete subject revocation:** the old `withdraw()` did `UPDATE ... WHERE id = chosen_receipt`; other active receipts for the same patient and study case remain eligible for future `current_for_file06()` checks. Red-first test commit `dea2d679086ed1b9e7963edf1b9584aacfdf7d18` failed its File06 source-test job `42736438082` on the intended old behavior.
3. **Safety during follow-up fault injection:** after introducing an advisory lock, fault-injection tests identified two additional fail-safe cases: an acquired lock plus an anomalous DB error required an explicit release; and a failed post-write reconciliation needed both operator notification and downstream revalidation/invalidation. Red-first test commit `a53b9c2d9b2efeed2fb08b2508ca94d55b66aa4b` produced a failed File06 source QA run `42737433040`.

The above describe **2 original source defects and 2 corrective hardening gaps**, not 123 missing code features or a verified incident on the live website.

## Bounded implementation

- Added per-`user_id` + canonical research UUID DB named-lock acquisition (`GET_LOCK`, bounded timeout) for both `accept()` and `withdraw()`. Missing/ambiguous lock => fail closed, no acceptance/withdrawal mutation.
- Added `try/finally` lock release, including best-effort release of a granted handle when the lock query itself carries an error.
- Re-read the selected receipt after lock acquisition before issuing a withdrawal.
- On withdrawal, update **all** currently accepted, unwithdrawn rows for that subject, case HMAC, consent purpose and channel in one SQL mutation; never touch another subject or case.
- Read back `COUNT(*)` of surviving accepted receipts before asserting success. Any uncertain result yields an error and sets `smc_file06_consent_reconciliation_required` for operators, while emitting the downstream invalidation action.
- Preserve the existing audit, case-specific policy, adult assurance, independent File06 subject attestation and opt-in; no new paid tier, public identity exposure, role escalation, DB migration, or File06 source write.
- Updated `MANIFEST.sha256` from source bytes. Current changed File06 source SHA-256: `60529776f83ed06bad929e8c3645ee6109ee7254543c6ed6eccf2f09ad541a7c`.

## Concrete QA evidence

### Isolated PHP 7.4 and 8.3

`qa/file00-file06-consent-contract.php` now has **40 assertions** per PHP version, including subject/case binding, policy revisions, age gates, revoked receipt denial, duplicate receipt retirement, unrelated person/case isolation, fail-closed locking, idempotent acceptance, audit/activation order, stale revocation-reconciliation notification and cleanup. Source workflow `file00-file06-consent.yml` completed successfully on both PHP 7.4 and 8.3 at source review HEAD `e18a8aabc688c5e6aa9d0d42cd99f4fcfb13e400`.

### Real WordPress / MariaDB disposable CI database

`qa/file00-file06-consent-wordpress-mariadb.php`, executed in a disposable WordPress 7.0.1 / MariaDB 11.4 environment, produced **11 PASS / 0 FAIL** on GitHub Actions workflow `42737996101` (real DB job ID `129571843751`). It tested:
- two simultaneously active historical receipt rows,
- actual `smc_consents` storage,
- cross-patient authorization denial,
- subject-owned same-case bulk withdrawal with real DB locks,
- retention of unrelated cases and users,
- downstream invalidation event,
- repeat withdrawal denied,
- independently held lock on another MariaDB connection, with safe refusal and no unauthorized mutation.

This test exercises **withdrawal and lock contention against real SQL**; the positive `accept()` end-to-end pathway is currently covered by isolated PHP tests, not yet a genuine verified-patient WordPress application flow.

### Other regression context

The inherited File00 CF01/forty-round, exact release and pinned File04/File08 GitHub workflows were green on the earlier source-fix HEAD `28a25f2c4abfd3b8afc682014c9f191371d948c5`. Re-run/check at the **latest** source commit before any release decision, rather than treating older workflow success as exact-new-HEAD approval.

## Open acceptance gates and risks

1. Independent File06 companion version, authenticated research-scope reviewer policy, withdrawn-subject publication/cache purge, and its current actual consumer integration.
2. Genuine WordPress positive acceptance with verified adult membership profile and controlled concurrent requests under load, plus failure-injection against a real DB during audit or activation.
3. Stable release version choice: pending divergent File00 PR #52 uses 1.2.45, while this PR chain retains source 1.2.44. Do not silently overwrite or blind-merge.
4. Full 123-ID File00 acceptance; File02/04/08/09/17/19/20/21/22/23/24/25/26 consumer tests; browser/RTL/a11y; approved backup/restore and tenant/privacy erasure; staging and live parity.
5. Existing separate File04 sentinel orphan rollback scenario in PR #55 remains open; this change does **not** claim to resolve it.

## Required separate truth fields

| Field | Status |
|---|---|
| Repository main HEAD | `2fa7c022ee9cd1b65432e900579512f304532442` |
| Working candidate | Draft PR #56 on Draft PR #55; exact current SHA requires fresh CI check |
| Deployed Version | UNVERIFIED |
| Live DB Version | UNVERIFIED (source schema 1.4.5 only) |
| Migration State | UNVERIFIED |
| Live Verification Status | NOT PERFORMED / UNVERIFIED |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Count discipline

- **Original unique source defects addressed:** 2.
- **Additional candidate hardening gaps found and addressed during this work:** 2.
- **New tests:** 40 isolated assertions per PHP version, 11 separate real WordPress/MariaDB assertions.
- **Newly confirmed live defects:** 0 (live not inspected).
- **Production/live corrections:** 0 (no deployment).
- **Known unresolved release/acceptance blockers:** see the 5 independent groups above; do not mislabel pending tests as proven source defects.

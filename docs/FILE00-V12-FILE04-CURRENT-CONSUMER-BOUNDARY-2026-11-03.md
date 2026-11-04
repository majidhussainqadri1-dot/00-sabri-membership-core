# File00 — File04 Current-Consumer Trust Boundary, V12 Evidence & Regression Record

**Date:** 2026-11-03. **Repo source only.** No merge or deployment. File00 main at audit start: `2fa7c022ee9cd1b65432e900579512f304532442`. File00 owner candidate: Draft PR #55. The exact File04 **current main** source inspected was `bae1c70a168a09d7df09d634bd15050837e0b80a`; this is distinct from merged historical File04 PR #5 source `0ba3548a43f3ec5d00559f660ffcf871e28ac57e`.

## Corrected discovery: use current consumer, not historical PR source

File04 current-main `includes/class-snfla-plan-completion.php::authorship_preflight()` constructs an attribution-placeholder request with `file_number=04`, `legacy_id`, `legacy_author_id` and a **canonical request_digest** computed by `SNFLA_Checksum::hash`. It checks the File00 reply's `legacy_id`, `legacy_author_id`, `request_digest`, `user_id` and `platform_uuid`. Earlier File04 PR #5 did not include that request digest; **the current main already fixed it**. No stale-file Consumer patch is needed.

File04 existing-author requests use `sabri_file00_platform_uuid_v1` with `file_number=04`, `legacy_id` and `purpose=legacy_publication_migration`; unknown/deleted authors use `sabri_file00_legacy_author_placeholder_v1` with the digest. File04's exact current `SNFLA_Capabilities::CAP_RUN` is `sabri_file04_run_migrations`. Its `current_actor()` also permits `sabri_feed_run_migrations` as an explicitly governed canonical File21 migration fallback. File00's owner filter now recognizes **either exact capability**, but requires an authenticated principal; it does not accept arbitrary or review-only capabilities. A WP-CLI migration must be run under a privileged authenticated principal; anonymous/unowned CLI actions are not authorized.

**Late source parity correction:** a follow-up inspection of File04 `class-snfla-capabilities.php` identified the concrete File04 CAP_RUN name plus its distinct File21 migration fallback. The first File00 guard admitted only the fallback; that would have rejected an otherwise valid File04 CAP_RUN operator. Both exact paths are now tested; the public/anonymous path remains denied. This was caught during candidate review, not on live.

## Red-first source findings

Targeted regression in `qa/v12-file04-authorship-guard-runtime.php` initially failed four assertions against the previous File00 PR55 candidate:
1. An unrelated `file_number` could invoke File04 UUID service.
2. A nonmigration `purpose` could invoke File04 UUID service.
3. Anonymous callers could project a different user's UUID.
4. Previously `verified=true` upstream filter payloads were trusted before independent File00 verification.

The old placeholder logic also checked only **digest shape**, not canonical equality against the structured request. That gap was corrected after inspecting the File04 source; post-fix adversarial cases now specifically verify a forged but well-formed digest is rejected **even when a valid sentinel already exists**, and fail-closed privilege checks on an existing sentinel.

## Bounded File00 owner-source corrections

- `file04_migration_authorized()`: require exact consumer `04`, positive `legacy_id`, exact `legacy_publication_migration` purpose for UUID projection, an authenticated current WP user and either the exact File04 `sabri_file04_run_migrations` capability or the canonical File21 `sabri_feed_run_migrations` fallback.
- `file04_request_digest()`: reconstruct the 3-field sorted JSON digest using `wp_json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` and `sha256`, matching File04 current-main `SNFLA_Checksum::hash` for these scalar fields. Reject malformed or mismatching requests, not just hashes of the right length.
- `file04_platform_uuid()`: discard caller-supplied `$existing`, deny unauthorized/incorrect context and derive only the canonical File00 UUID for a current user.
- `file04_legacy_author_placeholder()`: discard untrusted previously verified results; validate canonical request and principal; only then return the File00-owned, digest-bound non-login attribution sentinel.
- Prior safeguard from PR55 remains: reserved login collision is rejected; `authenticate` and `wp_authenticate_user` prevent placeholder sign-in; real members are unaffected.

There is no new role grant, login, paid tier, clinical authorization or foreign repository write. The source SHA-256 record `source/sabri-membership-core/MANIFEST.sha256` was updated after PHP code changes.

## Evidence levels / acceptance truth

`qa/v12-123-evidence.mjs` indexes all **123** V12 requirement IDs. The source-level scan reports **103** source signals (80 historical R-group pointers + 3 CEN + 20 EXT), **113** test-file references (90 R-group pointers + 3 CEN + 20 EXT), and **22** individually named *static* test assertions (2 CEN, 20 EXT). None of these are independently attested end-to-end positive/negative consumer acceptances. `individual_behavioral_acceptances` remains **0** on purpose; this is not an assertion that 123 features are absent.

`qa/v12-file04-authorship-guard-runtime.php` now covers direct negative/positive File00 owner calls and registered WordPress filter invocations shaped like the exact current File04 producer. This is more than a source-text test but **not** a full two-plugin loaded WordPress staging test; no installed companion version, real DB migration, or browser evidence is asserted.

## Unmerged related PRs

- File00 PR #52 (`802d9da7f07167373619de52b641a2ededafae01`): stale divergent `1.2.45` File04 candidate. Do not blind-merge; current File04 has its own present callback contract and version reconciliation is required.
- File00 PR #54 (`63dd097874d9309236526eafb1f1f1faa86655eb`): its owner-side bounded UUID inverse lookup is incorporated by content into Draft PR #55, with 13 negative cases. Avoid double integration.
- File08 Draft PR #29 (`1a358ead5ed02938fe2ee0bcb45494d53b4ffa8d`): uses File00 inverse UUID helper plus independent forward equality guard. Not installed or end-to-end accepted.

## Remaining acceptance and truth fields

Next work: identify and exercise each File00 R/CEN/EXT behavioral acceptance with explicit positive/negative tests, pin consumer File04/File08 runtime using actual two-plugin source artifacts, review reserved-sentinel creation metadata/audit rollback, reconcile source-version choices, then finish coding other files **before** staging/live verification per Founder plan.

Repository Main: `2fa7c022ee9cd1b65432e900579512f304532442`. Exact candidate HEAD/CI to be attached after final workflow conclusion. Deployed Version **unverified**; DB Version **unverified**; Migration State **unverified**; Live Verification Status **deferred/unverified**. **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## 2026-11-03 — Additional File04 sentinel persistence/audit fault-recovery pass

**Proven new owner-source defect:** `ensure_legacy_author_placeholder_user()` previously executed two `update_user_meta()` writes without checking the stored values, then issued an audit event. A database/write-filter failure could leave a newly created account without one or both essential non-login/attribution markers, yet permit a success path. The previous audit-failure rollback also unconditionally required a WordPress admin include, breaking isolated runtime testing and obscuring the true persistence boundary.

**Bounded remedy:**
- Include `_smc_legacy_author_placeholder_v1` and `_smc_login_disabled_v1` in the WordPress user creation's `meta_input`, reducing the unprotected creation window.
- Read back both markers after creation. If a marker is absent, retry the ordinary metadata write and require a positive persisted readback; do not trust update function return values alone.
- Verify the created ID resolves to the exact reserved login before any deletion/compensation attempt.
- On missing marker or failed mandatory `SMC_Security::audit()`, attempt a compensating delete via `wp_delete_user()` (loading the WordPress include only if needed). **Never** issue a verified placeholder receipt on these failures.
- Keep normal login filters: a preserved, marked sentinel cannot authenticate.

**Adversarial harness:** `qa/v12-file04-placeholder-persistence-runtime.php` injects no-op or failed metadata persistence, a false-positive metadata return, audit refusal, deletion failure and insertion failure. It distinguishes genuine creation, marker readback, compensating deletion and fail-closed response. Final tested result: **15/15 PHP fixture assertions PASS**. This is simulated fault-recovery source QA, not a production database transaction or independently installed two-plugin rehearsal.

**Second red-first audit-laundering defect:** after a failed creation audit combined with failed deletion, an orphan retained both no-login markers. The original existing-user path could then return it as verified without any successful creation audit. Red CI demonstrated **3 failing attestation/retry assertions**. This candidate now writes `_smc_legacy_author_placeholder_audited_v1` only **after** successful audited creation, verifies its persistence, and on an existing two-marker sentinel without that marker demands a new `legacy_author_placeholder_recovered` audit plus persisted attestation before permitting use. Failed audits remain deny-by-default. Source QA now targets **18** persistence-fault assertions and **28** File04 direct/hook assertions; exact-green CI should be consulted independently before treating these target counts as passed.

**Explicit residual risk / recovery obstacle:** If the underlying database cannot delete an account *and* the initial login-disabled marker is absent because writes failed, a newly created but unverified reserved-login account could remain. The source returns no successful attribution receipt; the state still requires an authenticated operator DB inspection/cleanup and cannot be called automatically resolved. Exact recovery under actual database outages and races remains an external integration/acceptance gate. No live or staging database was touched.

The exact source file `class-smc-cf01-contract.php` is re-bound in `MANIFEST.sha256` after this correction. These are two additional bounded owner-source defect corrections (unchecked marker persistence and unaudited-orphan retry). They do **not** change the earlier 123/123 per-ID acceptance boundary.

# File 00 — Master Plan v12 code and adversarial review ledger

**Audit date:** 2026-11-03. **Scope:** `majidhussainqadri1-dot/00-sabri-membership-core`, new Draft PR #55, parent repository main `2fa7c022ee9cd1b65432e900579512f304532442`. Source-only, not a live incident diagnosis. Current candidate source software `1.2.44`, schema `1.4.5`, public membership contract `1.2.3`, authentication-account contract `1.1.0`.

## Governing V12 requirement inventory

The supplied `Sabri-Website-Master-Plan-v12.docx` File 00 requirements include **123 distinct IDs**:
- `F00-R001–R100`: the original 100 stable File 00 rows, represented in `qa/requirements-traceability.json`. Its completion record is **historical**, originally associated with older release 1.2.11, not automatically proof of current 123/123 acceptance.
- `F00-CEN-01–03`: single-free-tier/no donor advantage; logical separation of donations from roles/privileges; immediately rechecked age, guardian and consent conditions.
- `F00-EXT-001–020`: twenty identity, assurance, verification, privacy, delegation and continuity extensions in `qa/advanced-trust-traceability.json`. This legacy registry describes source intent and older tests; its `coded/tested` flags are not independently accepted evidence for the candidate HEAD.
- **0/123** is independently certified as end-to-end *staging/live accepted* in this audit. Production approval and live parity remain unknown.

## Four verified source defects and bounded fixes

| Defect | Evidence of original failure (pre-fix GitHub Actions) | Root cause and correction | Post-fix security test |
|---|---|---|---|
| F00-V12-D01 — anonymous actor binding | GitHub Run #813: 3 targeted failures on proposed actor-boundary regression | `actor_is_current` treated current user `0` as nonconflicting with a submitted actor ID; now an authenticated exact current user is mandatory, including Founder and empty-capability paths | `qa/v12-actor-binding-runtime.php` |
| F00-V12-D02 — reverification overdue bypass | GitHub Run #821: excessive interval and artificially future stored due date both accepted | Canonical interval now clamped to 1 day–1 year; old stored `due_at` cannot extend beyond `verified_at + policy interval` | `qa/v12-actor-binding-runtime.php` |
| F00-V12-D03 — untyped system reverification | GitHub Run #821: bare boolean adapter crossed system authorization boundary | System reverification now requires typed File00 owner/version, subject/source binding and fresh timestamp; old boolean shortcut removed | `qa/v12-actor-binding-runtime.php` |
| F00-V12-D04 — cross-subject timeline disclosure | GitHub Run #821: blanket management capability returned another user's trust activity | Cross-subject reader must have current step-up, explicit File00 authorization, bound actor/subject, nonempty purpose and freshness; self-service remains available | `qa/v12-actor-binding-runtime.php` |

**First four-defect change boundary:** the original four corrections remain inside `source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php` and its targeted QA. Canonical File02 authentication ownership and the previously approved File00 MFA-retirement policy were **not** replaced. The production-code checksum was re-bound in `source/sabri-membership-core/MANIFEST.sha256`; the deterministic package is validated by CI. No new paid tier or donation-derived privilege was added.

## Twelve distinct source-review lenses

Machine-readable, repeatable source and historical-register inspection is in `qa/v12-123-source-review.mjs`, executed by `npm test`. The twelve lenses are:

1. Exact 123 canonical requirement IDs and uniqueness.
2. Free-tier constitution and no donor advantage signals.
3. Actor binding and authorization.
4. File02 authentication boundary and File00 MFA retirement.
5. Age, guardian, critical change and revalidation.
6. Privacy, consent graph and minimal disclosure.
7. Revocation epoch and bounded time requirements.
8. Account duplicate/merge integrity.
9. Delegated scope and emergency dual control.
10. Database installer, recovery and locking.
11. PHP compatibility, package and existing QA.
12. Historical versus live-state provenance and approval.

**Interpretation:** these are **12 different automated static review lenses**, not twelve independent comprehensive human/security audits. Their success verifies only the signals they explicitly check. The separate regression suite exercises **14 runtime cases** and the inherited repository QA remains authoritative for source and package. Do not convert 12 passes to a statement that the 123 requirements have all been behaviorally proven.

## Subsequent cross-repository reconciliation and three additional source safeguards

- PR #54's File08 canonical subject reverse-lookup interface is now **integrated in Draft PR #55**: `SMC_CF01_Contract::resolve_user_id_by_subject_uuid()` and `smc_get_user_id_by_subject_uuid()`. Original native negative suite `qa/t38-file08-inverse-subject-runtime.php` **13/13 PASS**. File08 consumer PR #29 checks forward UUID equality, but is not installed or merged.
- Comparing PR #52's 1.2.45 File04 authorship code against current main identified stale base divergence (5 behind/16 ahead), incompatible reserved-login names and competing callback implementations. **Do not blindly merge PR #52**.
- File04 additional fail-closed source review: forged caller-supplied `$existing` UUID cannot override canonical File00 user UUID; unknown user cannot inherit supplied UUID; reserved placeholder-login collision is denied; real placeholder login is blocked by both standard WordPress authentication filters; ordinary users remain unaffected. Four original failing situations were reproduced (3 assertions and one missing guard), then corrected. `qa/v12-file04-authorship-guard-runtime.php`: **10/10 PASS**.
- `qa/v12-123-evidence.mjs` now produces a **123-row individual evidence inventory**, distinguishing prior group pointers from requirement-specific source methods. Its initial exact-head CI observed **103** source-code signals, **113** test-file pointers and **0 individually accepted end-to-end requirements**. The 20 governance/final-approval records without runtime source evidence are not automatically code defects.
- Full companion-file acceptance and File04 migration authorization remain explicit open gates; see `docs/FILE00-V12-PR52-PR54-PR55-COMPATIBILITY-2026-11-03.md`.

## Repository and integration blockers

1. Draft PR **#54** (File08 canonical UUID reverse lookup) remains independent/unmerged; its candidate `63dd097874d9309236526eafb1f1f1faa86655eb` is not equal to `main`.
2. PR **#52** (File04 authorship/placeholder contract, version 1.2.45) is also open/unmerged, so related changes must be integrated through deliberate conflict-aware code reconciliation rather than assuming either PR is deployed.
3. Full 123-item source→negative test→consumer→exact CI evidence ledger and independent File01/File02/File04/File08/File09/File20/File24/File25/File26 compatibility tests remain incomplete.
4. Browser/RTL/accessibility, provider contracts, migration/backup/restore, source package/deployed checksum parity and live testing are external acceptance, intentionally deferred until coding across all files is reviewed.
5. **Release version must be reconciled** with pending 1.2.45 PR before any package is selected for staging; 1.2.44 source candidate is not an authorization to redeploy a colliding version.

## Live Reality Freeze fields

Repository main: `2fa7c022ee9cd1b65432e900579512f304532442`; corrected Draft PR candidate will be verified by its exact final CI SHA. Deployed Version **unverified**; DB Version **unverified**; Migration State **unverified**; Live Verification Status **not attempted in this coding-only phase**.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## No-overclaim decision

Seven source defects have bounded corrections in Draft PR #55 (four original advanced-trust defects plus three File04 source issues), with a separate File08 contract gap integrated; 12 automated source-review lenses, 14 advanced-trust tests, 13 File08 resolver tests and 10 File04 tests are in the workflow. This is NOT 123/123 end-to-end acceptance, NOT a claim that unrelated modules are complete, and NOT staging/production resolved. No repository main merge or live deployment follows from this document.

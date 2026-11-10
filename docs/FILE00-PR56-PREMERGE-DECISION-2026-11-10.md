# File 00 v12 — PR #56 premerge decision and exact-source truth, 2026-11-10

## 2026-11-10 additional real two-plugin activation result

At File00 exact code+workflow commit `15f343e48209e089cabdf04e9747e95d3417306a`, the disposable WordPress 7.0.1 / MariaDB 11.4 job `42747566914` **PASSED** after installing/activating both complete plugin directories (File00 candidate and pinned File06 PR16 exact SHA `bee1462c0591f7281d3cd8182c6d9563a16dc078`). An independent WP-CLI runtime step verified both plugins active, `HE_V2_Schema::runtime_status() === 'active'`, File00/File06 owner classes and both active policy/relation hook registrations. The same job returned 36/36 File00 real DB and 12/12 PR16 genuine-source-class/real-WPDB consent assertions PASS.

**This closes the isolated dual-plugin fresh-install/activation smoke requirement**, but does NOT prove full **File06 real canonical research-record subject-policy approval route → patient File00 explicit acceptance → File06 public read/write → withdrawal/cache purge** with both plugins active throughout. It also does not exercise upgrade from older persisted schema, migration rollback/backups, real independent human reviewers, staging or live.

## Decision summary

**Current disposition: HOLD / DRAFT — DO NOT MERGE OR DEPLOY.**

The source-level 3-part File00↔File06 consent interface is **implemented in File06 PR #14** and **its WordPress filter arity corrected in stacked File06 PR #16**. An exact pinned pair of File00 PR #56 and File06 PR #16 passed 12/12 paired real WordPress/MariaDB contract assertions, in addition to 36/36 existing real File00 consent assertions and 61/61 isolated PHP assertions per PHP 7.4 and 8.3. The paired test seeds synthetic approved patient, documents, independent reviewer and consent-policy assertions; it does **not** install the whole File06 plugin, exercise migrations, or demonstrate real human patient/reviewer approval.

## Frozen source identities and architecture

| Item | Exact source SHA / status |
|---|---|
| File00 main | `2fa7c022ee9cd1b65432e900579512f304532442`, plugin `1.2.44`, schema target `1.4.5` |
| File00 PR55 (parent) | `b3e9253d06a2897f092981f3a1f77e16b4b78578` Draft |
| File00 PR56 (child) | consult exact branch HEAD at gate; staged on PR55, source plugin `1.2.44` |
| File06 PR13 (older) | `308cb09f9375057fe83e2738e002e74b9aa68bbe`; genuinely missing 3 interface elements; old negative control stays pinned |
| File06 PR14 | `4f5b7b1714bd1dc502026ca6509f7cab35b43cd5` Draft, owner classes and UUID implemented |
| File06 PR16 (stacked fix) | `bee1462c0591f7281d3cd8182c6d9563a16dc078` Draft, policy hook arity corrected |
| File00 PR52 | `802d9da7f07167373619de52b641a2ededafae01`, nonmergeable historical `1.2.45` candidate |
| File00 PR54 | `63dd097874d9309236526eafb1f1f1faa86655eb`, its File08 UUID resolver incorporated into PR55 |

## The three contract discrepancies — corrected in PR14 + PR16

1. File06 `HE_V12_Completion::consent_attestation()` now forwards the canonical `research_public_id` along with the File00 receipt, policy, locale, purpose and research actor.
2. File06 `HE_V12_Case_Consent_Owner::relation_claim()` now produces `he_v12_file06_patient_relation_v1`, subject ID + exact research UUID, version and distinct attester/reviewer, based on current File06 controlled case row and independently approved relation.
3. File06 `HE_V12_Case_Consent_Owner::approved_policy()` now produces the approved text, SHA-256, version and locale required by `he_v12_file06_consent_policy_v1`.

**New confirmed File06 PR14 application defect:** `hooks()` registered policy callback with `accepted_args=1` while callback needs 2; real joint WordPress test raised `ArgumentCountError` in run `42746653462`, job `129600246006`. File06 PR16 fixes `10,1` → `10,2`, with `tests/v12-file00-policy-hook-arity.php` included in `tests/run-all.sh`. File00 CI then pinned PR16 source and returned **12/12 real WP pair assertions PASS, 36/36 existing real DB assertions PASS**, run `42747048349`, job `129601545567`. The earlier fail-closed PR13 negative gate is still intentionally kept as a historical compatibility sentinel.

**Interpretation:** 3 missing *code contracts* are **source-implemented / limited source-class + real WordPress SQL verified**. The original File06 PR13 alone remains incompatible. Full installed File06 plugin and migration acceptance are *not yet proven*.

## Version `1.2.45` conflict — conservative release decision

- File00 PR52's stale source version `1.2.45` is NOT safe to cherry-pick or merge onto current File00 source. Its File04 placeholder naming, older ancestry and changed CF01 contract diverge from PR55/56. PR52 also reports `mergeable=false`.
- All **nine** workflows at PR52 exact SHA on 2026-09-24 show failure; do not treat 24 September CI as proof of a 10 November application defect. PR55/56's new source and checks are separate.
- **Recommended next release identifier:** `1.2.46`, *only after* PR52 is formally superseded/retired, PR55+PR56+File06 dependency reconciled, source/plugin/package lock manifests/workflow strings are updated coherently and all exact-HEAD tests rerun.
- **Do not bump to `1.2.45` now** or silently leave a release-ready merged plugin at `1.2.44`; the current code is an unmerged development candidate, not a production release. Keep version change as a distinct reviewed release step.
- The project founder/owner must approve the supersession/version decision before merge. This document is an evidence-based recommendation, not an unrecorded approval.

## Tests: observed vs missing

**Observed:**
- File00 PR56 exact-code isolated QA: PHP 7.4 and 8.3, 61/61 each.
- File00 disposable WordPress 7.0.1/MariaDB 11.4: 36/36 existing consent assertions.
- Pinned File06 PR16 source-class↔File00 real WP SQL test: 12/12; wrong UUID, withdrawn relation, unapproved policy and suspended member deny use.
- File06 PR14 inherited `File 06 20-Part Governing Plan Parity` success (run `42452989180`); File06 PR16 rerun status must be freshly checked.
- File06 PR14 two other historical workflow failures had **zero executed jobs**; they cannot prove a failed PHP assertion.
- File00 PR55/56 inherited Release Gate, CF01, File04/File08 consumer tests need exact final SHA check after any modifications.

**Outstanding hard release gates:**
1. **Fresh dual-plugin activation is now passed in disposable WP/MariaDB.** Still required: complete real File06 canonical research flow under both installed plugins, upgrading legacy persisted File06 schema/migrations, backup/restore, staging deployment and no simulated independent human owner evidence.
2. Current File06 actual subject record allocation, policy approval, independently authorized reviewer, real patient verified File00 account, genuine consent acceptance, and public read/write + revocation/consumer cache invalidation against both plugins together.
3. Exact source and dependency manifests/checksums, independent File02 authentication binding, role/guardian/erasure negative paths.
4. PR55 and PR56 review + version-lineage reconciliation; File04 placeholder orphan recovery branch behavior; PR54 duplication avoidance.
5. Complete File00 123-ID v12 acceptance, production backup/recovery/rollback, accessibility/RTL, signed deploy, stage and live re-test with parity.

## Mandatory live-reality distinction

| Field | Current verified state |
|---|---|
| Repository HEAD | Main SHA above; candidate PR SHAs must be frozen separately |
| Deployed Version | **UNVERIFIED** |
| DB Version | **UNVERIFIED** (source target 1.4.5 only) |
| Migration State | **UNVERIFIED** |
| Live Verification Status | **NOT DONE / UNVERIFIED** |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## Defect and closure counts

- Three originally missing File06 interface components: **3/3 source-implemented in PR14**, awaiting full dual-plugin acceptance.
- One newly established WordPress fatal callback-arity bug: **1/1 corrected in File06 PR16** and 12/12 WordPress source-class pair regressions green.
- One independent historic File00 version conflict: **1 outstanding release decision**, conservative successor `1.2.46` proposed, not applied.
- One known File04 sentinel orphan recovery risk remains open independently.
- Live repairs: **0 verified**; main branches unchanged; production resolution not claimed.

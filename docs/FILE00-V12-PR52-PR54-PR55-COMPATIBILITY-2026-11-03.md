# File 00 — PR #52, #54 and #55 source compatibility and reconciliation

Date: **2026-11-03**. This is a **repository-only, exact-source** reconciliation. It does not authorize merging or live deployment.

## Frozen source identities

| Reality | SHA / state | Interpretation |
|---|---|---|
| File00 main | `2fa7c022ee9cd1b65432e900579512f304532442` | repository main source baseline |
| PR #52 File04 authorship candidate | `802d9da7f07167373619de52b641a2ededafae01`; non-draft, unmerged, `mergeable=false` | based on earlier main `1d507e9`; **5 commits behind and 16 ahead** of current main, status *diverged* |
| PR #54 File08 UUID reverse lookup | `63dd097874d9309236526eafb1f1f1faa86655eb`; Draft/unmerged | based on exact current main; **7 ahead, 0 behind**, additive |
| PR #55 corrected V12 owner candidate | begins from exact main `2fa7c022...`; Draft/unmerged | reconciles 4 evidenced security fixes and incorporates PR54 owner-only contract without changing main |
| File08 consumer PR #29 | `1a358ead5ed02938fe2ee0bcb45494d53b4ffa8d`; Draft/unmerged | its `class-wca-future24.php::subject_user_id` requires File00 `smc_get_user_id_by_subject_uuid` and verifies forward UUID equality |

## PR #54 — source integration accepted into PR #55, not production

- Read-only internal `SMC_CF01_Contract::resolve_user_id_by_subject_uuid()` now checks canonical UUIDv4, exact owner `_smc_platform_uuid_v1`, at most two matching users, unique current user and persisted UUID equality.
- `smc_get_user_id_by_subject_uuid()` exposes only a server-side identity lookup. It grants **no** File08 role, guardian relationship, appointment, patient, clinic, clinical data, or other capability.
- PR54's original **13** native negative tests are reproduced unmodified in `qa/t38-file08-inverse-subject-runtime.php` and run under the complete `npm test` workflow.
- Source checksums `MANIFEST.sha256` cover changed `class-smc-cf01-contract.php` and `functions.php`.
- File08's exact consumer PR29 source checks both the reverse mapping and forward identity equality; this is source-contract parity only. Cross-plugin staging/install/runtime and database uniqueness require separate evidence.
- **Do not merge both PR54 and PR55 independently without checking exact duplicate commit contents**: the canonical owner code in PR55 intentionally supersedes PR54's additive content, but PR54 remains a distinct unmerged PR with its own history.

## PR #52 — unsafe blind merge; preserve current authoritative File04 contract

Current File00 `main` already includes `file04_platform_uuid()`, `file04_legacy_author_placeholder()` and `ensure_legacy_author_placeholder_user()` on `SMC_CF01_Contract`. PR52 was authored against older ancestry and exports different callback names (`filter_platform_uuid`, `legacy_author_placeholder`); its placeholder login `sabri_legacy_unknown_author` differs from current canonical `sabri_legacy_author_placeholder`. Its release identity is `1.2.45` whereas current main and PR55 remain `1.2.44`.

**Conflict risk:** blind merge or file replacement could remove current File04 filter method names, produce two logically competing placeholder identities, overwrite current release checks, or revert newer changes. The diff is not safely cherry-pickable as a whole.

**Conservative resolution:** Do **not** import the old PR52 File04 helper implementation, workflow YAML or version bumps into PR55. Keep current File00 implementation as source candidate and require a separate exact current File04 consumer contract test identifying its filter names, request provenance, login prohibition, actor/CLI authority and placeholder collision prevention. PR52 stays unmerged until deliberately superseded or independently rebased and reviewed.

**Open security design review (not claimed fixed):** current `file04_platform_uuid()` accepts a syntactically valid `$existing` UUID before binding it to `$user_id`, and current File04 placeholder generation has a less restrictive request-authority condition than the divergent PR52 candidate. This is a source-level trust-boundary concern; **do not call it an exploited live vulnerability or make a breaking patch without frozen consumer inputs and failing adversarial tests**.

## 123-requirement acceptance boundary

`qa/v12-123-evidence.mjs` emits one machine-readable row per `F00-R001–R100`, `F00-CEN-01–03`, and `F00-EXT-001–020`. Each row separates historical group-pointer evidence, code/token/source checksums, discovered test files, individual behavioral proof and external acceptance. Its accepted counts **must stay false** until stronger independently performed tests and external approvals are attached.

**Next best source work:** prove File04's exact current consumer invocation against File00 on both PHP7.4 and 8.3, add fail-closed tests for forged/mismatched platform UUID and placeholder collision, reconcile File00/File04 release version and review the 123 rows with per-ID negative tests where needed. Staging/live deployment is deferred until all files' code review is complete.

## Live Reality fields

Repository HEAD = separate immutable source truth; Deployed Version **unknown**, Database/Schema Version **unknown**, Migration State **unknown**, Live Verification **unverified**. **Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

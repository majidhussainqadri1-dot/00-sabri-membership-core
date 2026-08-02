# File 00 — Four-Round Runtime Audit and Correction — 1.2.4

## Governing baseline

Audit baseline: File 00 master plan requirements `F00-R001`–`F00-R100`, current `main` release 1.2.3, contract 1.1.2 and schema 1.2.0.

## Round 1 — Authorization precedence and hard blocks

### Defects confirmed

- `manage_options` bypassed membership capability, admin and REST enforcement.
- Institutional identity precedence could therefore become a practical bypass for explicit `rejected`, `suspended`, `appeal_review`, `erasure_pending`, `expired` or corrupt states.
- A broad action-prefix exemption treated arbitrary `smc_*` and `sa_*` actions as recovery.

### Corrections

- Added `SMC_Authorization` as the single replacement authorization boundary.
- Explicit hard blocks now deny platform/reviewer/publishing capabilities even for an Administrator.
- Recovery is an exact allowlist, not a prefix rule.
- WordPress core `manage_options` is not rewritten; File 00 restricts only its protected/platform capabilities and requests.

## Round 2 — Guardian, session and REST context

### Defects confirmed

- Capability eligibility did not defensively require the guardian assertion.
- Safe/public REST reads and protected mutations were not contextually separated.

### Corrections

- Effective eligibility now requires canonical eligibility, no hard block, valid guardian consent when applicable, and verified email/mobile ownership for ordinary accounts.
- Protected capability and mutation gates require a current two-factor session challenge.
- Anonymous/public reading remains outside File 00; authenticated safe methods are readable by default, while mutating REST requests fail closed unless a consumer explicitly classifies a route differently.
- Exact REST recovery routes can be registered without widening the boundary.

## Round 3 — Founder high-risk governance and policy drift

### Defects confirmed

- The ordinary settings form could replace an already configured Founder identity.
- Client-side age values were duplicated as literals and could drift from the canonical server policy.

### Corrections

- Ordinary Founder reassignment is locked after configuration; recovery requires an explicit audited process or immutable `SMC_FOUNDER_USER_ID` configuration.
- The settings action is subject to effective membership and current-session two-factor controls.
- JavaScript policy values are now derived from `smc_policy()`.

## Round 4 — Master-plan integrity, traceability and release evidence

### Defects confirmed

- The final 100-point project artifact was not registered in the repository by exact checksum and stable requirement range.
- No machine-readable 100-requirement implementation/evidence map existed.
- Existing release tests still asserted 1.2.3 metadata only.

### Corrections

- Added a checksum-governed repository index, 100-requirement Markdown/JSON traceability and automated integrity contract for the reviewed project DOCX.
- Added static and runtime authorization regressions.
- Updated release, package, CI, WordPress readme, status and architecture evidence to 1.2.4/contract 1.1.3.

## Residual mandatory gates

This audit does not manufacture evidence that only the target environment can provide. Jurisdiction-specific age/child-safety approval, real provider delivery, cross-plugin integration, browser/screen-reader/RTL/mobile/low-bandwidth tests, performance, backup/restore, rollback, key-loss/disk-full rehearsal, monitoring and Founder acceptance remain pending on Hostinger staging.

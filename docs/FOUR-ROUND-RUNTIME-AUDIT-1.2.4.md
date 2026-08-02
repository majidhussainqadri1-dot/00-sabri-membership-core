# File 00 — Four-Round Runtime Audit and Correction — 1.2.4

## Governing baseline

Audit baseline: File 00 master plan requirements `F00-R001`–`F00-R100`, `main` release 1.2.3, contract 1.1.2 and schema 1.2.0.

## Round 1 — Authorization precedence and hard blocks

### Defects confirmed

- `manage_options` bypassed membership capability, admin and REST enforcement.
- Institutional identity precedence could therefore become a practical bypass for explicit manual `rejected`, `suspended`, `appeal_review`, `erasure_pending` or corrupt states; ordinary expired evidence also remains restricted.
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
- Exact REST recovery routes are matched by both route and HTTP method.

## Round 3 — Founder high-risk governance and policy drift

### Defects confirmed

- The ordinary settings form could replace or clear an already configured Founder identity.
- Client-side age values were duplicated as literals and could drift from the canonical server policy.
- The protected capability census needed a controlled extension boundary for canonical consumers.

### Corrections

- Ordinary Founder reassignment and clearing are locked after configuration; recovery requires an explicit audited process or immutable `SMC_FOUNDER_USER_ID` configuration.
- The settings action is subject to effective membership and current-session two-factor controls.
- JavaScript policy values are now derived from `smc_policy()`.
- Canonical consumers may extend the protected capability census through `smc_restricted_capabilities` without weakening the default controls.

## Round 4 — Master-plan integrity, traceability and release evidence

### Defects confirmed

- The final 100-point project artifact was not registered in the repository by exact checksum and stable requirement range.
- No machine-readable 100-requirement implementation/evidence map existed.
- Existing release tests still asserted 1.2.3 metadata only.
- A public contract bump to 1.1.3 was initially proposed without an assertion-schema change and was therefore unnecessary.

### Corrections

- Added a checksum-governed repository index, 100-requirement Markdown/JSON traceability and automated integrity contract for the reviewed project DOCX.
- Added static and runtime authorization regressions.
- Updated release, package, CI, WordPress readme, status and architecture evidence to 1.2.4.
- Preserved public contract 1.1.2 and database schema 1.2.0 for consumer and migration compatibility.

## Fresh review after correction

A new review of the first corrective implementation found and fixed five additional issues before merge:

1. Founder clearing with user ID `0` could bypass the first reassignment check.
2. REST recovery exemptions were route-only instead of route-and-method exact.
3. The protected capability census was not extensible for canonical consumers.
4. Contract 1.1.3 was not justified by a public schema change.
5. Audit wording blurred institutional manual hard blocks with ordinary expired-evidence restrictions.

## Verified automated result

The final corrective PR head `5efab3d837700fd15d27b518a6e98942bd802af2` passed GitHub Actions workflow run `30732165567` through PR merge ref `1ce11cfdfca953d57cab3abd96a8c02faf8c6db8` and was merged into `main` as `1ef2a3898eafbe5b5c023ab24e42fcca1b89a472`.

- Baseline source assertions: 563 PASS, 0 FAIL.
- Membership-state contract/runtime: 25 PASS and 16 PASS, 0 FAIL.
- Institutional lifecycle contract/runtime: 22 PASS and 9 PASS, 0 FAIL.
- Authorization boundary contract/runtime: 32 PASS and 17 PASS, 0 FAIL.
- Master-plan traceability contract: 16 PASS, 0 FAIL.
- Package safety: 0 unsafe entries, 0 symlinks, 0 manifest mismatches and 0 CRC failures.
- Deterministic package SHA-256: `c22e05c4bd60fb2540715f507a11f905d1d01d9d44fd8b53bf9946e48bc7934a`.

## Residual mandatory gates

This audit does not manufacture evidence that only the target environment can provide. Jurisdiction-specific age/child-safety approval, real provider delivery, cross-plugin integration, browser/screen-reader/RTL/mobile/low-bandwidth tests, performance, backup/restore, rollback, key-loss/disk-full rehearsal, monitoring and Founder acceptance remain pending on Hostinger staging.

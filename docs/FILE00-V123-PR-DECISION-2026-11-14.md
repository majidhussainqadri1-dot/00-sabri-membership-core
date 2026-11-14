# File 00 V12.3 — G02 PR and release-lineage decision ledger

Date: 2026-11-14. **Analysis only; no merge, closure or version decision is authorized by this document.** Frozen `main` SHA: `1fad051c86d530d558be3eb1890d3c6e67bce6e8`.

| PR | Snapshot | Decision pending specific proof |
| --- | --- | --- |
| #21 | old 1.2.11 File02 contract Draft | HOLD; compare with current 1.2.44 and File02 source before supersession |
| #29 | 1.2.19 Draft | HOLD; evaluate historical acceptance and supersession, no stale release |
| #30 | 1.2.20 Draft | HOLD; evaluate overlap with later advanced-trust source |
| #31 | 1.2.21 Draft | HOLD; evaluate latest lineage and redundant source |
| #52 | 1.2.45 File04 alternative; GitHub reports merge conflict | BLOCKED until File04 reserved-login semantics, historical principal invariants and version collision are independently reconciled |
| #54 | UUID reverse lookup Draft; GitHub reports merge conflict | HOLD; functionality overlaps with PR #55, select one canonical owner implementation |
| #55 | V12 123-evidence and actor/guardian/trust/attribution repair; GitHub reports merge conflict | PRIORITY REVIEW; resolve current-main (#58) ancestry, preserve red/green behavior, independently review and retest; cannot call 123 accepted |
| #56 | File06 case-specific consent; stacked on PR #55 (not directly on main) | HOLD as dependent; pair exact current File06 source, full real multi-plugin consent withdrawal/purge and migration gates before release |
| #59 | New current-main Draft: limited File09 authenticated self-service actor binding + 123 inventory | SOURCE FIX candidate; red/green CI achieved; File09 real consumer, branch integration, release identity and staging/live still pending |
| #58 | merged source-only File09 doctor-intake contract | Already in main; clinical privilege remains independent. Issue #57 not yet accepted end-to-end |

## Confirmed overlap
PRs #54, #55, #56 and #59 all touch `source/sabri-membership-core/MANIFEST.sha256`. PR #52 modifies an alternate File04 attribution path. A mechanical sum of changed files or blind merges is forbidden. Recompute manifest and all pinned provider/consumer checks after each deliberate integration.

## Required decision gates
1. Freeze main and all candidate SHAs and perform file-by-file comparison, including exact File02, File04, File06, File08 and File09 paired source.
2. Authorize only tested logical changes; deduplicate overlapping UUID and File04 implementations.
3. Establish a **new unique release version**. `1.2.46` is a suggestion from previous premerge notes, **not an approved release**.
4. Preserve every red-first regression and source/consumer negative invariant.
5. Rebuild/checksum once on final merged SHA; staged database schema, backups, install order, rollback and live parity separately accepted.
6. Do not close old PRs until source equivalence/replacement is recorded and the responsible owner approves.

## Scope and counters
Nine open PRs observed at the snapshot, **four critical pre-existing PRs** (#52, #54, #55, #56), four historical Drafts and one new source-only Draft #59. One merged source contract PR #58 is not an outstanding PR. No claims about deployed functionality.

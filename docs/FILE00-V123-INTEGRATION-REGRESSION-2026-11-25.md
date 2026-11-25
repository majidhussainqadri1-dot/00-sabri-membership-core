# File00 1.2.47 candidate integration regression audit — 2026-11-25

Compared exact PR #66 source 3cf1bf1ac04ce554a3032a47e53f125919dd3c81 with prior PR #64 e87f629aa0bd8cb9ecd51a717315cde44c492266. Source-only correction; no claim of live defects or live acceptance.

- Restore File04 provider authorization, exact purpose, owner-issued identity, reserved-login collision denial and non-login guard, using historical main sentinel login rather than introducing a second identity. Newly created sentinel has zero WP roles; pre-existing role-bearing or erasure-locked sentinel is rejected pending authenticated host review. No live account mutation.
- Restore privacy-erasure denial in inverse UUID mapping and owner-checked placeholder predicate.
- Restore exact trust_timeline_read purpose and action on cross-subject timeline checks; preserve the newer schema-readiness and deferred bootstrap safeguards.
- Restore File04 owner fixtures, erased/role-bearing sentinel negatives, inverse erasure regression, File03 exact paired WordPress workflow (File03 4b0006b9f9301077b4c2b020c56e722d862112c6), and pinned File04 consumer workflow. Retain inherited tests.
- New exact SHA CI and package verification required. 1.2.47 remains an unreleased draft identifier; no production overwrite authorized. 123 acceptance IDs, File06 consent lifecycle, other owner integrations, independent 20 complete review cycles, staging, backup/restore, deployed parity and live re-test remain open.

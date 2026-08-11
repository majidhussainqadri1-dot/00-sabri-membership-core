# Sabri Membership Core — Release 1.2.40

## Repository release identity

- Runtime release: `1.2.40`
- Database schema target: `1.4.5`
- Public membership contract: `1.2.3`
- CF-01 membership-assurance contract: `1.1.0`
- Advanced Trust contract: `1.0.0`

## Purpose

Release 1.2.40 is the repository-side result of the requested 80-round fresh review of File 00 after the live-proven 1.2.36–1.2.39 migration compatibility sequence. It does not rewrite or bypass historical audit evidence and it does not infer Live state from GitHub state.

## Principal corrections

1. Completes the Founder-approved retirement of File 00 MFA from active runtime surfaces. Retired File 00 authenticator/TOTP/recovery/session-challenge controls are not permitted to reappear through fallback authorization, reviewer/admin transitions, Advanced Trust transitions, Institutional AI configuration, Host Compatibility fallback registration, or the current user security UI.
2. Keeps historical factor/audit compatibility code only where required for governed migration, verification, containment, and retirement evidence; historical compatibility code is not an active authentication owner.
3. Preserves session revocation and containment after File 00 MFA retirement and adds canonical `smc_auth_sessions` indexes for `expires_at` and `revoked_at`. This is the schema change that advances the File 00 database contract from `1.4.4` to `1.4.5`.
4. Aligns release manifest, package identity, QA contracts, and active CI workflows with the 1.2.40 / 1.4.5 / 1.2.3 repository identity.
5. Adds a permanent exact-head release gate covering PHP lint, inherited QA, WordPress 7.0.1 + MariaDB 11.4 migration/regressions, deterministic package verification, and artifact generation.

## 80-round review record

Defects were identified and corrected during rounds **1, 2, 3, and 80**. Rounds **4–79** produced no newly recorded defect after the immediately preceding corrections. Round 80 is the final release-closure round and includes manifest/QA/CI/documentation identity reconciliation found by the exact-head gates.

This record describes repository review evidence only. It is not evidence that the candidate has been deployed to Live.

## Supplemental requested ten-round corrective review — 11 August 2026

A further ten-round review was started from the then-current PR #44 exact head, with every newly proven defect corrected before the next review lens proceeded.

- **Round 1 — defect found and corrected:** the institutional lifecycle recovery reader expected plaintext `reason_code`/`reason`, while the canonical audit privacy sanitizer deliberately stores those sensitive keys as SHA-256 digest fields. The repair path now recognizes only the exact allowlisted `age_eligibility_failed` digest (while retaining legacy plaintext compatibility) and does not expose or reverse arbitrary audit detail.
- **Round 2 — defect found and corrected:** temporary cursor root-cause diagnostic workflow/script residue was removed after it had produced the required evidence.
- **Round 3 — defect found and corrected:** the permanent release gate only rejected one narrow temporary filename pattern. It now rejects any `zz-*` temporary file in the workflow or top-level QA diagnostic surfaces.
- **Round 4 — defect found and corrected:** institutional repair completion previously meant only “no write failures in the current batch.” Completion now also requires exhaustion of the persistent repair cursor, preventing a 500-row batch from prematurely stamping the release repair as complete while later eligible rows remain.
- **Round 5 — QA defect found and corrected:** the real WordPress/MariaDB starvation regression did not explicitly fail on premature completion. It now asserts both that completion remains false while more cursorized batches remain and that completion becomes true only after the terminal batch.
- **Rounds 6–9 — no new defect found:** release identity, migration/schema compatibility, authentication/MFA ownership and authorization boundaries, and package/manifest/reproducibility were re-reviewed after the corrections above.
- **Round 10 — exact-head closure gate:** repository acceptance is determined only by the final PR-head CI result and deterministic package artifact. A green earlier SHA is not sufficient evidence for a later head.

## Historical live incident boundary

The 1.2.36–1.2.39 compatibility work remains historical evidence for the live migration sequence: legacy `queue` index reconciliation, legacy `decision` index reconciliation, protected Administrator role-backfill semantics, and missing-principal/orphan application reconciliation. Release 1.2.40 retains those corrections while adding the review hardening above.

## Release acceptance boundary

Repository release acceptance requires the exact candidate SHA to pass all required CI gates and to produce the verified installable package. Main-branch merge and post-merge package identity must then be verified separately.

**Live deployment, database promotion, migration completion, staging acceptance, and operational correctness remain separate states.** This file does not claim that the live site is resolved. Under the project Live-First rule, Live may be called resolved only after the exact deployed build, live database/schema/migration state, and real workflow behavior are re-tested and deployment parity is confirmed.
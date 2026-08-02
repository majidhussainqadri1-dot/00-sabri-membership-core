# File 00 — Sabri Membership Core — Authoritative Master Plan 2026

## Governing status

This repository document is the authoritative index for the four-round reviewed File 00 master plan. The complete human-readable specification is preserved as the project artifact:

- `00-Sabri-Membership-Core-Complete-Master-Plan-2026-Four-Round-Reviewed-Final.docx`
- SHA-256: `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Document version: `2.0 — Four-Round Reviewed and Corrected Final Specification`
- Runtime audit release: `1.2.4`
- Contract version: `1.1.2`
- Database schema: `1.2.0` (no structural migration in 1.2.4)

The DOCX project artifact is normative for full requirement wording and governance hierarchy. This repository preserves its exact checksum, all stable requirement IDs/titles, and the current machine-readable implementation/acceptance status.

## Source hierarchy

1. Latest explicit Founder decision.
2. Platform Definitive Integrated Master Plan.
3. This File 00 master plan and its stable `F00-R001` through `F00-R100` requirements.
4. Harmonized owner plans for Files 02, 03, 09, 17, 19, 20, 21, 22, 23, 24 and 25.
5. Repository source, tests and release evidence.

A lower source may not silently override a higher source. Conflicts must be documented and resolved through an explicit decision.

## Canonical ownership

File 00 owns membership eligibility, identity assurance, guardian consent, membership state, membership security assertions, privacy/retention controls and membership audit evidence. It does not duplicate authentication credentials, public profiles, professional credential truth, publishing, notifications, communications or the global shell.

## Traceability artifacts

- `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.4.md` — human implementation/evidence map.
- `qa/requirements-traceability.json` — machine-readable source of truth.
- `qa/master-plan-traceability-contract.mjs` — integrity and completeness regression.
- `docs/FOUR-ROUND-RUNTIME-AUDIT-1.2.4.md` — four review/fix rounds and residual gates.

## Acceptance boundary

Repository completion does not equal staging or production acceptance. Hostinger staging must still verify fresh activation, real upgrade/migration, providers, cross-file integration, browser/accessibility/RTL, backup/restore, rollback, key/disk failures, privacy erasure, monitoring and Founder acceptance. Until then:

- Staging accepted: **No**
- Production approved: **No**
- Live installation authorized: **No**

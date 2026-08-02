# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, privacy lifecycle and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.5`
- Contract version: `1.1.2`
- Database schema version: `1.2.0` — unchanged; no structural database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package target: `dist/00-sabri-membership-core-1.2.5.zip`
- Authoritative master-plan artifact: `00-Sabri-Membership-Core-Complete-Master-Plan-2026-Four-Round-Reviewed-Final.docx`
- Master-plan SHA-256: `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Requirement traceability: `F00-R001` through `F00-R100`
- Exact-head corrective CI: **Pending on the proposed 1.2.5 pull request**
- Staging installation candidate: **No until exact-head CI passes**
- Staging accepted: **No**
- Production approval: **No**

Version 1.2.5 performs a fresh completion-hardening review after 1.2.4 and corrects:

- professional dual-review finalization deadlock: independent votes persist until a senior finalizer acts;
- stale approval inheritance: votes count only against the exact submitted evidence snapshot;
- privacy erasure resurrection: a persistent lock outranks Founder/Administrator institutional precedence and application absence;
- non-atomic erasure and audit-chain corruption risk: active records delete transactionally while hash-chained audit rows remain unchanged under retention;
- account/role recreation after erasure;
- recovery-code receipt deletion before successful decryption;
- partial two-factor setup after receipt failure;
- reviewer contact status drift from canonical assertions.

Version 1.2.4 remains the authorization-boundary and master-plan traceability foundation. Version 1.2.3 remains the institutional lifecycle repair foundation. Version 1.2.2 remains the institutional/application precedence foundation.

## Canonical boundaries

File 00 owns membership eligibility, identity assurance, verified guardian consent, membership state, two-factor membership assertions, privacy/retention, and membership audit evidence. It does not duplicate File 02 authentication, File 03 profiles, File 09 doctor credentials, File 17 communications, File 19 notifications, File 20 shell/navigation, or Files 21–25 domain ownership.

See `docs/FILE-00-MASTER-PLAN-2026.md`, `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.5.md`, `docs/COMPLETION-HARDENING-AUDIT-1.2.5.md`, `ARCHITECTURE.md`, `SECURITY.md`, and `RELEASE-1.2.5.md`.

## Commands

```bash
npm ci --ignore-scripts
npm run verify
```

Local or CI checks do not authorize live installation. Hostinger staging acceptance, real providers, cross-plugin runtime, browser/accessibility, backup/restore/rollback, legal approval and Founder acceptance remain mandatory.

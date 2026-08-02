# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, privacy lifecycle and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.5`
- Contract version: `1.1.2`
- Database schema version: `1.2.0` — unchanged; no structural database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package: `dist/00-sabri-membership-core-1.2.5.zip`
- Verified package SHA-256: `442adaf73cdef8859edf45b241cc1abffa9f073a9ee3e2fef8d6f7670b80f385`
- Corrective head: `eade3f32784d56f91cbfa5731f965f32c89f6d43`
- Corrective GitHub Actions run: `30756909004` — **passed**
- Workflow artifact: `8836209197`
- Corrective PR: `#8`
- Merge commit: `ce292b22a6af14f7c7efe7d6efe5fe505e70444f`
- Authoritative master-plan artifact: `00-Sabri-Membership-Core-Complete-Master-Plan-2026-Four-Round-Reviewed-Final.docx`
- Master-plan SHA-256: `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Requirement traceability: `F00-R001` through `F00-R100`
- Staging installation candidate: **Yes**
- Staging accepted: **No**
- Production approval: **No**

Version 1.2.5 completed two fresh adversarial review-and-correction rounds after 1.2.4 and corrects:

- professional dual-review finalization deadlock: independent votes persist until a senior finalizer acts;
- stale approval inheritance: votes count only against the exact submitted evidence snapshot;
- stale votes after resubmission/appeal: applicant generation is locked and advanced atomically in both canonical records;
- privacy erasure resurrection: a persistent lock outranks Founder/Administrator institutional precedence and application absence;
- non-atomic erasure and audit-chain corruption risk: active records delete transactionally while hash-chained audit rows remain unchanged under retention;
- unsafe erasure completion when private storage or completion-audit evidence is unavailable: both states remain fail-closed and retryable;
- account/role recreation after erasure;
- recovery-code receipt deletion before successful decryption;
- partial two-factor setup after receipt failure;
- reviewer contact status drift from canonical assertions.

Version 1.2.4 remains the authorization-boundary and master-plan traceability foundation. Version 1.2.3 remains the institutional lifecycle repair foundation. Version 1.2.2 remains the institutional/application precedence foundation.

## Verified automated evidence

- completion hardening contract: **35 PASS, 0 FAIL**;
- approval-gate runtime: **5 PASS, 0 FAIL**;
- privacy-erasure runtime: **3 PASS, 0 FAIL**;
- resubmission-generation runtime: **4 PASS, 0 FAIL**;
- all inherited source, membership-state, institutional-lifecycle, authorization-boundary and master-plan traceability suites: **passed**;
- deterministic ZIP, manifest, CRC, unsafe-path and symlink verification: **passed**.

## Canonical boundaries

File 00 owns membership eligibility, identity assurance, verified guardian consent, membership state, two-factor membership assertions, privacy/retention, and membership audit evidence. It does not duplicate File 02 authentication, File 03 profiles, File 09 doctor credentials, File 17 communications, File 19 notifications, File 20 shell/navigation, or Files 21–25 domain ownership.

See `docs/FILE-00-MASTER-PLAN-2026.md`, `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.5.md`, `docs/COMPLETION-HARDENING-AUDIT-1.2.5.md`, `ARCHITECTURE.md`, `SECURITY.md`, and `RELEASE-1.2.5.md`.

## Commands

```bash
npm ci --ignore-scripts
npm run verify
```

The verified repository package is a Hostinger staging installation candidate. CI does not authorize live installation. Hostinger staging acceptance, real providers, cross-plugin runtime, browser/accessibility, backup/restore/rollback, legal approval and Founder acceptance remain mandatory.

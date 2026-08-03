# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, privacy lifecycle and verification governance for the Sabri Social Homeopathy Platform.

## Current verified forty-review release

- Version: `1.2.8`
- Contract version: `1.1.2`
- Database schema version: `1.2.0`
- Forty consecutive review-and-correction cycles: **40/40 completed and exact-head verified**
- Final verified PR head: `e8ff52477f61a6cf446390afb337201338dabab2`
- Final Dual-Plan QA run: `30853368958` — **success**
- PHP 7.4/8.3 and CF-01 integrity run: `30853369022` — **success**
- Requirement traceability: `F00-R001` through `F00-R100`
- Deterministic package: `dist/00-sabri-membership-core-1.2.8.zip`
- Package SHA-256: `544395db2bb4d798dd9bcc44c14ae61b56d844568086859ecb11679413905adc`
- Workflow artifact: `8871478716`
- Runtime merge to `main`: `ca6d73e76b904512863617cd441eb85150a03b4a`
- Staging installation candidate: **Yes**
- Staging accepted: **No**
- Production approval: **No**

## Material 1.2.8 corrections

Release 1.2.8 hardens approval evidence locking, identity-document freshness, private filesystem containment, deferred deletion, lifecycle concurrency, contact OTP ordering, TOTP replay, revoked-session containment, exact session revocation, inactivity, recovery-code atomicity, audit-chain verification and CF-01 step-up evidence. Every confirmed defect is recorded in `docs/FORTY-ROUND-REVIEW-1.2.8.md` and guarded by `qa/forty-round-contract.mjs`.

## Canonical boundary

File 00 owns membership legitimacy, identity assurance, verified guardian consent, membership state, membership security assertions, privacy/retention and membership audit evidence. It does not own File 02 credentials, File 03 public profiles, File 09 professional credential truth, File 17 communications, File 19 transport, File 20 shell/navigation, Files 21–23 publishing, File 24 assurance governance, File 25 public presentation or CF-01 clinical-object authorization.

## Verification

```bash
npm ci --ignore-scripts
npm run verify
```

Repository completion is not Hostinger staging, legal, real-provider, cross-plugin, browser/accessibility, load/recovery or Founder production acceptance.

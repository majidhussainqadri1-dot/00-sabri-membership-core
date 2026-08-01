# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.2`
- Contract version: `1.1.1`
- Database schema version: `1.2.0` — unchanged; no database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package target: `dist/00-sabri-membership-core-1.2.2.zip`
- Static state-contract regression: `qa/membership-state-contract.mjs`
- Runtime authority matrix: `qa/membership-state-runtime.php`
- Staging approval: **Pending**
- Production approval: **No**

Version 1.2.2 corrects institutional/application precedence. A canonical Founder or WordPress Administrator may carry a historic File 00 application row from an earlier membership workflow. Draft, submission, review, more-information, approval-pending, approved, or expired-evidence rows no longer cancel that institutional authority. The underlying application remains explicitly observable through `application_exists` and `application_status`.

Explicit disciplinary and erasure states remain controlling and fail closed: `rejected`, `suspended`, `appeal_review`, and `erasure_pending`. Ordinary accounts remain governed by their exact membership application state.

Version 1.2.1 introduced the explicit `smc_membership_state()` API and separated a truly absent application from a real draft application. Version 1.2.2 completes that contract for institutional accounts that already have legacy application records.

The verified original `1.0.1` archive remains under `source-archive/` with its canonical checksum. The previously corrupt GitHub checkout copy was restored from the verified 59,278-byte artifact.

## Canonical boundaries

File 00 owns membership eligibility, identity assurance, verified guardian consent, membership state, two-factor membership assertions, privacy/retention, and membership audit evidence. It does not duplicate File 02 authentication, File 03 profiles, File 09 doctor credentials, Files 04/06 publishing, File 17 communications, File 19 notifications, or File 20 shell/navigation.

See [ARCHITECTURE.md](ARCHITECTURE.md), [SECURITY.md](SECURITY.md), and [QA-REPORT-1.2.0.md](QA-REPORT-1.2.0.md).

## Commands

```bash
npm install --ignore-scripts
npm run verify
```

Local checks do not authorize live installation. Hostinger staging acceptance remains mandatory.

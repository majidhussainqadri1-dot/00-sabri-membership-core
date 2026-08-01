# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.1`
- Contract version: `1.1.0`
- Database schema version: `1.2.0` — unchanged; no database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package target: `dist/00-sabri-membership-core-1.2.1.zip`
- Focused state-contract regression: `qa/membership-state-contract.mjs`
- Staging approval: **Pending**
- Production approval: **No**

Version 1.2.1 corrects the Membership Core state contract that previously returned `draft` both for a real draft application and for an account with no application row. The public `smc_membership_state()` API now reports `application_exists`, a distinct `not_enrolled` state, and an explicit institutional account classification. Canonical Founder and WordPress Administrator accounts that predate File 00 are reported as verified institutional accounts while every real application state remains authoritative.

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

# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.0`
- Editable source: `source/sabri-membership-core/`
- Deterministic package: `dist/00-sabri-membership-core-1.2.0.zip`
- Package SHA-256: `f6a5f531be977b8f824852499c5d9fa0738791aab7b9b3354a934be7cfb88436`
- Local automated assertions: `561/561`
- Baseline findings mapped to implemented controls: `181/181`
- Staging approval: **Pending**
- Production approval: **No**

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

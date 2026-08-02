# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.4`
- Contract version: `1.1.3`
- Database schema version: `1.2.0` — unchanged; no structural database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package target: `dist/00-sabri-membership-core-1.2.4.zip`
- Authoritative master-plan project artifact: `00-Sabri-Membership-Core-Complete-Master-Plan-2026-Four-Round-Reviewed-Final.docx`
- Master-plan SHA-256: `3b1f81aa8aed39c76be9e6e2da3eef4e6671a581c13c359f52d163c9bbc6bc9d`
- Requirement traceability: `F00-R001` through `F00-R100`
- Staging approval: **Pending**
- Production approval: **No**

Version 1.2.4 audits the 1.2.3 runtime against the four-round reviewed master plan and corrects authorization-boundary defects:

- explicit hard blocks now control File 00/platform capabilities and protected requests even for a WordPress Administrator;
- broad `smc_*` and `sa_*` recovery-prefix exemptions are replaced by exact allowlists;
- effective eligibility requires guardian validity, verified ordinary-account email/mobile ownership, and a current session challenge for protected actions;
- safe/public reads are separated from protected REST mutations;
- ordinary Founder reassignment is locked after configuration;
- client age rules derive from the canonical server policy;
- the complete plan and 100-row implementation/evidence map are checksum-governed and tested.

Version 1.2.3 remains the institutional lifecycle repair foundation. Version 1.2.2 remains the institutional/application precedence foundation.

## Canonical boundaries

File 00 owns membership eligibility, identity assurance, verified guardian consent, membership state, two-factor membership assertions, privacy/retention, and membership audit evidence. It does not duplicate File 02 authentication, File 03 profiles, File 09 doctor credentials, Files 04/06 publishing, File 17 communications, File 19 notifications, or File 20 shell/navigation.

See [the authoritative plan index](docs/FILE-00-MASTER-PLAN-2026.md), [implementation traceability](docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.4.md), [runtime audit](docs/FOUR-ROUND-RUNTIME-AUDIT-1.2.4.md), [ARCHITECTURE.md](ARCHITECTURE.md), [SECURITY.md](SECURITY.md), and [RELEASE-1.2.4.md](RELEASE-1.2.4.md).

## Commands

```bash
npm install --ignore-scripts
npm run verify
```

Local and GitHub checks authorize a staging candidate only. Hostinger staging acceptance remains mandatory.

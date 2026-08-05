# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, multiple role grants, security assertions, privacy lifecycle and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective candidate

- Version: `1.2.10`
- Contract: `1.1.2`
- Database schema: `1.3.0`
- Review method: forty fresh review → correction rounds against the current 1.2.9 main state
- Newly corrected groups: guardian rollback, Safe Mode, idempotency recovery, repair/outbox targeting, restore evidence, key minimization, document eligibility, corrupt drafts and backup inventory
- Exact-head GitHub Actions: **pending**
- Repository-correctable known defects after local corrective pass: **0 pending CI confirmation**
- Staging accepted: **No**
- Production/live approval: **No**

## Verification

```bash
npm ci --ignore-scripts
npm run verify
```

See `docs/FORTY-ROUND-REVIEW-1.2.10.md`, `docs/DUAL-PLAN-CODE-COMPLETION-1.2.10.md` and `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.10.md`.

Repository code completion never substitutes for Hostinger staging, real providers, cross-module runtime acceptance, browser/accessibility, load/recovery, legal approval or Founder production acceptance.

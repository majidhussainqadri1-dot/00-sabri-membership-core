# File 00 — Sabri Membership Core

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

## Current corrective release

- Version: `1.2.3`
- Contract version: `1.1.2`
- Database schema version: `1.2.0` — unchanged; no structural database migration is required
- Editable source: `source/sabri-membership-core/`
- Deterministic package target: `dist/00-sabri-membership-core-1.2.3.zip`
- State-contract regression: `qa/membership-state-contract.mjs`
- Runtime authority matrix: `qa/membership-state-runtime.php`
- Institutional lifecycle contract: `qa/institutional-lifecycle-contract.mjs`
- Institutional lifecycle runtime matrix: `qa/institutional-lifecycle-runtime.php`
- Staging approval: **Pending**
- Production approval: **No**

Version 1.2.3 corrects an automated lifecycle defect discovered through live audit evidence. The daily age recheck could treat missing, legacy, or unreadable date-of-birth evidence on a canonical Founder or WordPress Administrator as an ordinary membership eligibility failure, write `suspended`, revoke sessions, and consequently make File 22 report `membership_hard_block`.

The correction now:

- protects canonical Founder and Administrator accounts from automatic disciplinary suspension caused only by age-evidence processing;
- records a privacy-safe institutional evidence-attention event instead of silently granting evidence completeness;
- repairs only an existing institutional `suspended` row whose latest matching tamper-evident audit event proves `age_eligibility_failed` was the source;
- restores the corresponding non-disciplinary verification-request state, or `draft` when no safe state exists;
- leaves manual rejection, manual suspension, appeal review, erasure, corrupt states, and ordinary-member controls fail-closed;
- runs the bounded repair once on the 1.2.3 administrator upgrade path even though the database schema remains `1.2.0`.

Version 1.2.2 remains the institutional/application precedence foundation. A canonical Founder or WordPress Administrator may carry a historic File 00 application row without allowing ordinary draft, submission, review, approval-pending, approved, or expired-evidence states to cancel institutional authority.

The verified original `1.0.1` archive remains under `source-archive/` with its canonical checksum.

## Canonical boundaries

File 00 owns membership eligibility, identity assurance, verified guardian consent, membership state, two-factor membership assertions, privacy/retention, and membership audit evidence. It does not duplicate File 02 authentication, File 03 profiles, File 09 doctor credentials, Files 04/06 publishing, File 17 communications, File 19 notifications, or File 20 shell/navigation.

See [ARCHITECTURE.md](ARCHITECTURE.md), [SECURITY.md](SECURITY.md), and [QA-REPORT-1.2.0.md](QA-REPORT-1.2.0.md).

## Commands

```bash
npm install --ignore-scripts
npm run verify
```

Local checks do not authorize live installation. Hostinger staging acceptance remains mandatory.

# File 00 — Sabri Membership Core

**Current V12.3 source-only corrective candidate:** `1.2.47` (File03 independent age assurance), public membership contract `1.2.3`, DB schema `1.4.5`. This identity is distinct from historical `1.2.44` and other unmerged `1.2.45` proposals.

## Ownership and compatibility

File 00 alone owns membership eligibility, encrypted date of birth, age/guardian policy, profile-age assurance, identity, role verification, erasure locks and membership claims. The File 03 consumer obtains a read-only purpose-bound assertion from `smc_profile_age_guardian_claim_v1`. File 00 never recursively calls its full membership assertions from that filter, never exposes the raw DOB or exact age, and does not grant clinical authorization.

The source candidate retains the previously merged File02 1.2.4 compatibility boundary and the Founder-approved retirement of File00-owned MFA; File02 remains the authentication authority. No schema migration is introduced by 1.2.47. See `RELEASE-1.2.47.md`.

## Repository verification

```bash
npm ci --ignore-scripts
npm run verify
```

**NO-GO for deployment without actual host evidence.** Repository CI, package and frozen SHA are not Staging/Live truth. First capture installed plugin versions/files/checksums, DB/options, migration and logs, then review cross-file parity, Staging acceptance, backup restore, rollback, Founder approval and Live re-test. Historical statuses and PRs must not be substituted for deployed evidence.

# File 00 — Sabri Membership Core

Current corrective candidate: plugin `1.2.32`, public contract `1.2.1`, database schema `1.4.3`.

This release repairs the proven `smc_audit_log`-present / `smc_audit_tail`-missing upgrade state. File 00 now recognizes the original `1.0.1` audit-table shape, preserves every pre-HMAC row unchanged, seals its exact lower-assurance snapshot with a keyed create-once migration anchor, and starts the modern HMAC epoch without pretending that historical unhashed rows were cryptographically verifiable.

Unknown schemas, changed legacy snapshots, invalid modern hashes or links, key/anchor mismatches, recovery races, and previously initialized partial schemas remain fail-closed.

```bash
npm ci --ignore-scripts
npm run verify
```

Candidate ZIP SHA-256: `7463935f3105ca8936284157b7d644ab85b982e42cfb353b6dc7ba90b8206c03`.

Local deterministic packaging and static checks pass. GitHub PHP 7.4/8.3 runtime gates and controlled WordPress/MySQL staging acceptance remain separate required evidence.

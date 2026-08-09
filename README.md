# File 00 — Sabri Membership Core

Current corrective candidate: plugin `1.2.33`, public contract `1.2.1`, database schema `1.4.4`.

This release repairs the live `smc_audit_log`-present / `smc_audit_tail`-missing transition that reached historical row 16. File 00 now verifies old modern rows against trusted historical key generations, including the pre-1.2.19 literal encoded-key derivation, while new rows authenticate an explicit `audit_key_id`.

An interrupted additive bridge is recoverable only when unchanged unhashed rows form one exact legacy prefix and a trusted HMAC proves the first modern epoch row. No historical row is deleted, rewritten, backfilled, or re-signed. Unknown schemas, invalid hashes/links, unavailable key generations, anchor mismatches, races, and previously initialized partial schemas remain fail-closed.

```bash
npm ci --ignore-scripts
npm run verify
```

Candidate ZIP SHA-256: `a10977e63bfe31774e13e41b25d0069c535ff0e8b7476979c981a960686da483`.

Local deterministic packaging, PHP syntax parsing, static contracts, manifest, CRC, and archive-safety checks pass. GitHub PHP 7.4/8.3 runtime gates and controlled WordPress/MySQL staging acceptance remain separate required evidence.

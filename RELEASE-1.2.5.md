# File 00 — Release 1.2.5

## Classification

Corrective staging candidate after exact-head CI; not production-approved by code alone.

## Runtime identity

- Plugin: `1.2.5`
- Public contract: `1.1.2`
- Database schema: `1.2.0`
- Structural migration: none

## Corrections

- Professional dual-review votes persist until senior finalization; no second-vote rollback/deadlock.
- Approval votes are bound to the exact submitted evidence snapshot; stale votes do not count.
- Canonical email/mobile assertions power the reviewer status display.
- Privacy erasure is fail-closed before deletion, cannot resurrect Founder/Administrator membership, and blocks account/role recreation.
- File 00 record erasure is transactionally retryable; tamper-evident audit-chain rows are preserved unchanged under retention.
- Recovery-code receipts decrypt before deletion; incomplete two-factor setup rolls back safely.

## Mandatory verification

Run `npm ci --ignore-scripts && npm run verify`. Promote only the exact resulting ZIP/checksum after Hostinger staging acceptance and the remaining external Definition-of-Done gates.

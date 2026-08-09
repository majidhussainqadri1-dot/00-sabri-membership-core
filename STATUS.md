# File 00 Status — 1.2.33

- Runtime / public contract / DB schema: `1.2.33` / `1.2.1` / `1.4.4`.
- Legacy audit rows: preserved unchanged and explicitly labeled lower assurance.
- Legacy bridge: exact schema inspection, trusted historical-key verification, keyed create-once v2 snapshot anchor, forward HMAC epoch, and tail-only reconstruction.
- New audit rows authenticate `audit_key_id`; pre-1.2.33 rows retain their original HMAC format and are never rewritten.
- Transaction-owned audit calls use a read-only readiness gate, so schema DDL cannot implicitly commit a sensitive outer transaction.
- Automatic recovery remains denied for unknown schema, modern-chain corruption, unavailable key generation, anchor/key mismatch, concurrent change, or previously initialized partial schema.
- Candidate ZIP SHA-256: `a10977e63bfe31774e13e41b25d0069c535ff0e8b7476979c981a960686da483`.
- Local source/static/package integrity: passing (581 source assertions, all static contracts, deterministic ZIP, exact manifest, CRC, and archive-safety checks).
- GitHub PHP 7.4/8.3 runtime checks: pending on the candidate commit.
- Controlled staging, live deployment, and operational acceptance: pending.

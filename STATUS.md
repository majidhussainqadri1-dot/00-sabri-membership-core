# File 00 Status — 1.2.32

- Runtime / public contract / DB schema: `1.2.32` / `1.2.1` / `1.4.3`.
- Legacy audit rows: preserved unchanged and explicitly labeled lower assurance.
- Legacy bridge: exact schema inspection, keyed create-once snapshot anchor, forward HMAC epoch, tail-only reconstruction.
- Automatic recovery remains denied for unknown schema, modern-chain corruption, anchor/key mismatch, concurrent change, or previously initialized partial schema.
- Candidate ZIP SHA-256: `7463935f3105ca8936284157b7d644ab85b982e42cfb353b6dc7ba90b8206c03`.
- Local source/static/package integrity: passing.
- GitHub PHP 7.4/8.3 runtime checks: pending on the candidate commit.
- Controlled staging, live deployment, and operational acceptance: pending.

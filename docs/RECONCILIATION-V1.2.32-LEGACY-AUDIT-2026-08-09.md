# File 00 v1.2.32 legacy-audit reconciliation

This reconciliation starts from current merged `main`, overlays the verified File 00 v1.2.32 plugin source, and carries forward PR #33's fail-closed bridge for genuine pre-HMAC `smc_audit_log` history with a missing `smc_audit_tail`.

Safety invariants:

- no historical audit row is deleted, reset, rewritten, or silently re-hashed;
- exact supported legacy schema is required;
- the legacy prefix remains explicitly lower assurance (`legacy_snapshot_only`);
- a create-once keyed migration anchor binds the deterministic snapshot;
- only forward records enter the modern HMAC epoch;
- modern hash/link corruption, key mismatch, unknown schema, incompatible columns, or concurrent mutation fail closed;
- current-main Hostinger/button QA is retained alongside the focused legacy bridge regression.

Target identity: runtime `1.2.32`, DB schema `1.4.3`, public contract `1.2.1`.

Repository merge/QA remains distinct from live Hostinger acceptance; the live site must retest the existing audit history after deployment.

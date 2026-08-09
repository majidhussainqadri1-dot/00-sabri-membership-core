# File 00 1.2.33 — Historical Audit-Key Transition and Interrupted Bridge

## Invariants

1. Historical audit rows are immutable.
2. A key candidate may verify old evidence but cannot become the new write key merely because it exists in the historical keyring.
3. Rows with `audit_key_id` must verify under that exact generation; unrelated keys are not tried.
4. Rows without `audit_key_id` may verify under trusted pre-key-ID generations because their original format carried no generation field.
5. A legacy prefix is never called cryptographically verified. Its v2 anchor states `legacy_snapshot_only`.
6. An already-columnized bridge is eligible only when a verified first modern HMAC row proves the epoch boundary after the contiguous legacy prefix.
7. Serializer-tail reconstruction follows complete row and anchor verification and never moves or repairs an audit row.
8. Audit schema DDL cannot run inside an existing business transaction.

## Required regression evidence

- exact 1.0.1 schema and additive bridge;
- pre-1.2.19 literal encoded-key row;
- current decoded-key row with authenticated `audit_key_id`;
- v1 and v2 anchor verification;
- mixed key epochs and matching tail;
- wrong known-key HMAC rejection;
- unavailable explicit generation rejection;
- changed legacy snapshot rejection;
- late unhashed-row rejection;
- deterministic build, manifest, ZIP and PHP 7.4/8.3 runtime gates.

## Live staging gate

Before production, record the existing audit-row count and a read-only physical snapshot digest, deploy v1.2.33 to an isolated sanitized clone, confirm record 16 under an available trusted historical generation, verify v2 anchor persistence and tail binding, complete a fresh TOTP enrollment, then prove the original row count/content is unchanged except for forward-only new events.

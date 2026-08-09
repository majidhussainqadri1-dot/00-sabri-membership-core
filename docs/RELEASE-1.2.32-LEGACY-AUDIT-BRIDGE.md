# File 00 1.2.32 — Legacy Audit Snapshot to HMAC Epoch Bridge

## Root cause

File 00 `1.0.1` created `smc_audit_log` with `subject_user_id`, `object_type`, and `object_id`, but without `subject_hash`, `previous_hash`, or `row_hash`. A later guarded bootstrap correctly refused to create a missing `smc_audit_tail` over non-empty history, while `1.2.31` incorrectly attempted to verify those genuine pre-HMAC rows as modern HMAC records and reported `smc_audit_partial_rows_invalid`.

The old rows do not contain enough evidence to reconstruct or verify historical HMACs. `1.2.32` therefore does not rewrite them and does not call them cryptographically verified.

## Recovery boundary

The bridge proceeds only when all of these conditions hold:

1. `smc_audit_log` exists, `smc_audit_tail` is missing, and the audit schema was never marked initialized.
2. The surviving table matches the supported legacy column names and definitions; unknown or incompatible shapes fail closed.
3. Unhashed records form one contiguous prefix. No unhashed record may appear after a modern HMAC record.
4. Every modern suffix record, if present, passes `previous_hash` and keyed `row_hash` verification.
5. The complete physical row snapshot remains identical before and after the additive schema bridge.
6. A create-once, domain-separated HMAC anchor over the exact legacy prefix is persisted and read back successfully.
7. The audit log remains unchanged while the serializer tail is created and bound to the final modern HMAC hash (or the empty start-of-epoch hash when the table contains only legacy rows).

Any key change, anchor mismatch, row mutation, schema mismatch, race, invalid modern hash/link, or pre-existing initialization marker stops recovery.

## Data treatment

- No audit row is deleted, reset, re-signed, or backfilled with invented hashes.
- Only missing modern columns are added; existing incompatible hash columns are rejected.
- The legacy snapshot has explicit assurance `legacy_snapshot_only`.
- All new records continue in the normal append-only HMAC chain.
- Administrator diagnostics expose only non-secret state/reason identifiers, including the legacy anchor state.

## Compatibility

File 22 requires File 00 plugin `>=1.2.3`, DB schema `>=1.2.0`, and public contract `>=1.1.2`. File 00 `1.2.32 / 1.4.3 / 1.2.1` remains compatible without changing File 22 ownership or authorization boundaries.

## Candidate package

- Archive: `00-sabri-membership-core-1.2.32.zip`
- SHA-256: `7463935f3105ca8936284157b7d644ab85b982e42cfb353b6dc7ba90b8206c03`
- Plugin PHP files: 19
- Embedded manifest entries: 22
- ZIP unsafe paths, symlinks, manifest mismatches, and CRC failures: 0

Repository CI performs PHP 7.4/8.3 syntax and runtime suites, Node/static contracts, deterministic rebuild, manifest verification, and archive verification. Controlled WordPress/MySQL staging remains mandatory before production promotion.

# File 00 — Sabri Membership Core 1.2.44

## Purpose

Correct the proven File 00 canonical-account-taxonomy / `smc.authentication-account` provider-vocabulary drift without a lossy File 02 remap.

## Exact source lineage

- Pre-release-correction root-cause head: `249b5dc40f1936cc805709bed146e602ec67732a`.
- Frozen review evidence: `REVIEW-1.2.44-TAXONOMY-FROZEN.md`.
- Paired File 02 candidate: `1.2.4` at exact SHA `950f4bd3f63e08304e3afafa501196919223ab20`.

## Corrected contract behavior

- `SMC_Authentication_Contract_V11::validate_extra_fields()` derives its allowlist from `array_keys( smc_account_types() )`.
- Accepted canonical account values: `member`, `patient`, `student`, `doctor`, `teacher`, `researcher`, `pharmacy`, `clinic`, `publisher`.
- Provider-only aliases `clinic_staff` and `institution_representative` are rejected; File 02 performs no lossy remap.

## Identity

- Runtime: `1.2.44`.
- DB schema: `1.4.5` unchanged.
- Public membership contract: `1.2.3` unchanged.
- `smc.authentication-account`: `1.1.0` unchanged.

## Completion boundary

This release record is repository/source evidence. It does not claim staging acceptance, live deployment, DB migration completion or operational resolution. Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

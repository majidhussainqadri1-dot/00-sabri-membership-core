# File 00 — 1.2.44 Taxonomy/Provider Parity Review Frozen Before Release Correction

Reviewed exact pre-release-correction head: `249b5dc40f1936cc805709bed146e602ec67732a`.

The root-cause correction already present at that head changes the `smc.authentication-account` v1.1 provider validation from a duplicated hard-coded vocabulary to `array_keys( smc_account_types() )`. Its focused source/runtime QA proves the File 00 canonical values `member`, `patient`, `student`, `doctor`, `teacher`, `researcher`, `pharmacy`, `clinic`, and `publisher` are accepted and the provider-only aliases `clinic_staff` and `institution_representative` are rejected.

No 1.2.44 release-identity correction was started before this review was frozen.

## Frozen findings

1. The material provider-behavior correction is still labeled runtime `1.2.43`; deployment parity would therefore be ambiguous. The corrected source requires a distinct runtime identity `1.2.44`.
2. DB identity must remain `1.4.5`, public membership contract must remain `1.2.3`, and `smc.authentication-account` contract remains `1.1.0`; this correction changes provider conformance to its canonical File 00 taxonomy, not the wire contract shape.
3. Current package metadata, current QA/workflow assertions and release evidence that intentionally describe the active runtime must be synchronized to `1.2.44`; historical release records must remain historical.
4. File 00's current File 02 compatibility gate is stale: it must exact-pin the corrected File 02 `1.2.4` candidate `950f4bd3f63e08304e3afafa501196919223ab20` rather than the old File 02 main/release line.
5. Temporary write-capable taxonomy correction machinery must be physically absent from the final File 00 candidate.
6. Staging/live/operational status is not established by this repository correction and must not be claimed.

## Required correction/retest

- Advance File 00 runtime/package identity to `1.2.44` while preserving DB `1.4.5`, membership contract `1.2.3`, authentication-account contract `1.1.0`.
- Update active release/package/QA truth and create a new `RELEASE-1.2.44.md` without rewriting `RELEASE-1.2.43.md`.
- Exact-pin File 02 `1.2.4` at `950f4bd3f63e08304e3afafa501196919223ab20` in the compatibility gate.
- Run complete repository verification plus focused source/runtime taxonomy tests.
- Remove temporary correction workflow before accepting the exact source candidate.

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔

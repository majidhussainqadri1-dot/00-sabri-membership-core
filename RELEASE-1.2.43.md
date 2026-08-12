# File 00 — Sabri Membership Core 1.2.43

## Live-first incident provenance

This release exists because a real production activation attempt of File 02 — Sabri Authentication and Accounts 1.2.0 failed closed with the message that File 00 did not provide `smc.authentication-account` 1.1.0. File 02 stopped before changing its own tables, pages or options.

The exact deployed File 00 v1.2.42 plugin ZIP was then frozen and audited. Its SHA-256 is:

`f64e945bd712a3ccc7a385c82de47ccfaaa338e455af090073d68e5a7386a7d6`

The deployed 24-file payload matched the reviewed File 00 v1.2.42 repository candidate manifest, and the deployed source contained none of:

- `SMC_Authentication_Contract_V11`
- `SMC_AUTHENTICATION_CONTRACT_V11_VERSION`
- the `smc.authentication-account` provider

The root cause is therefore an exact-deployed File 00 branch-lineage / omitted-contract regression. It is not an install-order defect and not a File 02 activation defect.

## 1.2.43 correction

- Runtime release advances to `1.2.43`.
- DB schema remains `1.4.5`; no schema bump is introduced.
- Public membership contract remains `1.2.3`.
- File 01 structured authorization claim remains `1.0.0` for Foundation contract `2.0.0`.
- Restores the reviewed File 00 account-registration transaction helper as an internal helper only.
- Exposes only `SMC_Authentication_Contract_V11` as the active `smc.authentication-account` public provider at contract version `1.1.0`.
- Provides the exact methods required by merged File 02 1.2.0: `register_account`, `mark_email_verified`, and `get_completion_state`.
- Successful registration is bound to File 00's canonical `SMC_CF01_Contract::ensure_subject_uuid()` and returns a validated opaque `subject_uuid`, as required by the exact File 02 consumer.
- Preserves Founder-approved File 00 MFA retirement: historical `two_factor` completion is stripped and the old v1 helper is not initialized as an active contract.
- Uses canonical `SMC_Security::revoke_all_sessions()` for containment instead of restoring historical direct WordPress session destruction.
- Adds source/runtime regression guards and an exact cross-repository compatibility gate pinned to File 02 merged main commit `8192c45b595b34e13e09934e3b2d554aa2d8553f`.
- Adds a real WordPress/MariaDB regression that installs File 00 1.2.43 and proves the exact merged File 02 1.2.0 activation dependency gate accepts the provider.

## Deployment truth

This document records repository/release intent only. It does not prove staging or live deployment.

Required operational sequence for the current incident:

1. Exact-head CI green and deterministic File 00 1.2.43 package retained.
2. Deploy/update File 00 1.2.43 first.
3. Verify File 00 active version, DB version `1.4.5`, bootstrap/migration state and package parity.
4. Activate File 02 1.2.0.
5. Prove File 02 remains active and its account provider is available.
6. Re-test the affected live authentication/Health & Repair integration and confirm parity.

The incident must not be labelled Resolved until live re-test and parity confirmation are complete.

## Incident status at repository correction start

- Repository File 00 baseline main before correction: `9237afb02565bb05233de4e5ca59542c0db3ab37`
- Exact deployed File 00: `1.2.42`
- Exact deployed File 00 ZIP SHA-256: `f64e945bd712a3ccc7a385c82de47ccfaaa338e455af090073d68e5a7386a7d6`
- File 00 DB target: `1.4.5`
- File 02 exact repository main: `8192c45b595b34e13e09934e3b2d554aa2d8553f`
- File 02 attempted runtime: `1.2.0`
- File 02 activation mutation state: stopped before File 02 tables/pages/options
- Live verification status: root cause proven; repository correction required; not yet resolved

# Release 1.2.0

File 00 was reconstructed from the verified 1.0.1 baseline into editable, testable, deterministic source.

## Main changes

- Canonicalized File 00 as membership/identity/guardian/security governance only.
- Delegated authentication, profiles, professional credentials, publishing, communications, notifications, and shell ownership to their proper modules.
- Enforced 0 percent commission, optional donation neutrality, male age 15, female age 12, verified under-18 guardian consent, and age 18 for every professional account.
- Added versioned membership and communication assertions.
- Added fail-closed master-key and external private-storage configuration.
- Added authenticated AES-256-GCM context, blind indexes, atomic file replacement, scanner gating, leases, durable deletion jobs, and secure downloads.
- Added independently verified email/mobile targets, encrypted authenticator secrets, session-bound challenges, TOTP replay prevention, recovery-code atomicity, and per-session revocation.
- Added SMC1 legacy migration with scanning, re-encryption, and verified former-ciphertext deletion.
- Added optimistic reviewer transitions, explicit identity match, exact evidence decisions, maker-checker separation, and dual professional approval.
- Added truthful privacy export/erasure, retention holds, lifecycle review, audit hash chains, localization, RTL, accessible forms, and deterministic packaging.

## Upgrade warning

Upgrade on staging first. Legacy evidence migration is deliberately fail-closed and requires a configured master key, safe external storage, and an active scanner provider. A failed legacy row prevents the database version from advancing.

## Acceptance

Local QA passed. Hostinger staging and production acceptance remain separate and pending.

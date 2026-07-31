# Security and Privacy Operations

## Secrets

- Configure `SMC_MASTER_KEY` outside the repository.
- Back up the key separately from encrypted evidence.
- Treat key rotation as a staged multi-key migration; never replace the key in place.
- Configure `SMC_PRIVATE_STORAGE_DIR` outside `ABSPATH` and `WP_CONTENT_DIR`.

## Required providers

File scanning and OTP delivery are explicit fail-closed integration points:

- `smc_document_scan`
- `smc_send_contact_otp`
- `smc_send_guardian_invitation`

The scanner must cover malware and active PDF content. Provider failures must return `false` or `WP_Error`; no application becomes approvable when a provider is unavailable.

## Incident controls

Suspension, guardian withdrawal, identity expiry, contact change, or approval revokes all WordPress sessions and File 00 session assertions. Failed filesystem deletions remain referenced and enter the durable retry queue. Operators must investigate any `failed` file job before privacy or staging acceptance.

## Staging test minimum

- fresh activation and upgrade from the verified 1.0.1 baseline;
- two concurrent schema workers and two concurrent reviewers;
- disk-full, permission-denied, symlink, database rollback, and lock-release failures;
- scanner accept/reject, email OTP, mobile OTP, and guardian OTP;
- Google OAuth followed by mandatory membership challenge;
- File 03/09/17/19/20 integration contracts;
- export, erasure, active hold, failed deletion, restore, and key-backup rehearsal.

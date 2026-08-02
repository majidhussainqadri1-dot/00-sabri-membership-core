# Security and Privacy Operations

## Secrets

- Configure `SMC_MASTER_KEY` outside the repository using at least 256 bits of cryptographically secure entropy.
- Back up the key separately from encrypted evidence and restrict custody to explicitly authorized operators.
- Treat key rotation as a staged multi-key migration; never replace the key in place.
- Configure `SMC_PRIVATE_STORAGE_DIR` outside `ABSPATH` and `WP_CONTENT_DIR`.

## Required providers

File scanning and OTP delivery are explicit fail-closed integration points:

- `smc_document_scan`
- `smc_send_contact_otp`
- `smc_send_guardian_invitation`

The scanner must cover malware and active PDF content. Provider failures must return `false` or `WP_Error`; no application becomes approvable when a provider is unavailable.

## Authorization controls

- Explicit hard blocks override institutional compatibility authority for File 00/platform capabilities and requests.
- Recovery actions and REST routes are exact allowlists; no namespace prefix is accepted as authorization.
- Protected capabilities and mutations require effective membership, verified ordinary-account email/mobile ownership, guardian validity when applicable, and a current session two-factor challenge.
- Ordinary Founder reassignment is locked after configuration.
- Sensitive REST reads must use their route permission callback and may opt into File 00 enforcement through `smc_rest_request_requires_membership`.

## Incident controls

Suspension, guardian withdrawal, identity expiry, contact change, or approval revokes all WordPress sessions and File 00 session assertions. Failed filesystem deletions remain referenced and enter the durable retry queue. Operators must investigate any `failed` file job before privacy or staging acceptance.

## Staging test minimum

- fresh activation and upgrade from the verified 1.0.1 baseline;
- two concurrent schema workers and two concurrent reviewers;
- hard-blocked Founder/Administrator negative tests for capabilities, admin actions and REST mutations;
- exact recovery-route positive and arbitrary-prefix negative tests;
- disk-full, permission-denied, symlink, database rollback, and lock-release failures;
- scanner accept/reject, email OTP, mobile OTP, and guardian OTP;
- Google OAuth followed by mandatory membership challenge;
- Files 03/08/09/17/18/19/20/21/22/23/24/25 integration contracts;
- export, erasure, active hold, failed deletion, restore, and key-backup rehearsal.

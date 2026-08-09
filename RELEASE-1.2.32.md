# File 00 — Sabri Membership Core 1.2.32

## Runtime identity
- Runtime: `1.2.32`
- Database schema: `1.4.3`
- Public contract: `1.2.1`

## Corrective scope
This candidate contains the original 32 verified GitHub code-only audit corrections and Hostinger live-test hardening through v1.2.32, including deferred activation bootstrap, Hostinger-safe user actions/document submission, File 19 email/SMS provider boundaries, nonce/origin handling, TOTP enrollment diagnostics, audit schema bootstrap/reconciliation, verified missing-tail recovery, and trusted historical audit-key verification.

## Verification
The corrected branch is required to remain PHP/JavaScript syntax-clean, pass the repository runtime/contract suites, build deterministically, and pass package safety verification before merge.

## Acceptance boundary
Repository merge and automated QA do not by themselves establish live production acceptance. The v1.2.32 trusted historical audit-key transition still requires final live-site confirmation against existing audit record 16. Real email/SMS providers, full browser/mobile/accessibility acceptance, isolated backup restore/rollback, independent security review, and final Founder acceptance remain separate gates.

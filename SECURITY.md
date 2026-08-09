# Security — File 00

Current corrective runtime: `1.2.32`; database schema: `1.4.3`; public contract: `1.2.1`.

File 00 uses fail-closed authorization and verification, TOTP/recovery protections, versioned encryption/key handling, tamper-evident audit rows, serialized audit-tail state, controlled bootstrap/recovery, privacy/erasure governance, and transactional membership lifecycle controls.

Audit recovery must not delete, rewrite, or silently re-hash historical audit rows. Missing-tail recovery is permitted only under the guarded integrity logic implemented by the corrective runtime. Trusted historical audit keys may be used to verify immutable legacy rows without changing those rows. A row that cannot be verified by an available trusted key remains a live integrity failure and must not be bypassed.

Repository merge does not itself establish live production acceptance. The v1.2.32 historical-key transition still requires final live confirmation against the site's existing audit record 16; provider, browser/accessibility, restore/rollback, independent security review, and Founder acceptance remain separate gates.

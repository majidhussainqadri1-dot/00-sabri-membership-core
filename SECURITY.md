# Security — File 00

Current corrective runtime: `1.2.33`; database schema: `1.4.4`; public contract: `1.2.1`.

File 00 uses fail-closed authorization and verification, TOTP/recovery protections, versioned encryption/key handling, authenticated per-row audit key IDs, tamper-evident audit rows, serialized audit-tail state, controlled bootstrap/recovery, privacy/erasure governance, and transactional membership lifecycle controls.

Audit recovery must not delete, rewrite, or silently re-hash historical audit rows. Missing-tail recovery is permitted only under the guarded integrity logic implemented by the corrective runtime. Trusted historical audit keys are verification-only and may authenticate immutable rows without changing them. A row that cannot be verified by its explicit key generation or an available trusted pre-key-ID generation remains a live integrity failure and must not be bypassed.

When a caller already owns a transaction, File 00 runs only read-only audit readiness checks. Any required CREATE/ALTER/bootstrap work must complete outside that transaction so MySQL DDL cannot silently commit a partially completed membership or privacy mutation.

Repository merge does not itself establish live production acceptance. The v1.2.33 historical-key transition still requires final live confirmation against the site's existing audit record 16; provider, browser/accessibility, restore/rollback, independent security review, and Founder acceptance remain separate gates.

# File 00 — Architecture

## Canonical ownership
Sabri Membership Core is the canonical authority for membership eligibility, identity assurance, guardian consent, verification governance, membership security assertions, controlled role grants, audit evidence, and lifecycle state required by dependent platform modules.

## Current corrective identity
- Runtime: `1.2.33`
- Database schema: `1.4.4`
- Public contract: `1.2.1`

## Security architecture
The corrective line preserves fail-closed authorization, TOTP/recovery protections, versioned encryption/key handling, per-row audit key-generation identity, tamper-evident audit chaining, serialized audit-tail state, controlled schema/bootstrap recovery, privacy/erasure governance, and transactional lifecycle boundaries. Historical key candidates are verification-only; one selected generation owns new blind-index and audit writes.

## Integration architecture
Authentication remains outside File 00 where canonically owned by the authentication component. Receipt-bearing email/SMS delivery is provided through File 19/configured delivery adapters; File 00 consumes provider evidence rather than falsely declaring contact ownership from local intent alone.

## Acceptance boundary
Repository source/CI completion and live production acceptance are distinct. The v1.2.33 key-transition and interrupted-bridge correction still requires final live confirmation against existing audit record 16; external providers, browser/mobile/accessibility, isolated restore/rollback, independent security review, and Founder acceptance remain separate gates.

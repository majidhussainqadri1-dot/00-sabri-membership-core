# File 00 — Sabri Membership Core 1.2.33

## Runtime identity

- Runtime: `1.2.33`
- Database schema: `1.4.4`
- Public contract: `1.2.1`

## Proven live defect

The v1.2.32 live diagnostic reached audit record 16 with `row_hash_mismatch`, a missing serializer tail, and an invalid legacy snapshot state. Repository documentation said trusted historical audit keys could verify immutable rows, but the runtime inspector tried only one selected key derivation.

Releases before 1.2.19 passed an encoded `base64:` or `hex:` master-key constant to HKDF literally. Later releases correctly decoded that material for current encryption and HMAC writes. An authentic historical row could therefore fail under the newer single-key verifier even though its stored HMAC remained unchanged.

## Corrective scope

- Build an allowlisted, verification-only historical audit keyring while retaining one explicit write generation.
- Preserve pre-1.2.19 literal encoded-key derivations as historical candidates; never use them for new writes.
- Add nullable `audit_key_id varchar(64)` to the audit schema and authenticate it in every new row HMAC.
- Continue verifying pre-1.2.33 rows in their original no-key-ID HMAC format.
- Permit an interrupted additive legacy bridge only when unhashed rows are one exact contiguous legacy prefix and the first modern row verifies as a new HMAC epoch with `previous_hash=''`.
- Create v2 legacy anchors that bind the source shape, exact lower-assurance snapshot, signing generation, and any already-present first modern row; retain v1 anchor verification.
- Use a read-only readiness gate when audit is invoked inside an existing transaction so MySQL DDL cannot implicitly commit an outer sensitive mutation.
- Query recent security events with the canonical subject digest actually stored in the audit chain.
- Retire obsolete self-mutating one-shot workflows and align every active PR package/runtime gate with v1.2.33.

## Non-destructive boundary

The correction never deletes, rewrites, backfills, reorders, or re-signs an audit row. Unknown schemas, unavailable explicit key generations, wrong HMACs, broken links, late unhashed rows, changed snapshots, anchor conflicts, and recovery races remain fail-closed.

## Acceptance boundary

Repository QA and packaging do not establish production acceptance. The installed candidate must still prove the existing record-16 transition, tail reconstruction, successful 2FA enrollment, full-chain verification, rollback, and unchanged historical row snapshots on a sanitized Hostinger staging clone before production promotion.

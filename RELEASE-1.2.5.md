# File 00 — Release 1.2.5

## Classification

Repository-correctable completion hardening and exact-head automated QA verified. This is a Hostinger staging installation candidate, not a production-approved release by code alone.

## Runtime identity

- Plugin: `1.2.5`
- Public contract: `1.1.2`
- Database schema: `1.2.0`
- Structural migration: none
- Corrective head: `eade3f32784d56f91cbfa5731f965f32c89f6d43`
- GitHub Actions run: `30756909004` — passed
- Workflow artifact: `8836209197`
- Pull request: `#8`
- Merge commit: `ce292b22a6af14f7c7efe7d6efe5fe505e70444f`
- Package SHA-256: `442adaf73cdef8859edf45b241cc1abffa9f073a9ee3e2fef8d6f7670b80f385`

## Corrections

- Professional dual-review votes persist until senior finalization; no second-vote rollback/deadlock.
- Approval votes are bound to the exact submitted evidence snapshot; stale votes do not count.
- Resubmission and appeal lock and advance applicant generation atomically so prior votes cannot survive a new applicant generation.
- Canonical email/mobile assertions power the reviewer status display.
- Privacy erasure is fail-closed before deletion, cannot resurrect Founder/Administrator membership, and blocks account/role recreation.
- File 00 record erasure is transactionally retryable; tamper-evident audit-chain rows are preserved unchanged under retention.
- Private-storage discovery and completion-audit failures cannot be reported as completed; both remain retryable.
- Recovery-code receipts decrypt before deletion; incomplete two-factor setup rolls back safely.

## Automated verification

- Completion hardening contract: **35 PASS, 0 FAIL**.
- Approval-gate runtime: **5 PASS, 0 FAIL**.
- Privacy-erasure runtime: **3 PASS, 0 FAIL**.
- Resubmission-generation runtime: **4 PASS, 0 FAIL**.
- Inherited source, membership-state, institutional-lifecycle, authorization-boundary and master-plan traceability suites: **passed**.
- Deterministic package, manifest, CRC, unsafe-path and symlink verification: **passed**.

## Mandatory staging verification

Promote only the exact package matching the recorded SHA-256. Hostinger fresh activation/upgrade, real provider delivery, cross-file runtime, browser/mobile/RTL/accessibility, load, backup/restore/rollback, jurisdiction-specific legal/child-safety review and Founder acceptance remain mandatory before production authorization.

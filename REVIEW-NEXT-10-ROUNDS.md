# File 00 — Supplemental Ten-Round Corrective Review

Baseline: main `2a596b9b342a448a994be8a7452d113ee8f50825` (File 00 1.2.40).

This review is repository-only evidence. It does not claim staging/live deployment or live resolution.

- Round 1 — defect found and corrected: `smc_revoke_all_sessions` was a real self-service security recovery action but was absent from the exact authorization recovery allowlist. Hard-blocked/ineligible users could therefore reach single-session revocation but be redirected before the revoke-all handler. The action is now explicitly allowlisted and regression-tested.
- Round 2 — no new defect proven: privacy export/erasure, retention-hold, fail-closed erasure-lock, file deletion and audit-retention paths were re-reviewed after Round 1.
- Round 3 — no new defect proven: File 02 authentication ownership, File 09 professional-verification boundary, role grants and current identity-document requirements were re-reviewed; no additional repository defect was established in this lens.
- Round 4 — defect found and corrected: protected authorization relied on stored approval plus the daily lifecycle age sweep, so a current jurisdictional minimum/under-18 professional eligibility failure could remain usable until cron. Authorization and the versioned public assertion filter now synchronously recompute current age eligibility and fail closed.
- Round 5 — defect found and corrected: appeal independence was enforced for restore decisions but not at appeal claim time and therefore did not prevent the latest rejection/suspension actor from reclaiming an appeal and reaching a denial path. The canonical authorization boundary now blocks that actor at both appeal claim and transition entry.
- Round 6 — no new defect proven: event outbox/inbox idempotency, stale-claim recovery, retry/dead-letter and audit-coupled event emission were re-reviewed.
- Round 7 — no new defect proven: private storage path constraints, scanner gating, authenticated encryption, document leases, atomic writes and verified deletion/retry paths were re-reviewed.
- Round 8 — no new defect proven: migration locks, schema/index reconciliation, durable migration markers, institutional repair cursor/exhaustion and post-commit repair behavior were re-reviewed.
- Round 9 — no new defect proven: advanced trust, File 02 assurance, containment, delegation, break-glass, reverification and revocation behavior were re-reviewed.
- Round 10 — exact-head release closure is pending CI on the final candidate. Any defect revealed by combined `npm test`, WordPress/MariaDB migration/runtime regression, deterministic package verification or version/package identity will be corrected before repository closure.

Live boundary: exact deployed code, deployed File 00 version, live database/schema version, migration state and live workflow verification remain unverified until separately frozen and re-tested under the Live-First rule.

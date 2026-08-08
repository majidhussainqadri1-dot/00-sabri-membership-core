# File 00 — Eighth Completely Fresh Ten-Round Review — Release 1.2.21

Baseline: exact 1.2.20 candidate `6e73f6f8d7813f6216bd349aa7a82ab2fccee55b`. This is a new independent review series; prior rounds are not recounted.

| Round | Result | Defect | Correction / retest |
|---:|---|---|---|
| 1 | Defect | E8-R1-D01 High — prefix-based `smc_*`/`sa_*` recovery bypass admitted unrelated actions | Explicit non-removable canonical recovery/completion allowlist |
| 2 | Defect | E8-R2-D01 High — `manage_options` bypassed admin/REST membership and current-MFA gates | Removed blanket bypass; explicit recovery routes remain available |
| 3 | Defect | E8-R3-D01 High — reviewer/admin capability alone exposed private profiles cross-subject | Current session + operational actor + assignment/conflict scope; managers require explicit governance capabilities |
| 4 | Defect | E8-R4-D01 High — bare boolean filter could authorize system reverification | Typed owner/version/subject/source/freshness authorization claim |
| 5 | Defect | E8-R5-D01 Medium — filter/stored due date could weaken annual reverification indefinitely | Interval capped to annual maximum; stored due date clamped fail-closed |
| 6 | Defect | E8-R6-D01 High — overdue hold could suppress retry after audit/revocation propagation failure | Separate due-bound propagation receipt; retry until audit+revocation+receipt succeed |
| 7 | Defect | E8-R7-D01 High — Founder/Admin service-identity mutation bypassed operational-state/adaptive step-up | Institutional actor remains operationally gated; service identity gets dedicated phishing-resistant step-up |
| 8 | Defect | E8-R8-D01 High — minimal `session_step_up_current` could be true while membership/revalidation was stale; E8-R8-D02 Medium — merge observer exposed raw local IDs/request | Operational/fresh step-up assertion; durable audit event map plus opaque observer subjects and exception containment |
| 9 | Defect | E8-R9-D01 Medium — malformed break-glass rows could survive forever and exhaust capacity | Structural/TTL/subject/id validation added to pruning |
| 10 | Defect | E8-R10-D01 High — cross-subject trust timeline used blanket management capability without purpose-bound authorization | Typed actor/subject/purpose/freshness-bound access claim required |

Total: **10 fresh rounds; defects in 10/10 rounds; 11 unique defects corrected.** Severity: **8 High, 3 Medium, 0 Critical, 0 Low**. Known unresolved repository defects in this reviewed scope are zero after final exact-head CI. External staging/live/operational gates remain separate.

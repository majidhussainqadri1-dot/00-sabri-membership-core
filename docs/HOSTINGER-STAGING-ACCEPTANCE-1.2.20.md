# File 00 — Hostinger Staging Acceptance Delta — 1.2.20

This document inherits every real-environment gate from `HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md` and records only the seventh-review deltas. It is not evidence that Hostinger staging has passed.

## Candidate matrix

| File | Current staging evidence | Status |
|---|---|---|
| 00 Membership Core | runtime 1.2.20; final exact head and artifact supplied by GitHub CI/PR evidence | required candidate |
| 01 Foundation | previously pinned candidate | required |
| 02 Authentication | GitHub PR #7 remains 1.2.0; governing File 02 v2.3 local-reviewed runtime is 1.3.2, Authentication Assurance v2 contract 2.0.0 | **BLOCKED — current-plan GitHub exact-head candidate absent** |
| 03 Profiles | previously pinned candidate | required |
| 08 Clinic/Appointments | exact-head package previously pinned | required |
| 09 Doctor Verification | previously pinned candidate | required |
| 12 PDF Library | source/artifact wrapper pinned; inner installable identity still pending | required / partial package evidence |
| 17 Communication | previously pinned candidate | required |
| 18 Marketplace | exact-head package previously pinned | required |
| 19 Notifications | previously pinned candidate | required |
| 20 Unified Shell | previously pinned candidate | required |
| 21 Home/News | previously pinned candidate | required |
| 22 Composer | previously pinned candidate | required |
| 23 Publishing Dashboard | previously pinned candidate | required |
| 24 Security/Privacy | previously pinned candidate | required |
| 25 Visual Experience | previously pinned candidate | required |

## Preflight blocker — File 02 governing-plan freshness

File 00 now consumes the additive `smc_file02_authentication_assurance_v2` contract with contract version `2.0.0` and preserves v1 only when v2 is absent. A malformed or stale v2 receipt fails closed and cannot silently downgrade to v1. Final integrated staging acceptance remains blocked until File 02 v2.3/runtime 1.3.2 is synchronized to GitHub and exact-head CI/package evidence is pinned.

## Truthful status

Repository code/package/automated QA may be accepted independently. `Staging-Accepted`, `Live-Deployed`, and `Operational` remain false until real Hostinger, provider, browser/accessibility, backup/restore, rollback, and Founder-acceptance evidence is attached.

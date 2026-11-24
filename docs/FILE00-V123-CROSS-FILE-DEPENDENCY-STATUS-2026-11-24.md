# File 00 V12.3 — dependency and PR disposition (2026-11-24)

## Owner boundary
File00 is the canonical owner of membership eligibility, identity assurance, guardian consent and versioned trust assertions. It is not the owner of login/session authentication (File02), doctor verification decisions (File09), clinic/appointment records (File08), research-case consent records (File06), shell navigation (File20), visual tokens/components (File25), or independent release/security assurance (File24).

| Companion | Observed repository evidence | Required disposition |
|---|---|---|
| File01-B | PR #16 draft, updated 2026-11-23; File00 claim bridge exists in source | freeze exact consumer contract and test action/object/purpose binding |
| File02 | PR #28, #27, #26, #25, #24 and earlier open drafts | authenticate and step-up independently; revocation and recovery must be paired |
| File03 | PR #43, #42 and earlier open drafts | pair with File00 #61; unknown age remains fail-closed; do not expose DOB/age |
| File04 | File00 PR #52 conflict | reconcile historical author sentinel and actor attribution before source integration |
| File06 | File00 PR #56 stacked on old base; exact File06 counterpart required | consent withdrawal, revoke, purge and migration must be tested end-to-end |
| File08 | File00 #54 and #55 overlapping UUID paths | one canonical UUID owner; duplicate, wrong-subject and inverse lookup denied |
| File09 | merged File00 #58 provides read-only intake prerequisite; File09 #17 applicant actor boundary | retain reviewer/finalizer/public verification reads; actor-bind applicant-only writes |
| File17/18/19 | source pointers and explicit consumer obligations | no stale authority after membership suspension/revocation; no private identity in messages/index/notifications |
| File20/25 | active draft integration work | no duplicated membership owner; truthful UI states and accessible fail-closed UX |
| File24 | PR #33 draft; prior cross-file audit PRs merged | independent assurance, security veto, restore and release evidence |
| File21/22/23/26 | source pointers and plan obligations | publishing, drafts, dashboards and search use current scoped claims; exclude private identity evidence |

## PR handling
- #52: BLOCKED by merge conflict; do not merge.
- #54: HOLD until UUID reverse lookup overlap with #55 is resolved.
- #55: HOLD; historical branch ancestry and overlapping File04/UUID paths must be reconciled.
- #56: HOLD; stacked on #55 and requires exact File06 counterpart.
- #59: Draft evidence/source candidate; inventory does not establish acceptance.
- #61: Draft source candidate for independent File03 age assurance; not merged.
- #58: already merged source contract only; does not prove File09 end-to-end acceptance.

No PR is merged or closed by this audit.

# File 00 V12.3 — G05 cross-file owner/consumer acceptance matrix

Captured: 2026-11-14. The following are test obligations, NOT proof of all consumers being complete. File00 owns membership/identity/guardian/trust claims; File02 owns login/step-up; File09 professional review; File06 research cases; File20/25 shell and visuals.

| File | Owner boundary | Required positive and negative checks | Evidence/status |
| --- | --- | --- | --- |
| 02 | Authentication/accounts, current session, step-up, recovery | actor equality, current signed-in principal, revoked session denies | Selected CI exists; complete installed state pending |
| 03 | Profile/doctor public identity | public fields only with fresh File00 claim | Not independently installed/accepted |
| 04 | Historical authorship and deleted-author sentinel | no-login, no-role, no impersonation, orphan compensation | PR #52/#55 alternatives pending |
| 06 | Research consent and permitted publication | approved relation/policy, patient self-consent, withdrawal/cache purge | PR #56 with File06 counterpart; full life-cycle pending |
| 07 | Donation and entitlement separation | donor/non-donor identical File00 role/verification | Source group pointers only |
| 08 | Clinic and appointments | unique canonical UUID and current doctor/guardian authorization | PR #54/#55 overlap; final consumer pending |
| 09 | Doctor verification intake | exact principal, pending doctor role, clinical privilege false | Source red/green PR #59 and pinned File09 PR #17 PHP 7.4/8.3 passed; staging/live pending |
| 17 | Communication/Network | owner relationship and suspension/revocation | Not independently installed/accepted |
| 18 | Marketplace and appointments | no donor/commission-based membership privilege | Not independently installed/accepted |
| 19 | Messages/notifications | no private identity material in message/log/provider | Not independently installed/accepted |
| 20 | Shell/settings/route | truthful membership/verification; no duplicated owner | Not browser accepted |
| 21 | Publication moderation | current verified publisher grant and Founder policy | Not independently installed/accepted |
| 22 | Composer/drafts | no private membership leak or inherited rights | Not independently installed/accepted |
| 23 | Dashboard | owner-only data; no stale cross-tenant caching | Not independently installed/accepted |
| 24 | QA/security/privacy | independent verification, release veto, audit | File24 audit pending |
| 25 | Visual system | RTL/keyboard focus/zoom/a11y for File00 | No device acceptance |
| 26 | Search | private identity docs excluded from index | No installed privacy negative test |

## End-to-end mandatory cases
1. File02 login → File00 current membership → File09 pending doctor intake → independent File09 verification → separate clinical claim; no access before approval.
2. File06 approved case and reviewer → verified patient consent → permitted publication → withdrawal/purge/revoke.
3. File04 deleted author → non-login sentinel with zero privileges; interrupted DB and compensation failures are safe.
4. File08 unique subject UUID → incorrect/duplicate/missing subject denied; current doctor/guardian booking rechecked.
5. File20/25/26: 320/390/768/1024/1440 viewport, Urdu/Arabic RTL vs English LTR, screenreader, weak-network and no private leaks.

All rows remain Staging-Accepted=UNVERIFIED and Live-Deployed=UNVERIFIED until actual installed paired evidence. No release version selected and no deployment authorized.

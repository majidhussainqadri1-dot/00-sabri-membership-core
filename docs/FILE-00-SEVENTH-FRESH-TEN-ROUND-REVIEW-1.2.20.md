# File 00 — Seventh Completely Fresh Ten-Round Review — Release 1.2.20

Baseline: sixth-review exact candidate `c0d04a67fb2c24319d34defc23d534e0546445e0`, reopened because newer File 02 v2.3/runtime 1.3.2 governing evidence became available.

| Round | Result | Defect | Correction / retest |
|---:|---|---|---|
| 1 | Defect | S7R-R1-D01 Medium — staging artifact provenance still presented older main/package evidence while a newer exact 1.2.19 candidate existed | Preserved historical main receipt, added exact sixth-review baseline artifact/ZIP provenance and re-pinned File00 baseline |
| 2 | Defect | S7R-R2-D01 High — File02 ledger was v2.2/1.3.0 but governing reviewed evidence is v2.3/1.3.2 with Assurance v2 2.0.0 | Updated governing evidence and explicit GitHub-sync blocker |
| 3 | Defect | S7R-R3-D01 High — candidate/source pinning claimed complete despite missing current-plan File02 exact head | Pinning states now partial/blocked; executable contract fails closed |
| 4 | Defect | S7R-R4-D01 High — File00 consumed only File02 assurance v1 | Added additive v2 consumer with 5-minute freshness, owner/version, session/fingerprint binding, anti-downgrade and v1-when-absent compatibility |
| 5 | Defect | S7R-R5-D01 Medium — v2 UV/phishing/risk/binding/provenance disappeared from File00 assurance profile | Added privacy-minimal booleans/normalized risk and receipt provenance; no raw fingerprint |
| 6 | Defect | S7R-R6-D01 High — highest-risk File00 step-up modeled hardware-backed but not phishing resistance | Founder recovery and break-glass now require phishing-resistant assurance; File24 high risk may only strengthen |
| 7 | Defect | S7R-R7-D01 Medium — legacy local File00 MFA pre-gate could reject a valid File02 v2 strong receipt before versioned step-up evaluation | Removed duplicate ceremony gate; native step-up remains fail-closed on assurance levels and membership state |
| 8 | Defect | S7R-R8-D01 High — File02 v2 receipt risk was exposed but ignored by native File00 step-up | elevated/high receipt risk strengthens auth level and phishing-resistance requirement |
| 9 | Defect | S7R-R9-D01 Medium — claim envelope regenerated auth freshness from current time and omitted source verified_at | Bound nested auth claim to source verified_at and maximum receipt freshness |
| 10 | Defect | S7R-R10-D01 Medium — materially corrected source would still identify/package as 1.2.19/sixth review | Bumped 1.2.20, synchronized package/readme/tests/docs/manifest and deterministic package gates |

Total: **10 fresh rounds; defects in 10/10 rounds; 10 unique defects corrected.** Severity: **5 High, 5 Medium, 0 Critical, 0 Low**. Known unresolved repository defects in this reviewed scope are zero after final exact-head CI. External staging/live/operational gates remain separate.

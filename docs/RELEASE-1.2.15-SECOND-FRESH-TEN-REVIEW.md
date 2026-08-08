# Sabri Membership Core 1.2.15 — Second Fresh Ten-Review Corrective Release

Release 1.2.15 is a repository, authorization, privacy, durable-event and release-integrity hardening release. It preserves the existing canonical ownership boundaries and does not change the database schema or public membership contract.

## Release identity

- Plugin/runtime: **1.2.15**
- Database schema: **1.3.0**
- Public membership contract: **1.2.0**
- Advanced Trust contract: **1.0.0**
- File 00 owner: membership + identity assurance
- File 02 owner: authentication ceremonies
- File 09 owner: professional verification truth
- File 24 owner: security/risk assurance
- File 26 owner: search/discovery/ranking

## Second fresh ten-round result

Ten new rounds were executed from the prior exact `main` baseline `6f557b764e57509d414d4bdb6c2f654a8a0a7f20`; prior review rounds were not counted. The second cycle found **14 unique defects**, corrected **14/14**. All ten rounds found at least one reproducible defect.

Severity: **1 Critical, 8 High, 5 Medium, 0 Low**.

The most consequential corrections are: canonical event-inbox schema/runtime alignment; immutable hard-block and restricted-capability baselines; non-bypassable Safe Mode; fresh two-factor enforcement for private identity evidence; server-side submission lifecycle replay prevention; durable outbox retry scheduling; migration-lock contention safety; privacy-export data minimization; release-artifact hygiene; permanent second-cycle regression gating; and exact master-index/runtime release consistency.

## Preserved governing laws

- one complete free tier;
- no paid unlocks;
- zero commission;
- donation-neutral authority, verification, support and ranking;
- Sabri Green `#087A4E` remains the primary fallback token;
- public/search consumers never receive raw C2–C4 identity evidence;
- repository completion remains distinct from staging acceptance, live deployment and operational acceptance.

## Package and QA gate

Release acceptance requires exact-head read-only CI on the PR head and again on merged `main`, deterministic `1.2.15` package verification, full inherited QA, the permanent second-fresh contract/runtime tests, PHP 7.4/8.3 compatibility, no tracked generated release ZIP/checksum outputs, no write-capable CI, archive safety/manifest/CRC validation and zero known unresolved repository defects.

## External gates

Hostinger **Staging-Accepted**, **Live-Deployed** and **Operational** remain pending until separately proven in the real environment.

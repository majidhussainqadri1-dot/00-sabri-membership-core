# Sabri Membership Core 1.2.14 — Fresh Ten-Review Corrective Release

Release 1.2.14 is a security/authorization hardening release for the existing Advanced Membership, Identity & Trust Extensions. It does not create new canonical owners and does not change the database schema or the public membership contract.

## Release identity

- Plugin/runtime: **1.2.14**
- Database schema: **1.3.0**
- Public membership contract: **1.2.0**
- Advanced Trust contract: **1.0.0**
- File 00 owner: membership + identity assurance
- File 02 owner: authentication ceremonies/passkeys
- File 09 owner: professional verification truth
- File 24 owner: security/risk assurance
- File 26 owner: search/discovery/ranking

## Corrective scope

The fresh ten-round audit corrected 15 unique repository/source defects across periodic reverification, revocation concurrency, selective-disclosure freshness, fail-closed state transitions, service identity classification, emergency governance, File 09 claim provenance, background propagation, File 02 revalidation freshness, direct protected-action gating and delegated-scope authorization.

Round 10 found no new reproducible coding defect after the prior corrections.

## Preserved governing laws

- one complete free tier;
- no paid unlocks;
- zero commission;
- donations cannot influence authority, verification, support priority or ranking;
- Sabri Green `#087A4E` remains the primary fallback token;
- sensitive identity evidence stays private and is not projected to public/search consumers;
- repository completion is distinct from staging acceptance, live deployment and operational acceptance.

## QA and package gate

Release acceptance requires the final branch and merged `main` to pass both read-only workflow families on the exact immutable head, including the full inherited suite, fresh review regressions, PHP 7.4/8.3 compatibility, deterministic ZIP verification, archive path/symlink/manifest/CRC checks, secret/binary hygiene and workflow write-permission hygiene.

The exact final package SHA-256 and exact merged-main workflow runs are recorded as CI evidence rather than hard-coded into this source-controlled release note, so the note does not become stale when the final merge SHA is created.

## External gates

The following remain pending until proven in the real Hostinger staging/live environment:

- Staging-Accepted
- Live-Deployed
- Operational

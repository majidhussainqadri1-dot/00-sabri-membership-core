# File 00 — Sabri Membership Core 1.2.42

## Live-proven File 01 authorization compatibility correction

Live evidence on 11 August 2026 established that Hostinger had File 00 `1.2.39` with DB `1.4.4` and File 01 `2.0.0`. The uploaded complete File 00 plugin payload matched the exact `1.2.39` manifest, and its full source contained neither `spf_file00_authorization_claim` nor `spf_file00_capability_claim`. The deployed File 01 authorization source matched the repository behavior that requires a structured File 00 claim when File 00 is present and otherwise returns `false`. The observed File 01 Foundation Status symptom was therefore `Unauthorized.` before System Check rendering.

### Correction

File 00 now registers the canonical structured `spf_file00_authorization_claim` provider. It supports File 01 Foundation contract `2.0.0` with claim version `1.0.0`, validates current actor identity, action, exact capability, object hash, purpose, plugin identity, consumer contract and request freshness, and issues a 60-second claim ID. File 00 hard-block and effective-eligibility state remains authoritative. Founder may satisfy all recognized File 01 governance actions; ordinary WordPress Administrators are limited to `view`, `system_check`, and `run_system_check`. Unsupported requests remain fail-closed, and no legacy boolean bridge is added.

### Release identity

- Runtime: `1.2.42`
- DB schema: `1.4.5` (unchanged)
- Public membership contract: `1.2.3` (unchanged)
- CF-01: `1.1.0` (unchanged)
- Advanced Trust: `1.0.0` (unchanged)
- File 01 authorization claim: `1.0.0`
- Supported File 01 Foundation contract: `2.0.0`

### Live boundary

This repository correction is not a live resolution. Resolution requires exact-head CI success, deterministic package creation, deployment of the exact `1.2.42` artifact, confirmation of deployed/package parity, and a live re-test proving File 01 Foundation Status/System Check is accessible to the authorized Founder/Administrator while unauthorized actors remain denied.

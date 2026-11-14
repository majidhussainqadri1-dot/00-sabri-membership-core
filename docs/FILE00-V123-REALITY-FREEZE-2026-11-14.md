# File 00 V12.3 — G00 source / deployed / staging reality freeze

Capture date: **2026-11-14** (Pakistan). Governing plan: `Sabri-Website-Master-Plan-V12.3.docx`; implementation plan: `Sabri-File00-V12.3-Complete-Implementation-Plan-2026-11-14.docx`.

## 1. Repository reality (read-only evidence)
- Repository: `majidhussainqadri1-dot/00-sabri-membership-core`
- Frozen `main` HEAD: `1fad051c86d530d558be3eb1890d3c6e67bce6e8`
- Plugin header/runtime version in that exact HEAD: `1.2.44`
- Repository schema **target**, not live DB version: `1.4.5`
- Public membership contract: `1.2.3`
- File09 doctor-intake contract: `1.0.0`
- On `main` HEAD, four selected checks were observed successful. This is not a complete all-requirement or production acceptance certificate.
- Issue #57 remains open; PR #58 was merged into `main` on 2026-11-13.
- Current source-only corrective Draft PR #59 was opened against that `main`. Red regression SHA `6f44a2a4aec99270b51a4382e8ba8b5b030da08b`; actor-binding source change SHA `686bc5a1c7520892d95e3a95815152241b178f1c`; manifest-corrected green source SHA `d91a8a0c639a20e1f41ef78d791c6511434e6fbb`, 9/9 GitHub workflows success at that SHA. Later documentation-only commits require separate exact-HEAD CI checks.
- Source-level fix is not approved, merged, packaged as a unique release, or deployed.

## 2. Live WordPress reality — all unverified
An account-level WPVibe site discovery returned **no connected sites** for this account. This says nothing about the live site's health or whether the plugin is installed. No authenticated WP/Hostinger live file, active plugin inventory, WP CLI, wp_options, DB/schema/migration checkpoint, log, deployed package, or exact checksum was obtainable in this session.

| Mandatory field | Status |
| --- | --- |
| Repository HEAD | `1fad051c86d530d558be3eb1890d3c6e67bce6e8` (source truth) |
| Deployed Version | **UNVERIFIED** |
| Live DB Version | **UNVERIFIED** |
| Migration State | **UNVERIFIED** |
| Live Verification Status | **NOT PERFORMED / UNVERIFIED** |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

## 3. Staging reality
No authenticated staging site or installed build/DB evidence obtained. An ephemeral GitHub Actions WordPress+database integration job is **not** the staging site.

## 4. Mandatory next authorized read-only capture
Collect exact active plugin version/bytes, schema `smc_*` structure and minimal non-sensitive checkpoint counts, `wp_options` migration states, current blocking notices/log redacted excerpts, checksum and active File02/File06/File09 versions. Compare separately to the approved immutable release package. Do not disclose passwords, identity documents, patient data, encryption keys or tokens.

No live diagnosis or live repair may be certified from this source-only freeze.

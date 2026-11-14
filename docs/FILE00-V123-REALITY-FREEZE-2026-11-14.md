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
- Draft PR #59 is now a **governance and evidence-only candidate**. Its early attempted File00 self-only actor restriction was shown to break legitimate reviewer/public subject checks and was **reverted before merge**. The final owner source and manifest were restored byte-for-byte at SHA `8b49210c0ae8e8b2dff86be71044b401d0bd0fda` (9/9 source workflows green). The actual applicant-only control is corrected separately in File09 Draft PR #17; see the superseding File00 defect ledger. Earlier test RED/GREEN claims predicated on actor==subject for File00 are invalid as owner contract expectations.
- New File00 V12.3 123-ID source-only inventory and automated inventory assertion are part of Draft PR #59; none of 123 rows is independently end-to-end accepted. No new File00 runtime version, source security patch, merge, live deployment or production approval is being claimed.

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

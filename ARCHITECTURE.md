# File 00 — Current Source Architecture

## Canonical ownership
Sabri Membership Core governs membership eligibility, human/institutional identity prerequisites, age/guardian and consent checks, versioned role claims, privacy-minimal membership assertions, audited lifecycle, session invalidation, governed delegation and trust/continuity policy. Other files remain sovereign in their own domains.

## Source identity (not deployed version)
Plugin **1.2.44**, database schema **1.4.5**, public membership contract **1.2.3**, CF01 membership prerequisite **1.1.0**, advanced trust **1.0.0**. Draft PR #55 is not main or live truth; fetch its exact SHA and CI at review time.

## Domain and authentication boundaries
- **File02**: login, passkeys/WebAuthn, password/recovery and authentication assurance; File00 MFA/TOTP and old recovery-factor workflow were retired by Founder directive on 10 August 2026.
- **File09**: canonical professional/doctor verification; File00 validates fresh claim evidence and cannot independently grant medical authority.
- **File04**: publishing/legacy migration. File00 owns canonical subject UUID and an audited nonlogin legacy author sentinel. Only a current authenticated operator with precisely named File04/File21 migration capability may invoke owner filters. Failed metadata/audit compensation must not create an authenticated principal.
- **File08**: clinic/appointments and clinical object authorization; File00 supplies read-only opaque subject lookup and membership prerequisites, never clinical record access authority.
- **File01/20/21/24/25/26**: release, shell, feed, security governance, UI and donations remain with their own canonical owners.

## Technical invariants
Canonical claims fail closed on missing owner, freshness or authorization. Current DOB and guardian consent govern protected eligibility, not a stale registration flag; institutional exemption is explicit. Cryptography/audit keys remain generation-bound; the append-only audit chain and serialized audit tail are guarded. Source-verified runtime tests, real companion source fixtures, optional future staging and live deployed artifacts are **separate realities**.

## Acceptance boundary
Master Plan v12 contains **123** File00 IDs: 100 R, 3 CEN, 20 EXT. Repository presence or 80 individually named PHP/UI source proofs cannot replace 123 requirement-specific behavioral/consumer acceptance or Founder signoff. Live/staging parity, browser/RTL/a11y and restore rollback remain external gates. Prior architecture snapshots for source 1.2.33/schema 1.4.4/contract 1.2.1 were historical and cannot be treated as current.

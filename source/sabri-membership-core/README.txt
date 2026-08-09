=== Sabri Membership Core ===
Contributors: sabrihomeopathy
Tags: membership, identity, guardian consent, two-factor authentication, privacy
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.2.32
License: GPL-2.0-or-later

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

== Architecture ==

File 00 owns:

* membership age and eligibility;
* identity assurance and private evidence;
* verified guardian consent for every eligible applicant under 18;
* membership state transitions and reviewer governance;
* two-factor membership challenges and session assertions;
* versioned membership and communication assertions;
* advanced identity/trust claims, containment, continuity and revocation epochs;
* the privacy-minimal CF-01 membership/step-up provider contract;
* privacy export, erasure, retention holds, and tamper-evident audit records.

File 00 does not own authentication UI or passkey ceremony, public profiles, doctor credential storage, publishing, encyclopedia content, notifications, routes, the application shell, search/ranking, or clinical records. File 02 remains authentication/passkey owner, File 09 professional verification truth, and File 26 search/discovery/ranking owner. CF-01 consumes assertions only and remains the future clinical system of record after its activation gates.

== CF-01 Provider Contract ==

Contract `smc.cf01.membership-assurance` version `1.0.0` returns a short-lived, purpose-bound, server-side assertion containing an opaque platform UUID, membership state, age/guardian context, jurisdiction context, source record version and explicit allow/deny/unknown result. It returns no clinical data, identity-document content, two-factor secret or recovery code.

`SMC_CF01_Contract::verify_step_up()` verifies a File 00-owned second factor without exposing File 00 storage. The result is authentication evidence only; it never grants clinical object, field, relationship, prescription, export, break-glass or key authority by itself.

== Governing Policy ==

* Platform commission is permanently 0 percent.
* Support and donations are optional for everyone and never affect verification, ranking, visibility, or service.
* Minimum age is 15 for male applicants and 12 for female applicants, subject to jurisdiction-specific legal and child-safety approval before launch.
* Every eligible applicant under 18 requires independently verified guardian consent.
* Every professional account requires age 18 or older.
* Official Founder identity: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed.

== Authorization ==

* Institutional identity never defeats an explicit membership hard block.
* File 00/platform capabilities and protected mutations require effective eligibility, verified ordinary-account contact ownership, and a current two-factor session challenge.
* Advanced containment or non-active continuity states remove protected capabilities and are propagated with a monotonic revocation epoch.
* File 02 passkey/WebAuthn assurance may strengthen a File 00 step-up decision only through the versioned owner/freshness adapter; File 00 does not perform the passkey ceremony.
* A CF-01 assertion is derivative evidence and must be rechecked at action time; it is not a reusable bearer credential.
* Recovery actions and routes use exact allowlists; arbitrary smc_* or sa_* prefixes are not authorization.
* Ordinary Founder reassignment is locked after configuration.

== Required Configuration ==

For managed shared-host operation, File 00 can provision a per-site SMC3 master-key generation in protected private key storage when SMC_MASTER_KEY / SMC_MASTER_KEY_ID are not defined. Back up that managed keyring separately from the database before production acceptance.
For externally managed deployment, define SMC_MASTER_KEY with at least 256 bits of entropy and SMC_MASTER_KEY_ID as a stable non-secret key identifier (for example smc-master-2026-01). Retain prior key identifiers only as required by an approved rotation/migration plan.

Private storage defaults to a directory outside the WordPress public root. For explicit control, define SMC_PRIVATE_STORAGE_DIR as an absolute, non-symlink path outside ABSPATH and WP_CONTENT_DIR. The web-server user must be able to enforce directory mode 0700 and file mode 0600.

Configure these provider filters before accepting applications:

* smc_document_scan — approved scanner adapters must return true only after malware/active-content scanning. The built-in shared-host fallback accepts only supported images that will be decoded/re-encoded; PDFs remain fail-closed without an earlier approved scanner decision.
* smc_external_contact_otp_delivery — File 19/provider adapter must return an accepted structured result containing receipt_id or provider_reference; boolean-only delivery is not sufficient for OTP ownership proof.
* smc_external_guardian_invitation_delivery (or the compatibility smc_send_guardian_invitation bridge) — must return an accepted structured result containing receipt_id or provider_reference after the guardian code/link has been accepted by the delivery provider; boolean-only delivery is not trusted as guardian ownership proof.

File 19 receives membership notices through its canonical sabri_notify integration. File 00 never sends decision email directly.

== Staging Acceptance ==

Local source and GitHub checks do not authorize production. Test fresh activation, 1.0.1 upgrade, MySQL advisory locks, concurrent reviewers, authorization matrices, filesystem denial and rollback, scanner providers, email/mobile/guardian delivery, File 02 Google sign-in and passkey assurance adapter, CF-01 provider/consumer contracts, all named cross-file integrations, advanced trust containment/revocation/selective-disclosure workflows, privacy erasure, restore, browser accessibility, and mobile layouts on Hostinger staging.

== Changelog ==

= 1.2.32 =
* Correctly identifies surviving File 00 1.0.1 audit rows, whose original schema had no previous_hash or row_hash fields, instead of falsely reporting them as damaged modern HMAC rows.
* Preserves every legacy row unchanged, seals an exact keyed snapshot as an explicitly lower-assurance migration anchor, then starts the modern HMAC epoch and reconstructs only the missing serializer tail. It never retroactively claims that pre-HMAC history was cryptographically verified.
* Refuses recovery for an unrecognized legacy schema, an unhashed row after the HMAC epoch begins, any invalid modern hash/link, an anchor mismatch, a changed snapshot, a changed key, a race, or a previously initialized partial schema. Administrator diagnostics now distinguish the real row/anchor reason from a merely missing tail.
* DB schema contract remains 1.4.3; the compatibility bridge adds only missing nullable/empty HMAC columns to an exact legacy table and does not alter or delete audit-row content.

= 1.2.31 =
* Repairs the live first-bootstrap state proven by diagnostics where smc_audit_log contains surviving records but smc_audit_tail is missing. File 00 now validates every surviving append-only audit row cryptographically without consulting the absent tail, then reconstructs only the mutable serializer pointer from the verified final row hash.
* Keeps fail-closed behavior if any previous_hash/row_hash check fails, the audit key is unavailable, the schema was previously marked initialized, the audit log changes during recovery, or the reconstructed tail cannot be bound exactly to the verified final row.
* DB schema contract remains 1.4.3; this is a guarded recovery-behavior correction rather than a schema-shape change.

= 1.2.30 =
* Repairs the live partial-bootstrap state proven by diagnostics: smc_audit_log exists while smc_audit_tail is missing. If the surviving audit log is empty and the schema has never been marked initialized, File 00 can safely resume the interrupted first-time bootstrap by creating and initializing the serializer table.
* Replaces the fragile two-step dbDelta bootstrap for the audit pair with explicit CREATE TABLE IF NOT EXISTS statements, verifies InnoDB after creation, and records a bootstrap marker/epoch so future interrupted first-run recovery is evidence-based.
* Keeps fail-closed behavior for any previously initialized partial schema or any non-empty surviving audit state; those conditions still require manual integrity review. DB schema contract remains 1.4.3.

= 1.2.29 =
* Repairs the live Hostinger schema-bootstrap defect exposed by the 2FA diagnostic: both smc_audit_log and smc_audit_tail were absent while the persisted DB version already matched the runtime, so normal maybe_upgrade() skipped table creation.
* Adds a guarded audit-infrastructure bootstrap independent of version equality, initializes a new explicit audit-chain epoch only when both audit tables have never been marked initialized, and refuses silent auto-repair if a previously initialized or partially present audit schema later disappears.
* Advances the runtime DB schema marker to 1.4.3 so existing 1.4.2 installations also pass through the normal schema reconciler. TOTP remains fail-closed until the audit subsystem is ready.

= 1.2.28 =
* Adds safe live diagnostics for TOTP enrollment audit failures, exposing audit-row-chain and serializer-tail readiness without exposing hashes, secrets, or key material.

= 1.2.27 =
* Separates genuine TOTP mismatches from fail-closed enrollment storage/audit/session errors.
* Extends pending authenticator setup to 20 minutes and displays required TOTP parameters.
* Keeps security fail-closed while making live Hostinger diagnostics actionable.

= 1.2.25 =
* Authenticated admin-post CSRF compatibility hotfix: a valid WordPress session nonce is now the authoritative gate for logged-in File 00 actions. Optional Origin/Referer mismatch remains audit-visible but no longer blocks 2FA or other authenticated actions on reverse-proxy/shared-host deployments. Public guardian verification keeps a strict origin check after nonce validation.

= 1.2.24 =
* Hostinger same-origin hotfix: protected POST actions now accept canonical www/non-www aliases and WordPress Home/Site URL hosts, prefer Origin over Referer, and retain nonce-based fail-closed CSRF protection. Fixes legitimate 2FA setup and other admin-post actions being blocked with “The protected request origin could not be verified.”

= 1.2.23 =
* Second independent 80-round corrective candidate: closes transaction, provider-receipt, private-evidence authorization, privacy-export budgeting, retention-hold governance, session-revocation reconciliation, accessibility/RTL, status/security/guardian UX, and interrupted evidence-recovery defects found after the first 80-round package.
* Runtime 1.2.23; DB schema 1.4.3; public contract 1.2.1. Existing scanner-approved evidence can now be resumed at a server document checkpoint after an interrupted submission; selecting a new file deliberately replaces the accepted copy.
* Removes blanket WordPress manage_options bypasses from private evidence/profile review, surfaces legacy indefinite retention holds as explicit acceptance blockers, and bounds privacy exporter output while preserving deterministic pagination.
* Production acceptance remains separate: Hostinger staging, configured File 19 email/SMS/guardian delivery, real browser/mobile/accessibility, isolated restore/rollback, and independent security acceptance are still required.

= 1.2.22 =
* Second fresh 80-round hardening: optimistic row-version submit binding, post-commit role/session effects, immutable guardian-current enforcement, provider receipt preservation, keyring permissions, private referrer policy, founder option commit/cache safety, and minor guardian UX validation.


= 1.2.21 =
* Eighty-round corrective review candidate: closes additional lifecycle, guardian-currentness, merge-concurrency, delegated-authority, break-glass, service-identity, institutional-AI session, RTL/responsive, crypto-runtime, schema-migration and backup-manifest defects found during sequential review.
* DB schema advances to 1.4.1 so OTP delivery-receipt columns are actually migrated on existing 1.4.0 sites; public membership contract remains 1.2.1.
* Removes the missing RTL replacement-stylesheet path, strengthens 320px/touch-target geometry, and keeps native browser constraint validation in progressive/no-JavaScript application submission.
* Makes OpenSSL AES-256-GCM capability part of fail-closed key readiness, and keeps PDFs fail-closed unless an approved scanner adapter has already accepted them; image fallback remains bounded and is followed by safe re-encoding.
* Backup manifests now include the MFA factor replay-state and serialized audit-tail tables. Production acceptance remains separate and requires Hostinger staging, configured providers, browser/mobile/accessibility, restore/rollback and independent security acceptance.

= 1.2.20 =
* Hostinger/shared-host corrective candidate: replaces fragile XHR evidence submission with native multipart submission, adds bounded host-runtime dispatch for user/reviewer actions, managed per-site encryption-key fallback, conservative local evidence scanning fallback, and content-hash asset cache busting.
* DB schema remains 1.4.0 and public membership contract remains 1.2.1. Production acceptance still requires the explicit Hostinger/browser/provider/restore/security gates below.

= 1.2.19 =
* Corrective candidate for the 8 August 2026 GitHub code-only audit: fixes dual professional approval generation/independent handoff, appeal restoration provenance, reviewer assignment/document scoping, guardian immutable succession, rejected-reapplication bypass and jurisdiction-effective age enforcement.
* Adds versioned encryption-key generations for new SMC3 envelopes, global factor-level TOTP replay protection, safe 2FA replacement with current password/factor re-authentication, serialized tamper-evident audit tail, fail-closed post-commit role/session reconciliation, and stronger Safe Mode worker blocking.
* Strengthens privacy export/erasure, ancillary role/capability and break-glass cleanup, authenticated draft expiry, recovery-code receipt acknowledgement, orphan lease cleanup, outbox delivery acknowledgement, trust-transition repair and structured isolated-restore proof.
* Runtime 1.2.19; DB schema 1.4.0; public membership contract 1.2.1. This is a code/automated-QA candidate only: real WordPress/MySQL concurrency, providers, browser/accessibility, isolated restore/rollback, security review and Hostinger staging acceptance remain mandatory before production.

= 1.2.18 =
* Fifth completely fresh ten-round review: enforced the declared adaptive step-up policy on critical identity, guardian, merge, delegation and break-glass workflows; break-glass now requires current hardware-backed File 02 assurance and revalidates both privileged approvers at consumption.
* Added synchronous age/jurisdiction re-evaluation, typed File 24 containment authorization, fully opaque revocation hook subjects, stricter verifiable-credential chronology, live delegation-grantor authority checks, and current-policy guardian succession binding.
* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical File 00 ownership boundaries.

= 1.2.17 =
* Fourth fresh ten-round corrective closure: institutional-admin MFA/hard-block enforcement, non-removable recovery allowlists, opaque assurance profiles, factual MFA provenance timestamps, institutional expiry fail-closed handling, deployment key-ID documentation, and release-QA provenance synchronization.
* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical File 00 ownership boundaries.

= 1.2.16 =
Third fresh ten-round corrective closure: key-ID migration safety, object-scoped private evidence, canonical publishing authority, revocation/consent hardening, durable event recovery, erasure propagation, restore evidence checks, and permanent regression QA.

= 1.2.15 =
* Second fresh ten-round corrective closure: release-artifact hygiene, migration-lock contention safety, immutable authorization baselines, non-bypassable Safe Mode, fresh-session private-document access, canonical event-inbox schema use, replay-safe application lifecycle, privacy-minimal exports, durable outbox retry scheduling, and permanent regression gating.
* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical File 00 ownership boundaries.

= 1.2.14 =
* Fresh ten-round corrective closure: synchronous periodic reverification, serialized revocation epochs, purpose-bound revocation-fresh selective disclosures, fail-closed state transitions, service-identity separation, typed File 09 professional claims, propagated overdue holds, and atomic emergency governance.
* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical ownership boundaries.

= 1.2.13 =
* Adds F00-EXT-001 through F00-EXT-020 Advanced Membership, Identity & Trust Extensions 2026.
* Adds identity/authentication assurance levels and a versioned File 02 passkey/WebAuthn assurance adapter without duplicating authentication ownership.
* Adds adaptive step-up, periodic reverification, critical identity-change revalidation, guardian succession and governed duplicate-account resolution.
* Adds compromised-account containment, monotonic revocation propagation SLA, anti-downgrade contract negotiation and privacy-minimal assertions.
* Adds short-lived selective-disclosure proofs, external verifiable-credential adapter, scoped delegation and dual-control institutional break-glass.
* Adds non-human/service identity classes, dormant/deceased/permanently-inactive continuity states, and a privacy-safe trust/security timeline.
* Preserves the single free tier, zero commission, donor neutrality, File 09 professional truth and File 26 search/ranking ownership.

= 1.2.12 =
* Reconciles File 00 with the 6–7 August 2026 latest-central addendum and 00–26 ownership map.
* Makes #087A4E the exact File 00 green fallback while File 25 remains the visual-token owner.
* Adds a privacy-minimal File 26 membership projection and synchronous projection invalidation contract without creating a search backend.
* Enforces File 09 as doctor-verification truth and removes stale user-meta verification fallback.
* Advances the required 2FA challenge cutoff after age/guardian/consent/verification security-state changes.
* Preserves the single free tier, zero commission and complete donor-neutrality invariants.

= 1.2.11 =
* Harmonized against all three governing plans.
* Added institutional AI identity, free baseline, publishing and 1 GB transfer assertions.
* Donation remains optional and non-privileging; primary UI tokens are green.

= 1.2.10 =
* Completes the plan-mandated progressive seven-step application with encrypted server-side autosave/resume, upload progress, duplicate-submit protection, network retry and privacy notices.
* Replaces single-role enforcement with versioned multi-role grants and domain-specific approved-role assertions.
* Adds persistent application repair items for partial document/provider/submission failures and safe reconciliation.
* Adds reviewer queue filters, assignment, SLA/overdue visibility, conflict declarations, reason codes, independent appeal restoration and high-risk MFA gates.
* Enforces noindex/noarchive/no-store on private membership routes and adds scoped Safe Mode.
* Adds a privacy-minimized durable outbox/inbox with idempotency, correlation IDs, retries, dead letters and replay-safe consumers.
* Adds operational health, repair/dead-letter UI, backup manifest, post-restore reconciliation, operational owners and measurable SLO defaults.

= 1.2.8 =
* Applies forty consecutive review-and-correction cycles against both governing plans.
* Hardens approval evidence locking, document freshness, private storage, deferred deletion and lifecycle concurrency.
* Corrects TOTP replay, revoked-session resurrection, exact revoke-one/revoke-all, MFA inactivity and recovery-code atomicity.
* Adds real audit-chain verification, contact OTP persist-before-delivery and stronger CF-01 step-up evidence.
* Adds deterministic forty-round regression evidence while preserving fail-closed external staging and Founder acceptance gates.

= 1.2.7 =
* Adds a versioned, privacy-minimal CF-01 membership assertion with opaque platform subject UUID, source record version, age/guardian and jurisdiction context.
* Adds a File 00-owned second-factor verification command that never exposes TOTP secrets or recovery-code storage.
* Keeps clinical object, prescription, export, break-glass and key authorization with their native owners.
* Adds static and runtime provider-contract regressions.

= 1.2.6 =
* Corrects professional dual-review finalization so independent votes persist until senior finalization.
* Binds approval votes to the exact submitted evidence generation and excludes stale votes after resubmission or evidence replacement.
* Adds a persistent fail-closed privacy-erasure lock, atomic record deletion, and tamper-evident audit-chain preservation.
* Makes recovery-code receipt consumption decrypt-before-delete and rolls back incomplete two-factor setup.
* Aligns reviewer contact status with canonical verified-contact assertions.

= 1.2.4 =
* Added the checksum-verified four-round master plan and 100-requirement traceability.
* Removed broad Administrator bypasses from File 00/platform capabilities and protected requests.
* Replaced broad recovery-prefix behavior with exact action and REST-route allowlists.
* Added guardian- and contact-aware effective eligibility and contextual REST mutation enforcement.
* Locked ordinary Founder reassignment after configuration.
* Derived client age policy from the canonical server policy.
* Added static/runtime authorization and master-plan integrity regressions.

= 1.2.3 =
* Prevented automated age-evidence checks from disciplinarily suspending canonical Founder or Administrator accounts.
* Added evidence-bound repair for prior institutional suspensions proven to originate from the age lifecycle.
* Preserved explicit manual and disciplinary hard blocks.
* Added one-time release repair execution even when the database schema is unchanged.
* Added privacy-safe institutional evidence-attention audit events.

= 1.2.0 =
* Reconstructed editable source from the verified 1.0.1 baseline.
* Removed duplicate authentication, profile, clinic, knowledge, navigation, and publishing ownership.
* Added canonical roles and versioned cross-module assertions.
* Enforced 0 percent commission, sex-specific minimum ages, guardian consent, and the adult-only professional rule.
* Added fail-closed encryption, authenticated document context, external private storage, atomic writes, leases, scanning, deletion jobs, and secure reviewer downloads.
* Added contact ownership, encrypted two-factor secrets, replay protection, atomic recovery codes, and session revocation.
* Added optimistic verification transitions, exact evidence decisions, self-review prevention, and dual professional approval.
* Added complete paginated privacy export, truthful erasure, retention holds, lifecycle checks, accessibility, localization, and deterministic build evidence.

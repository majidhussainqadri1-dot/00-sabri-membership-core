=== Sabri Membership Core ===
Contributors: sabrihomeopathy
Tags: membership, identity, guardian consent, two-factor authentication, privacy
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.2.11
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
* the privacy-minimal CF-01 membership/step-up provider contract;
* privacy export, erasure, retention holds, and tamper-evident audit records.

File 00 does not own authentication UI, public profiles, doctor credential storage, publishing, encyclopedia content, notifications, routes, the application shell, or clinical records. CF-01 consumes assertions only and remains the future clinical system of record after its activation gates.

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
* A CF-01 assertion is derivative evidence and must be rechecked at action time; it is not a reusable bearer credential.
* Recovery actions and routes use exact allowlists; arbitrary smc_* or sa_* prefixes are not authorization.
* Ordinary Founder reassignment is locked after configuration.

== Required Configuration ==

Define a securely generated and separately backed-up SMC_MASTER_KEY with at least 256 bits of entropy in wp-config.php.

Private storage defaults to a directory outside the WordPress public root. For explicit control, define SMC_PRIVATE_STORAGE_DIR as an absolute, non-symlink path outside ABSPATH and WP_CONTENT_DIR. The web-server user must be able to enforce directory mode 0700 and file mode 0600.

Configure these provider filters before accepting applications:

* smc_document_scan — must return true only after malware and active-content scanning.
* smc_send_contact_otp — must send email or mobile ownership codes and return true.
* smc_send_guardian_invitation — must send the guardian code and signed invitation link and return true.

File 19 receives membership notices through its canonical sabri_notify integration. File 00 never sends decision email directly.

== Staging Acceptance ==

Local source and GitHub checks do not authorize production. Test fresh activation, 1.0.1 upgrade, MySQL advisory locks, concurrent reviewers, authorization matrices, filesystem denial and rollback, scanner providers, email/mobile/guardian delivery, File 02 Google sign-in, CF-01 provider/consumer contracts, all named cross-file integrations, privacy erasure, restore, browser accessibility, and mobile layouts on Hostinger staging.

== Changelog ==

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

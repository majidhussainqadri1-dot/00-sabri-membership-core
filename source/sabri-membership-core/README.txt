=== Sabri Membership Core ===
Contributors: sabrihomeopathy
Tags: membership, identity, guardian consent, privacy, governance
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.2.37
License: GPL-2.0-or-later

Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.

== Architecture ==

File 00 owns:

* membership age and eligibility;
* identity assurance and private evidence;
* verified guardian consent for every eligible applicant under 18;
* membership state transitions and reviewer governance;
* versioned membership and communication assertions;
* advanced identity/trust claims, containment, continuity and revocation epochs;
* the privacy-minimal CF-01 membership-assurance provider contract;
* privacy export, erasure, retention holds, and tamper-evident audit records.

Founder change-control dated 10 August 2026 retires File 00 two-factor authentication. File 00 no longer owns or requires authenticator/TOTP enrollment, recovery codes, a user-entered MFA session challenge, authenticator replacement, or lost-factor recovery. Normal sign-in and password/account recovery remain with File 02. Historical audit evidence is preserved while obsolete File 00 factor material is retired after the protected DB/audit migration is healthy.

File 00 does not own authentication UI, password recovery, passkey ceremony, public profiles, doctor credential storage, publishing, encyclopedia content, notifications, routes, the application shell, search/ranking, or clinical records. File 02 remains authentication/passkey/password-recovery owner, File 09 professional verification truth, and File 26 search/discovery/ranking owner. CF-01 consumes membership assertions only and remains the future clinical system of record after its activation gates.

== CF-01 Provider Contract ==

Contract `smc.cf01.membership-assurance` version `1.0.0` returns a short-lived, purpose-bound, server-side assertion containing an opaque platform UUID, membership state, age/guardian context, jurisdiction context, source record version and explicit allow/deny/unknown result. It returns no clinical data or identity-document content.

File 00 no longer provides a second-factor verification result. Any future stronger authentication assurance must come from the canonical authentication owner through a separately approved, versioned contract; membership evidence by itself never grants clinical object, field, relationship, prescription, export, break-glass or key authority.

== Governing Policy ==

* Platform commission is permanently 0 percent.
* Support and donations are optional for everyone and never affect verification, ranking, visibility, or service.
* Minimum age is 15 for male applicants and 12 for female applicants, subject to jurisdiction-specific legal and child-safety approval before launch.
* Every eligible applicant under 18 requires independently verified guardian consent.
* Every professional account requires age 18 or older.
* Official Founder identity: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed.

== Authorization ==

* Institutional identity never defeats an explicit membership hard block.
* File 00/platform capabilities and protected mutations require effective membership eligibility and verified ordinary-account contact ownership; they no longer require a File 00 MFA challenge.
* Advanced containment or non-active continuity states remove protected capabilities and are propagated with a monotonic revocation epoch.
* File 02 remains the canonical authentication owner; File 00 does not perform password, passkey, TOTP or authenticator ceremonies.
* A CF-01 assertion is derivative evidence and must be rechecked at action time; it is not a reusable bearer credential.
* Retired MFA/recovery routes are not authorization paths and are removed from the active File 00 runtime.
* Ordinary Founder reassignment remains locked after configuration and requires Founder-approved change-control or the explicit configuration constant.

== Required Configuration ==

For managed shared-host operation, File 00 can provision a per-site SMC3 master-key generation in protected private key storage when SMC_MASTER_KEY / SMC_MASTER_KEY_ID are not defined. This key protects File 00 encrypted identity/evidence data and tamper-evident audit material; it is not an MFA secret. Back up that managed keyring separately from the database before production acceptance.
For externally managed deployment, define SMC_MASTER_KEY with at least 256 bits of entropy and SMC_MASTER_KEY_ID as a stable non-secret key identifier (for example smc-master-2026-01). Retain prior key identifiers only as required by an approved rotation/migration plan.

Private storage defaults to a directory outside the WordPress public root. For explicit control, define SMC_PRIVATE_STORAGE_DIR as an absolute, non-symlink path outside ABSPATH and WP_CONTENT_DIR. The web-server user must be able to enforce directory mode 0700 and file mode 0600.

Configure these provider filters before accepting applications:

* smc_document_scan — approved scanner adapters must return true only after malware/active-content scanning. The built-in shared-host fallback accepts only supported images that will be decoded/re-encoded; PDFs remain fail-closed without an earlier approved scanner decision.
* smc_external_contact_otp_delivery — File 19/provider adapter must return an accepted structured result containing receipt_id or provider_reference; boolean-only delivery is not sufficient for contact-ownership proof. This contact OTP is not File 00 MFA.
* smc_external_guardian_invitation_delivery (or the compatibility smc_send_guardian_invitation bridge) — must return an accepted structured result containing receipt_id or provider_reference after the guardian code/link has been accepted by the delivery provider; boolean-only delivery is not trusted as guardian ownership proof.

File 19 receives membership notices through its canonical sabri_notify integration. File 00 never sends decision email directly.

== Staging Acceptance ==

Local source and GitHub checks do not authorize production. Test fresh activation, legacy upgrade, DB/audit migration, legacy factor retirement, MySQL advisory locks, concurrent reviewers, authorization matrices, filesystem denial and rollback, scanner providers, email/mobile/guardian delivery, File 02 authentication integration, CF-01 provider/consumer contracts, all named cross-file integrations, advanced trust containment/revocation/selective-disclosure workflows, privacy erasure, restore, browser accessibility, and mobile layouts on Hostinger staging.

== Changelog ==

= 1.2.37 =
* Repairs the second live-proven MariaDB same-name index migration failure, `Duplicate key name 'decision'`, on `smc_approval_votes` while retaining the successful v1.2.36 queue repair.
* Recognizes only the exact historical non-unique BTREE `decision(request_id,decision)` signature, removes only that obsolete secondary index, and lets normal dbDelta create `decision(request_id,approval_generation,decision)`.
* Treats fresh/current/absent decision indexes as no-ops, refuses unknown shapes fail-closed, and read-back verifies the current decision index together with both current queue indexes.
* Extends the WordPress 7.0.1 + MariaDB 11.4 live-state regression fixture to reproduce both historical queue and decision transitions before proving promotion to DB schema 1.4.4 and creation of downstream tables.
* Runtime 1.2.37; DB schema 1.4.4; public contract 1.2.2. Live resolution still requires deployment and post-deployment verification.

= 1.2.36 =
* Repairs the live-proven MariaDB migration failure `Duplicate key name 'queue'` on `smc_verification_requests` without changing the DB schema target.
* Recognizes only the exact historical non-unique BTREE `queue(status,assigned_reviewer)` signature, removes that obsolete secondary index, and lets the normal dbDelta pass create `queue(status,queue_type,assigned_reviewer)`.
* Treats fresh/current schemas as no-ops, refuses unknown queue-index shapes fail-closed, and read-back verifies both the verification-request and file-job queue indexes after DB promotion.
* Adds a WordPress 7.0.1 + MariaDB 11.4 regression gate that recreates the exact live legacy index, removes the three downstream tables that live migration had never reached, resets the fixture DB marker to 1.2.0, and proves successful promotion to 1.4.4.
* Runtime 1.2.36; DB schema 1.4.4; public contract 1.2.2. Live resolution still requires deployment and post-deployment verification.

= 1.2.35 =
* Applies Founder change-control dated 10 August 2026: File 00 no longer owns or requires two-factor authentication.
* Removes active authenticator/TOTP setup, challenge, recovery-code rotation, authenticator replacement and governed lost-factor recovery routes/handlers from the File 00 runtime.
* Removes the governed recovery source and recovery-lock source from the release and retires the managed Membership Recovery page.
* Advances the public membership contract to 1.2.2 with `mfa_required=false`, `mfa_owner=none`, `two_factor_ready=false` and `session_two_factor=false`; membership, publishing, messaging, appointment and transfer eligibility no longer depend on File 00 MFA.
* After DB schema 1.4.4 and audit readiness are proven, transactionally removes obsolete File 00 TOTP/recovery user meta, recovery-code rows and TOTP replay state, cancels unfinished lost-factor recovery cases, preserves historical audit rows and appends `file00_mfa_system_retired`.
* Keeps an internal primary-session compatibility stamp only so older File 00 governance methods cannot strand users behind a retired factor; it is not exposed as an MFA assertion and requires no user-entered code.
* Runtime 1.2.35; DB schema 1.4.4; public contract 1.2.2. Repository completion does not claim staging/live completion.

= 1.2.34 =
* Historical release: added governed lost-factor recovery for the then-active File 00 authenticator/MFA model.
* Required password reauthentication, contact-continuity binding, durable recovery cases, rate limits and serialized subject-level locking.
* Founder/Administrator recovery used a 24-hour cooling period and two distinct MFA-verified Administrator approvals.
* This recovery model is superseded by the Founder-approved File 00 MFA retirement in 1.2.35; its historical audit evidence remains preserved.
* Runtime 1.2.34; DB schema 1.4.4; public contract 1.2.1.

= 1.2.33 =
* Verifies immutable modern audit rows against an allowlisted historical keyring instead of assuming every row used the current derivation. This closes the pre-1.2.19 encoded-key transition that could falsely report a valid row as row_hash_mismatch.
* Recovers an interrupted legacy bridge only when blank legacy rows are one contiguous prefix and the first modern suffix row cryptographically proves a new epoch with an empty previous hash. Legacy rows remain unchanged and explicitly lower assurance.
* Adds an authenticated audit_key_id to every new audit row so future key rotations are deterministic; pre-1.2.33 rows remain verifiable in their original format.
* Keeps v1 migration anchors verifiable and creates stronger v2 anchors binding the source shape, exact legacy snapshot, signing generation, and—when already present—the first verified modern row.
* Prevents audit bootstrap DDL from running inside an existing membership/privacy transaction, avoiding MySQL implicit commits during security-sensitive operations.
* Uses the canonical audit subject hash for recent security events, restoring records previously queried under an incompatible digest.
* Runtime 1.2.33; DB schema 1.4.4; public contract 1.2.1. No audit row is deleted, rewritten, backfilled, or silently re-signed.

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
* Repairs the live Hostinger schema-bootstrap defect exposed by the then-active 2FA diagnostic: both smc_audit_log and smc_audit_tail were absent while the persisted DB version already matched the runtime, so normal maybe_upgrade() skipped table creation.
* Adds a guarded audit-infrastructure bootstrap independent of version equality, initializes a new explicit audit-chain epoch only when both audit tables have never been marked initialized, and refuses silent auto-repair if a previously initialized or partially present audit schema later disappears.
* Advances the runtime DB schema marker to 1.4.3 so existing 1.4.2 installations also pass through the normal schema reconciler.

= 1.2.28 =
* Historical release: added safe live diagnostics for TOTP enrollment audit failures without exposing hashes, secrets, or key material.

= 1.2.27 =
* Historical release: separated TOTP mismatches from enrollment storage/audit/session errors and extended pending authenticator setup to 20 minutes.

= 1.2.25 =
* Authenticated admin-post CSRF compatibility hotfix: a valid WordPress session nonce became the authoritative gate for logged-in File 00 actions. Optional Origin/Referer mismatch remained audit-visible while public guardian verification kept a strict origin check after nonce validation.

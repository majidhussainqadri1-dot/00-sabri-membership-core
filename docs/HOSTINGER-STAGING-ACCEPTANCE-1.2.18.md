# File 00 — Hostinger Staging Acceptance Execution Packet

**Release under test:** File 00 `1.2.18`  
**Immutable File 00 baseline:** `3a84c32a6ddad151f2ed09d244fa8aa536a58108`  
**Prepared:** 2026-08-08  
**Status:** EXECUTION READY — **not Staging-Accepted until real Hostinger evidence is attached and every mandatory gate is closed**.

## Governing rule

Repository/code completion, deterministic packaging and automated QA are not staging acceptance. Staging acceptance requires real WordPress/Hostinger execution, current companion contracts, real roles/data/providers, browser/accessibility/security tests, backup/restore and rollback rehearsal, zero known unresolved defects, and explicit Founder acceptance.

## Exact candidate matrix

| File | Candidate to stage | Evidence state | Staging instruction |
|---|---|---|---|
| 00 Membership Core | `main` `3a84c32a6ddad151f2ed09d244fa8aa536a58108`, runtime `1.2.18` | merged; exact-main QA green | mandatory |
| 01 Platform Foundation | PR #3 `ad34ecf316261666769d33a8ce3acac9c019ab73`, `2.0.0` | exact-head QA/package candidate | mandatory current candidate |
| 02 Authentication | PR #7 `c895ec17c631e6a28c86aa659bf947f9d326dc4d`, `1.2.0` | exact-head QA/package candidate; governing-plan freshness reviewed separately | mandatory auth dependency |
| 03 Profiles & Doctors | `main` `0db6161b7f8906e65387f1db9bb4fc6f215d00ef`, `1.0.0-rc3` | merged candidate | mandatory |
| 08 Clinic & Appointments | PR #6 `6e9367430bc8f1ad956d4fe32b6223ba4eeb7727`, `1.0.1` | exact-head CI `31100693352` green; ZIP `54f639…bb6f3` | mandatory |
| 09 Doctor Verification | PR #6 `f58b04f4282c1f907a9cf3043d2f9394fbccd640`, `1.2.0-rc3` | exact-head QA/package candidate | mandatory |
| 12 PDF Library | PR #2 `1ddc48e0271c896f32dbaa6d53a41711c22031ed`, `0.2.0` | corrective QA-green candidate | mandatory |
| 17 Communication Network | `main` `c4bb3d399b309d6f7f4f77f8bb376df25ccad558`, `2.0.3` | merged | mandatory |
| 18 Marketplace | PR #3 `c402d872fd00a0400b4718e47c02cb9d404bcd9d`, `2.1.0` | exact-head CI `31202785221` green; ZIP `17a9d7…b58f` | mandatory |
| 19 Notifications | `main` `5cb83d399f35ae1636415fb83373b6ba282e3685`, `3.0.0` | merged; QA green | mandatory |
| 20 Unified Shell | `main` `178606ed1599c88dce7ef45cb69e2e90081cd519`, `1.4.3` | merged; QA green | mandatory |
| 21 Home/News | `main` `ac83c987ab39d599ec4f5f092f1a1dedcaeaa1ee`, package/runtime `1.0.5/1.0.3` | merged | mandatory |
| 22 Composer | PR #24 `ce3dc881395cf22e5b33a538cbb7e104d1a51931`, `1.0.0-rc.2` | exact-head QA/package candidate | mandatory |
| 23 Publishing Dashboard | `main` `a8a8c805f4730998ccb44bd95c87591836561759`, `1.2.0` | merged | mandatory |
| 24 Security/Privacy | `main` `3c5ea8ef342983630a7973274d3409c34409f86c`, `0.99.0` schema `0.25.5` | merged repository candidate | mandatory assurance plane |
| 25 Visual Experience | PR #1 `64ccd17ca7c2055dd7f31365a91861b6aa28139c`, `0.14.0` | exact-head QA artifact | mandatory |

All File 00-plan-named integrations are now represented. Source-ref pinning is not package acceptance: every staged package still requires exact ZIP/checksum/manifest evidence before PASS.

## File 00 package identity

- Runtime: `1.2.18`
- DB schema: `1.3.0`
- Public membership contract: `1.2.0`
- Advanced Trust contract: `1.0.0`
- deterministic plugin ZIP SHA-256: `935ffa474625f25e64aad780d058de82b2403b1964bc4ce935a2b4551a764b43`
- exact-main Actions artifact ID: `9020924990`
- artifact wrapper SHA-256: `aa4bc3b845506685fa39ed705053d82b3fe774f5fb0fe7a16f838770374fbf6d`

## Gate A — Environment and immutable evidence

- [ ] Confirm staging hostname, WordPress version, PHP version, MySQL/MariaDB version, LiteSpeed/cache mode and active production theme.
- [ ] Record staging database/file backup identifier before any change.
- [ ] Verify backup by restoring it to an isolated staging/clone; a backup existence screen alone is not acceptance.
- [ ] Record every plugin candidate: file number, exact commit/head, version, ZIP checksum and source/package manifest.
- [ ] Confirm production secrets are not copied into public logs/screenshots/evidence.
- [ ] Confirm File 00 master key/private storage/scanner/provider configuration exists in protected staging configuration.

## Gate B — Fresh install

On a clean staging database/site clone:

- [ ] Install File 00 `1.2.18` exact package and activate.
- [ ] Schema `1.3.0` is created idempotently; roles/capabilities/pages/storage/cron/outbox health is green.
- [ ] No PHP fatal, database error, rewrite loop, public sensitive data or unexpected role mutation.
- [ ] Deactivate/reactivate is non-destructive.
- [ ] Normal uninstall is non-destructive; destructive purge remains separately guarded.

## Gate C — Upgrade and migration

- [ ] Upgrade from the latest production-like File 00 baseline and representative legacy dataset.
- [ ] Also exercise the documented historical upgrade path required by the File 00 plan.
- [ ] Re-run upgrade twice to prove idempotency.
- [ ] Inject interrupted/concurrent migration and verify lock/retry/reconciliation behavior.
- [ ] No unexplained drift in users, applications, guardian records, role grants, private evidence, audit/outbox records or revocation state.
- [ ] Forward-only migrations are explicitly listed with a compensating rollback plan.

## Gate D — File 02 authentication / passkey integration

Stage File 02 PR #7 candidate `1.2.0` rather than its stale historical `main` implementation.

- [ ] Register/login/email verification/password recovery/session management succeed.
- [ ] Google OAuth/link/unlink succeeds with real staging redirect configuration and safe collision handling.
- [ ] File 02 emits the preserved File 00 Advanced Trust `smc_file02_authentication_assurance_v1` `1.0.0` contract.
- [ ] Fresh passkey assertion elevates File 00 authentication assurance; stale/spoofed/foreign-owner/future/old-revalidation claims fail closed.
- [ ] Hardware-backed requirement is never fabricated when attestation does not prove it.
- [ ] WebAuthn enrollment/sign-in/revocation/replay/counter-regression and private-page no-store/noindex behavior pass on real browsers/authenticators.
- [ ] New-device/security alert, remote sign-out, emergency lockdown and protected recovery work end-to-end.

## Gate E — File 00 membership/guardian/identity workflows

Use representative adults, minors, Founder/Admin, doctor/reviewer and ordinary members.

- [ ] Account application: name/contact/address/identity handoff is correct.
- [ ] Email and mobile ownership verification.
- [ ] Current age is recomputed synchronously; under-minimum account is denied without relying on cron/cached age.
- [ ] Minor guardian consent is verified, versioned, revocable and current-policy bound.
- [ ] Guardian replacement requires a new verified successor and invalidates affected sessions/trust.
- [ ] Identity-document upload is private, scanner-gated and authorization-bound.
- [ ] File 00 TOTP/recovery MFA and File 02 passkey assurance remain separate owners with correct provenance.
- [ ] Critical identity changes force revalidation and synchronous protected-action blocking.
- [ ] Reviewer decision, professional dual-approval boundary, suspension, appeal, restoration and periodic reverification pass.
- [ ] Founder/Admin institutional precedence cannot be downgraded by legacy application state.

## Gate F — File 19 notifications

- [ ] Exactly one File 20 notification bell/center is rendered.
- [ ] File 00 factual events create deduplicated File 19 notifications; no duplicate delivery on retry.
- [ ] Sensitive security/identity/guardian data is redacted from external channel text.
- [ ] Deep links re-check File 00 authorization/state at click time.
- [ ] Quiet hours/preferences/digests do not suppress mandatory security/safety notices beyond policy.
- [ ] SMTP/email provider failure shows truthful queued/degraded state; retry/dead-letter/reconciliation work.
- [ ] Optional SMS/push is tested only if protected credentials/providers are actually configured; missing provider is not reported as delivered.

## Gate G — File 20 shell and File 24 assurance

- [ ] File 20 owns the one global shell; File 00 does not duplicate header/navigation/layout.
- [ ] File 00 account/security routes map to the correct private/minimal shell context and remain noindex/no-cache.
- [ ] Safe Mode/restricted security states do not create an alternate authorization owner.
- [ ] File 24 typed/fresh risk context can strengthen File 00 step-up but cannot weaken File 00 native enforcement.
- [ ] Stale or malformed File 24 containment/risk evidence fails closed or degrades to Unknown as specified.
- [ ] File 24 unavailable does not silently allow protected writes; optional assurance panels degrade honestly.
- [ ] Security diagnostics never expose raw identity documents, keys, secrets, backup locations or private incident evidence.

## Gate H — Privacy lifecycle

- [ ] WordPress/File 00 export returns only the requester's authorized data and no secrets/token hashes/private foreign records.
- [ ] Erasure coordinates primary data, private evidence, caches/indexes/projections and external adapters as defined.
- [ ] Inject storage/outbox/provider failures; erasure must not falsely report completion.
- [ ] Retention/legal hold prevents only the scoped deletion and is auditable/reviewable.
- [ ] After restore, deletion/rights ledger is replayed and previously erased/restricted data does not silently reappear.
- [ ] Trust/security timeline exposes only allowlisted minimum fields to self/authorized reviewer; anonymous access is denied.

## Gate I — Security/adversarial

- [ ] IDOR/object/field/state authorization.
- [ ] CSRF/nonce, replay, race/concurrency, duplicate tap/callback, stale session/cache and privilege-loss-mid-request.
- [ ] Path traversal/symlink/MIME/polyglot/archive-bomb/private-download authorization.
- [ ] Break-glass dual-control, hardware-backed step-up, bounded TTL and one-time consume.
- [ ] Delegated authority is suspended immediately when the grantor loses authority/protected status.
- [ ] Compromised-account containment strips protected capabilities synchronously.
- [ ] Revocation propagation uses opaque subject only and downstream consumers meet the declared SLA.
- [ ] Disk-full/database-write/audit/revocation/provider failures do not return false success.

## Gate J — Browser, accessibility, RTL and weak network

- [ ] Major desktop/mobile browsers and 320–1920px viewports.
- [ ] Keyboard-only, visible focus, managed modal focus, screen-reader name/role/value.
- [ ] 200% and 400% zoom/reflow; no horizontal page overflow on core membership/auth routes.
- [ ] Urdu/Arabic RTL + English LTR mixed labels and data.
- [ ] Slow/offline/reconnect, session expiry, duplicate submission, upload cancel/resume and conflict recovery.
- [ ] Active production theme plus safe fallback/default-theme smoke test where applicable.

## Gate K — Backup, restore and rollback rehearsal

- [ ] Verified files + database backup.
- [ ] Restore into isolated staging and verify record counts, keys/private evidence, audit, queues, routes and role journeys.
- [ ] File 00 code rollback to previous supported version with schema/data compatibility proven.
- [ ] Roll back File 20 settings/page mappings without damaging companion data.
- [ ] Cache purge and post-rollback smoke tests.
- [ ] Inject repair/rollback failure and prove resumable recovery/diagnostics.
- [ ] Record measured recovery time and operator steps.

## Gate L — Final acceptance

Staging-Accepted may be set to **true** only when:

1. every mandatory checkbox above is PASS with dated evidence;
2. all current required companion candidates are pinned and compatible;
3. no known Critical/High/Medium/Low repository or staging defect remains, unless the Founder explicitly accepts a named, time-bounded risk;
4. backup restore and rollback rehearsal pass;
5. security/privacy/accessibility evidence is attached;
6. Founder acceptance is explicit and dated.

Until then: **Specified = complete; Coded = complete for File 00 repository scope; Packaged = complete; Automated-QA Green = complete; Staging-Accepted = pending; Live-Deployed = pending; Operational = pending.**

## Evidence recording format

For each test attach: `gate_id`, date/time/time-zone, staging URL/host, File versions + exact commits, tester/role, preconditions, steps, expected result, actual result, PASS/FAIL/BLOCKED, defect ID if failed, screenshots/log references with secrets redacted, rollback/cleanup state and retest evidence.

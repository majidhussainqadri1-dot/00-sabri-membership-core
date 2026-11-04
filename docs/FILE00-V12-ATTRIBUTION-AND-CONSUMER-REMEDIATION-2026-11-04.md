# File00 — V12 implementation remediation and real consumer tests — 2026-11-04

**Scope:** exact-source Draft PR #55 only, source software 1.2.44 / source schema 1.4.5 / membership contract 1.2.3. NOT source-complete certification, NOT a deployed/live repair and NOT authorization to merge/release.

## Frozen realities

- Repository: `majidhussainqadri1-dot/00-sabri-membership-core`; `main` at `2fa7c022ee9cd1b65432e900579512f304532442` (not changed in this task).
- Reviewed candidate parent HEAD: `e73d27f46da90dc2cce31855f695eaa48bcc2bd5`, Draft/unmerged. This document commit will have a new SHA requiring its own exact-head CI.
- Installed/deployed File00, DB version, migration state, checksum and live verification: **unverified/deferred**.
- Real CI fixture: WordPress 7.0.1 / MariaDB 11.4 and exact plugin/source checkouts, NOT the Hostinger/staging/live installation.
- File04 current source SHA `bae1c70a168a09d7df09d634bd15050837e0b80a`.
- File08 PR29 candidate SHA `1a358ead5ed02938fe2ee0bcb45494d53b4ffa8d` (not merged/installed).
- Current File02 main SHA `224c39bcb8c28f77504c7348dbad41226753c7e8`, plugin version **1.3.4** (existing File00 release workflow also retains historical pinned File02 1.3.3 separately).
- File00 PR52 `1.2.45` divergent and unmerged; PR54 UUID helper already included in PR55 by source content. Resolve version identity before eventual release; do not blindly merge.

## Two *new* proven, fixed source defects (not 123)

### F00-V12-20261104-D01 — attribution-only placeholder had a WordPress subscriber role

Root cause: `SMC_CF01_Contract::ensure_legacy_author_placeholder_user()` created File04 non-login attribution identity with `role='subscriber'`; a sentinel must not be eligible for any ordinary WP capability, even if authentication is blocked.

**Red-first:** `qa/v12-file04-placeholder-persistence-runtime.php` added a regression for `role==='' `. Red commit `2c16df3ade8902d6f5128fc6e560c578a33f3e46`; GitHub job `126668301246` failed exactly `File04 non-login attribution sentinel is provisioned with no WordPress role`.

**Fix:** new user has explicit empty role; any unexpected roles/direct capabilities during creation reject the receipt and trigger best-effort compensation. Existing exact marked/audited attribution sentinel removes historical subscriber role using WP `set_role('')`, re-reads current user and rejects persistent capabilities. Cross-account logins are not relabelled. Owner file: `source/sabri-membership-core/includes/class-smc-cf01-contract.php`; `MANIFEST.sha256` updated. Current no-login/session blocks remain controlling.

### F00-V12-20261104-D02 — membership import automatically granted a role despite empty role

Root cause: `SMC_Contracts::register_account`, registered on WP `user_register` action, ran when File00 created the reserved sentinel and inserted a normal membership draft/pending role. Real WordPress/MariaDB initially rejected sentinel creation (6 failed real assertions), though in-memory test had passed. Synthetic CI diagnostics showed real user creation itself was possible, and File00 had emitted `account_imported` for the reserved identity.

**Fix:** the ordinary membership importer now excludes only the exact reserved username `sabri_legacy_author_placeholder` before any normal membership mutation. Normal user registration remains untouched. Owner file: `source/sabri-membership-core/includes/class-smc-contracts.php`; source manifest checksum synchronized. No schema change.

**Independent real WP correction evidence:** `qa/v12-file04-wordpress-attribution-role.php` — **13/13 PASS**, including missing member application, zero roles/caps, login/session denial, old subscriber-role retirement, direct-capability contamination fail closed and digest spoof rejection.

## Real current-source multi-consumer evidence

- `qa/v12-real-wordpress-file04-file08-consumers.php`, using real WordPress/MySQL users/posts/usermeta, actual immutable File04 and File08 PHP consumer classes: **12/12 PASS**. Includes positive legacy author, governed deleted-author sentinel, no migration cap, guest denial, valid canonical File08 UUID, malformed/duplicate mismatch and recovered unique identity.
- An initial real WP consumer test produced **10 pass / 2 fail** because test revoked a WP role cap and reused the same request's stale `WP_User::allcaps`. Fixture fixed to model a new request using `wp_set_current_user(0)`, `clean_user_cache`, actor reload. The negative test then passed. This was a fixture scoping mistake, **not a newly proved File00 source defect**.
- Current **File02 1.3.4** was checked out at its immutable current main, actually activated beside the File00 candidate inside the test WordPress instance. `SA_Membership_Adapter::available`, `SAUTH_Account_Contract::provider_available`, storage, email-reconciliation class, and File00 canonical page routes passed. This supersedes only the *current consumer* smoke evidence, not historical pinned 1.3.3 tests or deeper auth/end-to-end acceptance.
- Dedicated CI workflow: `.github/workflows/file00-v12-attribution-wordpress.yml`. Historical full release regression CI remains in `.github/workflows/file00-review80-wordpress-mysql.yml`.
- Exact parent HEAD `e73d27f46da90dc2cce31855f695eaa48bcc2bd5`: **15/15 workflow runs completed SUCCESS**, 0 failed; new current File02 + real File04/08 consumer workflow included. Workflow names saying “Live” describe source QA jobs, not live website verification.

## Unresolved failure-recovery risk

If `wp_insert_user` succeeds but the DB fails to persist both no-login markers while compensation/deletion also fails, an unverified orphan can remain. Source denies login by reserved name, denies current session and never returns a valid attribution receipt. **Atomic cleanup/restore under actual DB outage is NOT certified**; operator recovery and further evidence needed. Do not say fully resolved.

## 123-ID acceptance truth

Governing Master Plan v12: **100 base R + 3 CEN + 20 EXT = 123**. The existing scanner individually inventories 123/123, provides 103 source signals and 103 per-ID static executions, with **0/123 independent full behavioral acceptances** under its strict acceptance definition. The 0 is an evidence limitation, **not 123 proven defects**; green CI on targeted cases does NOT turn the entire catalog into full acceptance.

Still pending: other current cross-file R051–R060 contracts (e.g., File03,09,17,19,20,21,22,23,24,25,26); installed multi-plugin WordPress staging tests; all per-ID positive+negative suites; browser/mobile/Urdu/Arabic RTL/keyboard/a11y/weak-network; backup/restore and rollback rehearsal; vendor/runtime configuration; exact deploy/DB parity; Founder approval and live re-test.

## Defect accounting for THIS task

| Field | Count / status |
|---|---|
| New reproducible File00 source defects confirmed | **2** |
| Those new source defects repaired and retested | **2/2** in exact CI / synthetic DB |
| New source defects still reproducibly failing in tested correction scope | **0 observed** |
| Historical PR55 source fixes | 9 documented earlier (not double-counted in 2 new) |
| File04/08 real WordPress source-consumer cases | **12/12 PASS** |
| Reserved sentinel real WordPress cases | **13/13 PASS** |
| File02 1.3.4 current source activation & contract | **PASS** |
| Exact parent HEAD CI | **15/15 completed/success** |
| Known unresolved full-failure-recovery scenario | **1** |
| 123 requirement-specific full behavioral acceptances certified | **0/123** |
| Branch | PR55 **Draft/unmerged**, no main change |
| Staging/live deployed verification | **NOT performed / NOT VERIFIED** |

**Required five separate truth fields:** Repository HEAD (main): `2fa7c022ee9cd1b65432e900579512f304532442`; Deployed Version: unknown; DB Version: unknown; Migration State: unknown; Live Verification Status: deferred/unverified.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

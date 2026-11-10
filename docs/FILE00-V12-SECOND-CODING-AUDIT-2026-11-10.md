# File 00 v12 — Second Coding Pass: Consent Trust Windows and Exact File06 Contract

**Date:** 2026-11-10 (Pakistan).  
**Authoritative plan:** `Sabri-Website-Master-Plan-v12.docx`, File 00 identity, age, consent and cross-module boundaries.  
**Repository:** `majidhussainqadri1-dot/00-sabri-membership-core`  
**Untouched repository main:** `2fa7c022ee9cd1b65432e900579512f304532442`.  
**Working corrective branch:** `fix/file00-file06-case-consent-provider-20261108`, Draft PR #56 stacked on Draft PR #55.  
**Starting candidate HEAD:** `1748584c578796b49eb1f43b7f6e41272bb08c7b`.  
**Pinned File06 candidate:** `majidhussainqadri1-dot/06-homeopathy-encyclopedia-foundation`, `audit/file06-v12-twenty-code-review-2026-11-08`, commit `308cb09f9375057fe83e2738e002e74b9aa68bbe` (not its README-only main branch).

This task is a **repository-code correction with disposable CI evidence**, NOT an assertion that Hostinger deployed these fixes. No production, staging or main branch write was made. Source plugin 1.2.44, source schema 1.4.5 and membership contract 1.2.3 are not verified installed versions.

## 1. Two additional, reproducible File00 source-security defects

### S2-D01 — TOCTOU between original eligibility and database named lock

`SMC_File06_Case_Consent::accept()` used to evaluate the patient's current membership assurance, the File06 case relation and the policy before `GET_LOCK()`. If a consent authority changed while the request waited, the code could persist a new receipt using the stale authorization.

- **Red-first test:** commit `ed7e33844fe4378ec26216b579301c2a8eada694`, workflow `42742413844` (PHP 7.4/8.3 isolated source QA deliberately failed on the stale patient eligibility case).
- **Bounded correction:** current patient identity, session/nonce, adult/member assurance, independently attested research relation and policy version/hash are re-evaluated after lock acquisition, before any consent DB insertion or existing-receipt reuse.
- **No policy weakening:** closed/refused/changed authority returns `smc_file06_consent_changed` without a newly accepted record.

### S2-D02 — TOCTOU after audit and before pending-to-active transition

`accept()` wrote its mandatory audit event and then activated the pending consent. A revoked File06 policy, revoked patient relation, or membership suspension during the audit callback could allow transition to `actor_type='self'`.

- **Red-first test:** commit `d43034457d6e29ccd5971b039aa25fbd03ccf1fa`; workflow `42743261841` (both PHP versions deliberately failed on stale policy after audit).
- **Bounded correction:** one shared `current_accept_policy()` authority method now governs *both* pre-insert and post-audit checks. If the second check fails, File00 cancels the pending row, leaves it non-authoritative, and raises operator reconciliation if cancellation cannot be persisted.
- **Security boundary:** an audit log alone cannot turn a stale or revoked patient study authorization into an active consent receipt.

**New File00 defects found and corrected on this pass: 2.** The earlier PR #56 revocation/duplicate-lock work (2 original defects and 2 hardening corrections) remains independently accounted for, not counted again. These are candidate-source defects, not demonstrated live incidents.

## 2. Actual WordPress / MariaDB source evidence

A disposable WordPress 7.0.1 + MariaDB 11.4 runner uses the real File00 plugin and schema. New tests complete a *synthetic, fully seeded* adult-member approval and consent flow, including:

- encrypted DOB and phone in the actual File00 application table; persisted approved membership and role;
- verified email/phone records and all required synthetic approved identity-document metadata;
- File00 real membership eligibility and identity assurance with an explicitly **synthetic typed File02 authentication claim**;
- explicit policy/relationship provided by **synthetic File06 test filters**;
- real `smc_consents` insert, mandatory audit, pending-to-accepted transition, immutable receipt and idempotent same-case reuse;
- consent invalidation on revoked File06 policy, revoked patient/case relation and real File00 suspension;
- second independent MariaDB connection blocking both case withdrawal and case acceptance with no extra active receipt;
- invalid nonce, cross-patient and wrong-case authorization denial.

**Result at exact candidate source+test commit `bf7c09a4b5a0d39f65099d8fa3af2878cfacaaa9`: 36/36 real MariaDB assertions PASS, 0 FAIL.** This is real SQL and File00 behavior, but uses synthetic provider claims. It is not installed File02/File06 end-to-end authorization.

Isolated `qa/file00-file06-consent-contract.php` tests: **61/61 PASS on each of PHP 7.4 and 8.3**. Exact candidate workflows: inherited release, CF01/Forty-Round and File04/File08 pinned-source consumer QA **4/4 complete/success** at the above SHA; final doc-only HEAD must be rechecked separately.

## 3. Three File06 companion contract blockers — NOT File00 defects

File06 repository **main** (`c6f44e923f2a6f2ed82d967d18422624169b2770`) is README-only, but its pinned 2026-11-08 *candidate branch* contains actual research classes. Inspection of all **52 PHP source files** in this exact candidate showed:

1. `HE_V12_Completion::consent_attestation()` invokes `he_v12_consent_receipt_verification_v1` with `evidence_ref`, `policy_version`, `locale`, `purpose`, `actor_id`, but **omits the canonical `research_public_id`**. File00 cannot safely verify case ownership from the receipt alone. File06 must supply an immutable owner-validated study UUID *after* study identity allocation.
2. No producer for File00-required `he_v12_file06_patient_relation_v1` was found in the 52 PHP files of that candidate. File06 or its explicitly authorized relation owner must return current, independently reviewed `owner_file='file-06'`, patient user ID, research UUID, version and distinct attester/approver evidence. A study author/reviewer cannot impersonate the patient.
3. No producer for `he_v12_file06_consent_policy_v1` was found in those 52 PHP files. File06 must publish the current Founder-approved localized policy text, exact SHA-256, version, locale and purpose, including withdrawal/revision semantics.

**Actual code boundary demonstrated in real WordPress:** with a complete case-bound request, File00 verifies the receipt; with the current File06 `HE_V12_Completion` producer, the required UUID is absent and File06 gets `he_v12_consent_independent_verification_required`. The file00 receipt stays unchanged and private/public case consent remains deny-by-default.

- `qa/file00-file06-pinned-protocol.php` (4 static protocol checks) and the real WordPress fixture pin the exact File06 branch SHA without writing to the other repository.
- CI green on the pinned mismatch gate means **fail-closed is proven**, NOT that interoperability is complete.
- Do NOT hack File00 to accept a bare consent boolean, author checkbox, missing UUID or unaudited receipt. The missing provider contracts must be implemented and tested in File06's own repository before paired integration acceptance.

## 4. Required File06 owner-side handoff

1. Reserve/persist immutable `research_public_id` before requesting case-specific patient consent. Explicitly bind it to the true current data subject; no author-supplied arbitrary patient ID.
2. Implement current File06 patient↔research relation and versioned consent-policy provider hooks, independently reviewed and fail-closed on suspended/stale/changed records.
3. Propagate that exact UUID in `HE_V12_Completion::consent_attestation()` verification request. Only a real File00 `verified=true` reply authorizes storage of the opaque receipt.
4. Verify File00 `he_v12_consent_receipt_current_v1` on every consent-bearing File06 approval/public read, including after withdrawal or File00 suspension. Revalidate caches/indexes and queue redaction; test under concurrent revoke/publish.
5. Add exact current source pair, real WP/MariaDB, negative/positive cross-plugin tests and maintain independent immutable SHA/manifest evidence, then approved staging deployment with backup/restore and live re-test.

No File06 repo modification or deployment is authorized by this File00-only pass.

## 5. Other open boundaries and governance

- PR #55, #56, #54, #52 are still separate; #52's divergent software version 1.2.45 must be reconciled before release. Do not blindly merge duplicate UUID adapters or overwrite main.
- Earlier File04 reserved-author sentinel's non-atomic orphan cleanup on simultaneous DB write+deletion failure remains an operator recovery blocker; this task did not claim resolution.
- Individual **123/123** Master Plan v12 File00 acceptance is not complete; the strict historical evidence ledger is not a count of 123 code failures.
- File02/04/08/09/17/19/20/21/22/23/24/25/26 current consumers, browser/RTL/accessibility, backup/migration/performance and approved staging/live re-test remain separate gates.

## Five separate runtime-reality fields

| Field | Result |
|---|---|
| Repository main HEAD | `2fa7c022ee9cd1b65432e900579512f304532442` |
| Candidate | PR #56 on PR #55; exact final SHA from fresh GitHub HEAD/CI |
| Deployed Version | **UNVERIFIED** |
| DB Version / Schema | **UNVERIFIED** (source target `1.4.5` only) |
| Migration State | **UNVERIFIED** |
| Live Verification Status | **NOT PERFORMED / UNVERIFIED** |

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**

**Measured pass summary:** 2 newly reproduced File00 trust-window defects corrected on candidate; 3 separately proved external File06 contract blockers not corrected here; 61 isolated tests per PHP version, 36 real SQL/WP tests, 4 pinned static protocol signals; 0 live changes and 0 live fix claims.

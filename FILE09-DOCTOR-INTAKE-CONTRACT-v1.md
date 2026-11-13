# File 00 ↔ File 09: Doctor Professional-Verification Intake Contract v1.0.0

**Repository-only candidate, 13 November 2026.** This is not a clinical permission or a representation of deployed WordPress/DB state.

## Source-of-truth boundaries

* Owner: File 00 (membership, identity and approved/pending membership roles). Consumer: File 09 (professional-verification application).
* Contract name `smc.file09.doctor-intake`; exact major/minor/patch `1.0.0`; PHP provider `SMC_File09_Intake_Contract::assertion(int $user_id, array $context)`.
* Request MUST be `action=doctor_verification_intake`, `purpose=professional_credential_application`. Optional requested jurisdiction is ISO 3166-1 alpha-2; File 00 authenticates it against its canonical identity country.
* The assertion carries only an opaque File 00 subject UUID/positive record version, minimal age and country context, expiry, role-request prerequisite and outcome. 60-second TTL; no password, login secret, raw identity document, date of birth, private licence or credential evidence.
* `allow` means **File 09 may let this user apply for independent verification only**. The provider advertises `grants_clinical_authorization=false`, `grants_professional_verification=false`, `grants_patient_record_access=false`, `grants_prescription_authority=false`, `grants_public_badge=false`, `grants_appointment_authority=false`.
* A `deny` or `unknown` is NEVER upgraded by File 09. Unknown contract major/version, stale data or absent dependencies fail closed.

## Preverification vs global eligibility

File 00's general `eligible` and `can_practice` must **NOT** change. A newly requested doctor role may be `pending` professional verification; general eligibility may therefore remain `false`. This consumer-only intake permission checks other prerequisites without requiring its own result to have already occurred.

Minimum File 00 prerequisites for `allow`: authenticated existing user; approved and unsuspended membership; an actual requested doctor role with a current `pending` or `approved` owner grant (not a suspended/rejected/expired grant); current File 00 identity evidence, verified phone/email and any required guardian consent; confirmed adult professional age (minimum 18, strictest applicable policy may be higher); explicit canonical jurisdiction; valid row version; no owner-policy revocation; **Advanced Trust/containment/continuity/transition/reverification checks must pass**. Neither File 00's retired MFA flag nor absence of completed File 09 professional verification becomes a reason to deny intake by itself.

**Critical security rule:** `SMC_MFA_Retirement::advanced_trust_allows_without_mfa()` is re-checked at every assertion. This blocks risk containment, critical identity changes, transition holds, stale re-verification and continuity restrictions, which also make global `eligible=false`. If its owner implementation is missing, fail `unknown`. A pending professional credential is the only condition intentionally decoupled.

## Consumer responsibility

File 09 must separately check exact contract/version/subject/freshness/role/age/jurisdiction and reject any assertion that claims clinical, patient, professional, public badge, prescription or appointment authority. File 09 retains independent credential review, signed File 00 verification claims, secure evidence handling and human final decisions. File 02 remains login/reauthentication owner.

## Evidence and release constraints

* File 00 source QA: `qa/file09-intake-contract-runtime.php` is included in `npm test`; PHP 7.4/8.3 release workflows must succeed on the exact head and exact package checksum.
* File 09 consumer QA: `tests/file00-preverification-intake.php` and `tests/file00-owner-consumer-integration.php`. The latter executes this **real File 00 provider file** from an exact-SHA GitHub checkout under the singular File 09 release workflow. Database/WordPress functions there are synthetic; this is not Hostinger staging.
* No schema or migration change is introduced for this source-level contract. However real installed File 00 schema, `role_grants`, identity-document tables, keyring and migration state must be witnessed before staging acceptance.
* Live site exact deployed version, DB version, migration state and deployed SHA/ZIP **UNVERIFIED**. No production deploy or real user-data mutation in the source-change work.
* This change alters source while leaving the candidate plugin header at 1.2.44 for compatibility with existing exact-version CI; this is **NOT permission to deploy a same-version replacement**. Before any release, approve an explicit independently identifiable version and immutable package checksum/rollout, and rerun all version, migration and rollback gates.

Issue: https://github.com/majidhussainqadri1-dot/00-sabri-membership-core/issues/57

**Acceptance:** both repositories' exact-head CI; independent code review; no scope widening; stage File 00 and File 09 together; 32 File 09 staging gates; isolated backup/restore; Founder acceptance; exact live retest and deployed parity. P0 is only `source-fixed` once both branches merge; never mark a Live incident resolved without Live proof.

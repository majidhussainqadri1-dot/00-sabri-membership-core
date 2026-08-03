# File 00 — Ilhami Iterative Zero-Defect Cycle 1.2.6

## Governing method

After repository coding reaches its planned completion point, review does not stop after one audit. The cycle is:

1. fresh forensic review;
2. correction of every confirmed defect;
3. regression evidence for each correction;
4. a new independent review of the corrected whole;
5. repetition until two consecutive full review passes discover no new repository-correctable defect and exact-head CI/package verification passes.

“Zero defect” means zero known defects under the defined repository, automated-test and review scope. It does not replace Hostinger staging, real-provider, browser/accessibility, legal, load, recovery or Founder-acceptance gates.

## Round 1 findings corrected

- canonical public eligibility omitted email, mobile and guardian predicates;
- initial submission bound reviewer generation to the pre-submit row version;
- OTP and guardian provider failures could leave live undelivered secrets;
- pending and enabled 2FA metadata writes were not read-after-write verified;
- recovery rotation accepted any non-error result instead of exact success;
- recovery-code replacement and one-time receipt persistence were not atomic;
- recovery-code consumption and session elevation were not atomic;
- privacy erasure did not retry containment when a durable lock already existed;
- unknown gender values could fall through to the male minimum-age rule;
- guardian withdrawal did not synchronize the verification request;
- institutional repair could be marked complete after an incomplete corrective pass.

## Round 2 findings corrected

- recovery rotation audit failure could occur after code commit and strand an undisclosed code set;
- recovery code could be consumed before session elevation succeeded;
- guardian delivery had the same valid-undelivered secret class as contact OTP;
- self-service transitions committed before mandatory audit evidence;
- lifecycle restriction, adulthood transition and institutional attention handling did not consistently couple state and evidence.

## Stop condition

Repository stop condition requires:

- all plugin PHP files lint cleanly;
- inherited QA suites pass;
- Ilhami static and runtime suites pass;
- deterministic ZIP and manifest verification pass;
- two consecutive fresh reviews add no new confirmed repository-correctable defect;
- exact-head GitHub Actions succeeds.

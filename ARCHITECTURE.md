# File 00 Architecture Contract

## Canonical ownership

File 00 is the authoritative owner of membership eligibility, government-identity assurance, guardian consent, membership verification state, two-factor membership assertions, retention controls, and membership audit evidence.

It deliberately does not implement:

| Domain | Canonical owner |
|---|---|
| Email/password and Google authentication | File 02 |
| Public member and doctor profiles | File 03 |
| News/feed publishing | File 04 |
| Encyclopedia content | File 06 |
| Doctor professional evidence and verification | File 09 |
| Clinic/appointments and marketplace transactions | Files 08 and 18 |
| Network and messages | File 17 |
| Notifications and delivery retries | File 19 |
| Global application shell and navigation | File 20 |
| Publishing workspaces and composer UI | Files 21, 22 and 23 |
| Assurance evidence and resilience oversight | File 24 |
| Public visual timeline/profile experience | File 25 |

## Versioned consumers

Consumers call:

```php
$membership = smc_membership_assertions( $user_id );
$communication = smc_communication_assertions( $user_id );
```

Both responses include `contract_version`. Consumers must fail closed on unsupported contract versions, explicit hard blocks, stale session challenges, missing guardian assertions, or unavailable canonical professional verification.

## Authorization boundary — 1.2.4

`SMC_Authorization` is the canonical request and capability boundary. It replaces the older broad handlers after `SMC_Contracts::init()`.

The boundary enforces these rules:

1. Institutional Founder/Administrator identity grants compatibility authority but never defeats an explicit hard block.
2. File 00 does not remove core WordPress `manage_options`; it denies File 00/platform/reviewer/publishing capabilities and protected File 00 requests when membership evidence is not effective.
3. Recovery uses exact action/route allowlists. Prefixes such as arbitrary `smc_*` or `sa_*` are never sufficient.
4. Protected capabilities and REST mutations require effective eligibility, verified ordinary-account email/mobile ownership, guardian validity when applicable, and a current session two-factor challenge.
5. Anonymous public reading remains outside File 00. Safe authenticated reads are not globally blocked; route owners may mark sensitive reads as membership-protected through `smc_rest_request_requires_membership`.
6. Founder identity cannot be ordinarily reassigned after configuration. Recovery is an explicit audited process or immutable configuration constant.

## Fail-closed boundaries

Identity processing stops when the master key, private-storage permissions, symlink safety, content scanner, authenticated envelope, audit write, database mutation, document lock, or deletion verification fails.

Public reading remains open. A logged-in applicant may use the exact application, status, guardian, contact-verification, appeal, resubmission, and two-factor recovery routes. Membership restrictions apply to protected actions, protected REST requests, File 00 admin actions, and sensitive capabilities—not anonymous public content.

## Requirement governance

The normative requirements are `F00-R001` through `F00-R100` in the checksum-identified master-plan project artifact. The repository records the current implementation/acceptance status in `qa/requirements-traceability.json` and `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.4.md`.

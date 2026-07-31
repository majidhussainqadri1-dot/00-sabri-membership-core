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
| Network and messages | File 17 |
| Notifications and delivery retries | File 19 |
| Global application shell and navigation | File 20 |

## Versioned consumers

Consumers call:

```php
$membership = smc_membership_assertions( $user_id );
$communication = smc_communication_assertions( $user_id );
```

Both responses include `contract_version`. Sensitive capabilities require:

1. approved membership;
2. current eligibility;
3. any canonical professional-owner verification;
4. configured two-factor authentication;
5. a current session challenge.

## Fail-closed boundaries

Identity processing stops when the master key, private-storage permissions, symlink safety, content scanner, authenticated envelope, audit write, database mutation, document lock, or deletion verification fails.

Public reading remains open. A logged-in applicant may always use application, status, guardian, contact-verification, appeal, resubmission, and two-factor recovery routes. Membership restrictions apply to protected actions, REST requests, admin-post actions, and sensitive capabilities—not anonymous public content.

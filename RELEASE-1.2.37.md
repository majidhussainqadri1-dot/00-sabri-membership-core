# File 00 — Sabri Membership Core 1.2.37

## Live-first incident basis

This release exists for one live-proven migration defect discovered only after File 00 1.2.36 was deployed to Hostinger and the earlier `queue` collision was successfully crossed.

Exact deployed Hostinger copies of `class-smc-installer.php` and `class-smc-schema-compat.php` were compared with repository `main` at `6bccc011297fd7f4a81152c4563610d2d032e24c` and matched the repository blobs. Live MariaDB then repeatedly reported:

`Schema migration failed: Duplicate key name 'decision'`

The live `wp_smc_approval_votes` table had the historical non-unique BTREE index:

`decision(request_id,decision)`

Current File 00 schema 1.4.4 requires:

`decision(request_id,approval_generation,decision)`

WordPress `dbDelta()` can attempt to add the changed same-name index without first dropping the historical definition, which MariaDB correctly refuses.

## Correction

Release 1.2.37 extends the existing narrow schema-compatibility preflight so that:

- the already-proven queue repair remains intact;
- an absent or already-current `decision` index is a no-op;
- only the exact live-proven legacy `decision(request_id,decision)` non-unique BTREE with no prefix lengths may be removed automatically;
- every unknown index shape or attribute fails closed;
- normal `dbDelta()` remains the authority that creates the current schema;
- after DB promotion, the exact current approval decision index is read-back verified together with both current queue indexes;
- DB schema target remains `1.4.4` and public membership contract remains `1.2.2`.

## Regression acceptance

The WordPress 7.0.1 + MariaDB 11.4 gate recreates both live-proven historical named-index states, resets the fixture to the pre-upgrade DB marker, removes downstream tables that live migration had not reached, and requires the normal File 00 migration to:

1. repair the historical queue index;
2. repair the historical decision index;
3. create the downstream outbox/inbox/repair tables;
4. reach DB schema `1.4.4`;
5. read-back verify the current index signatures;
6. complete the Founder-approved File 00 MFA retirement flow without damaging the audit chain.

## Truthful release boundary

Repository/CI/package completion does not equal live resolution. This incident can be called resolved only after 1.2.37 is deployed to Hostinger and live evidence confirms DB schema 1.4.4, current queue and decision indexes, downstream tables, no fresh migration failures, and successful post-migration MFA-retirement cleanup.

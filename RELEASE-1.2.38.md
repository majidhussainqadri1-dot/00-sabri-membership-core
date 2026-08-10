# File 00 — Sabri Membership Core 1.2.38

## Live-first incident basis

This release exists for the next live-proven migration defect discovered only after File 00 1.2.37 was deployed to Hostinger. Live DB evidence showed File 00 still at schema `1.2.0` with `Role-grant backfill failed.` The affected first application belonged to the Founder/Administrator account (`user_id=1`), and no `role-grants-to-1.3.0` checkpoint had been persisted.

Exact v1.2.37 source establishes the contradiction: `SMC_Contracts::sync_wordpress_roles()` intentionally refuses to mutate WordPress Administrators by returning `false`, while `SMC_Installer::backfill_role_grants()` treats any `false` as a migration write failure. Administrator role protection is required; classifying that protection as migration failure is the defect.

## Correction

Release 1.2.38 changes the synchronization contract narrowly:

- missing users remain a failure;
- privacy-erasure locks remain a failure;
- a valid `manage_options` Administrator remains protected from File 00 managed-role mutation;
- that protected Administrator path returns success because no mutation is required;
- ordinary membership-role reconciliation is unchanged;
- canonical File 00 role grants still backfill normally and remain separate from the native Administrator role.

## Regression acceptance

The WordPress 7.0.1 + MariaDB 11.4 gate now creates an Administrator with a File 00 draft application, records its exact native role set, deletes the `role-grants-to-1.3.0` checkpoint and grant, recreates both earlier live-proven named-index legacy states, resets the DB marker to `1.2.0`, then requires the normal migration to:

1. repair the legacy queue index;
2. repair the legacy approval-decision index;
3. complete the Administrator role-grant backfill checkpoint;
4. create the canonical pending membership grant;
5. leave the native Administrator role set unchanged;
6. preserve institutional-account precedence despite the draft legacy application;
7. create downstream tables and promote DB schema to `1.4.4`.

## Truthful release boundary

This is a repository correction until exact-head CI is green and an installable package is produced. It is not a live resolution until the exact package is deployed to Hostinger and live evidence confirms schema `1.4.4`, completed role-grant migration, unchanged Founder/Administrator native roles, no fresh migration failure, downstream tables, cleanup/retirement completion, and post-deployment smoke/retest results.

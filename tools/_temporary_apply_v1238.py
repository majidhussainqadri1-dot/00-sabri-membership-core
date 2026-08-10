from pathlib import Path

ROOT = Path('.')


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8')


def replace_once(path, old, new):
    text = read(path)
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one occurrence, found {count}: {old!r}')
    write(path, text.replace(old, new, 1))


# Root cause: keep Administrator native roles immutable, but represent that
# intentional no-op as success instead of a write failure.
contracts_path = 'source/sabri-membership-core/includes/class-smc-contracts.php'
old_guard = """\t\t$user = get_userdata( absint( $user_id ) );
\t\tif ( ! $user || smc_privacy_erasure_lock( $user_id ) || user_can( $user, 'manage_options' ) ) {
\t\t\treturn false;
\t\t}
\t\t$desired = array();"""
new_guard = """\t\t$user = get_userdata( absint( $user_id ) );
\t\tif ( ! $user || smc_privacy_erasure_lock( $user_id ) ) {
\t\t\treturn false;
\t\t}
\t\t/*
\t\t * WordPress Administrators are institutional accounts whose native role is
\t\t * deliberately outside File 00 managed membership-role mutation. Treat
\t\t * that protected no-op as successful synchronization; callers must not
\t\t * misclassify the protection itself as a failed write.
\t\t */
\t\tif ( user_can( $user, 'manage_options' ) ) {
\t\t\treturn true;
\t\t}
\t\t$desired = array();"""
replace_once(contracts_path, old_guard, new_guard)

# Files where every 1.2.37 occurrence is current-release identity rather than
# immutable historical evidence.
current_release_files = [
    '.github/workflows/audit32-wordpress-mysql.yml',
    '.github/workflows/cf01-contract.yml',
    '.github/workflows/file00-button-actions.yml',
    '.github/workflows/file00-three-plan-qa.yml',
    '.github/workflows/mfa-retirement-wordpress-mysql.yml',
    'package.json',
    'package-lock.json',
    'qa/advanced-trust-contract.mjs',
    'qa/audit-legacy-anchor-contract.mjs',
    'qa/audit32-wordpress-mysql.php',
    'qa/authorization-boundary-contract.mjs',
    'qa/cf01-contract.mjs',
    'qa/completion-hardening-contract.mjs',
    'qa/dual-plan-runtime-completion-contract.mjs',
    'qa/fifth-fresh-review-contract.mjs',
    'qa/forty-round-1.2.10-contract.mjs',
    'qa/forty-round-contract.mjs',
    'qa/fourth-fresh-review-contract.mjs',
    'qa/ilhami-cycle-contract.mjs',
    'qa/institutional-lifecycle-contract.mjs',
    'qa/latest-central-contract.mjs',
    'qa/master-plan-traceability-contract.mjs',
    'qa/membership-state-contract.mjs',
    'qa/mfa-retirement-contract.mjs',
    'qa/mfa-retirement-wordpress-mysql.php',
    'qa/third-fresh-review-contract.mjs',
    'qa/three-plan-runtime-completion-contract.mjs',
    'source/sabri-membership-core/sabri-membership-core.php',
]
for path in current_release_files:
    text = read(path)
    if '1.2.37' not in text:
        raise SystemExit(f'{path}: current 1.2.37 identity not found')
    write(path, text.replace('1.2.37', '1.2.38'))

# Keep the now-historical 1.2.37 release record in the current QA artifact.
qa_plan = '.github/workflows/file00-three-plan-qa.yml'
text = read(qa_plan)
old = '          test -f RELEASE-1.2.38.md\n          test -f RELEASE-1.2.36.md'
new = '          test -f RELEASE-1.2.38.md\n          test -f RELEASE-1.2.37.md\n          test -f RELEASE-1.2.36.md'
if old not in text:
    raise SystemExit('file00-three-plan-qa.yml: release-file test marker missing')
text = text.replace(old, new, 1)
old = '            RELEASE-1.2.38.md\n            RELEASE-1.2.36.md'
new = '            RELEASE-1.2.38.md\n            RELEASE-1.2.37.md\n            RELEASE-1.2.36.md'
if old not in text:
    raise SystemExit('file00-three-plan-qa.yml: artifact release marker missing')
text = text.replace(old, new, 1)
write(qa_plan, text)

# Static regression assertions for the boolean contract.
mfa_contract = 'qa/mfa-retirement-contract.mjs'
text = read(mfa_contract)
marker = "console.log('mfa-retirement-contract: PASS');"
addition = """has(contracts, "if ( ! $user || smc_privacy_erasure_lock( $user_id ) )", 'missing user and privacy erasure remain role-sync failures');
has(contracts, "if ( user_can( $user, 'manage_options' ) ) {\\n\\t\\t\\treturn true;", 'Administrator protected role-sync no-op is successful');
lacks(contracts, "smc_privacy_erasure_lock( $user_id ) || user_can( $user, 'manage_options' )", 'Administrator protection is not conflated with role-sync failure');

"""
if marker not in text:
    raise SystemExit('mfa-retirement-contract.mjs: PASS marker missing')
text = text.replace(marker, addition + marker, 1)
write(mfa_contract, text)

# Real WordPress/MariaDB exact-live regression: Administrator + draft File 00
# application, no role-grant checkpoint, old named indexes, DB marker 1.2.0.
mysql_workflow = '.github/workflows/mfa-retirement-wordpress-mysql.yml'
text = read(mysql_workflow)
old_step = """      - name: Reproduce and repair exact live legacy named-index transitions
        run: |
          set -euo pipefail
          php /tmp/wp-cli.phar db query \""""
new_step = """      - name: Reproduce exact live legacy indexes and Administrator role-backfill state
        run: |
          set -euo pipefail
          php /tmp/wp-cli.phar eval '
            global $wpdb;
            $admin = get_user_by("login", "founder");
            if ( ! $admin || ! user_can( $admin, "manage_options" ) ) { fwrite(STDERR,"Administrator fixture missing\\n"); exit(56); }
            if ( ! smc_application( $admin->ID ) ) { SMC_Contracts::register_account( $admin->ID ); }
            $app = smc_application( $admin->ID );
            if ( ! $app ) { fwrite(STDERR,"Administrator application fixture missing\\n"); exit(57); }
            if ( false === $wpdb->update( $wpdb->prefix."smc_applications", array("membership_type"=>"member","status"=>"draft","row_version"=>4,"updated_at"=>current_time("mysql",true)), array("user_id"=>(int)$admin->ID) ) ) { fwrite(STDERR,"Administrator application fixture update failed\\n"); exit(58); }
            $roles = array_values( (array) get_userdata( $admin->ID )->roles ); sort($roles);
            update_option("smc_v1238_admin_roles_before", $roles, false);
            $state = smc_membership_state( $admin->ID );
            if ( empty($state["institutional_account"]) || empty($state["approved"]) || "verified" !== ($state["status"] ?? "") ) { fwrite(STDERR,"Administrator institutional precedence fixture failed\\n"); exit(59); }
            echo "PASS Administrator application fixture established without changing institutional precedence\\n";
          ' --path=/tmp/wordpress
          php /tmp/wp-cli.phar db query \""""
if old_step not in text:
    raise SystemExit('mfa-retirement-wordpress-mysql.yml: transition step marker missing')
text = text.replace(old_step, new_step, 1)

sql_marker = """            UPDATE wp_options SET option_value='1.2.5' WHERE option_name='smc_release_version';
          \" --path=/tmp/wordpress"""
sql_replacement = """            UPDATE wp_options SET option_value='1.2.5' WHERE option_name='smc_release_version';
            DELETE FROM wp_smc_migrations WHERE migration_key='role-grants-to-1.3.0';
            DELETE rg FROM wp_smc_role_grants rg INNER JOIN wp_users u ON u.ID=rg.user_id WHERE u.user_login='founder';
          \" --path=/tmp/wordpress"""
if sql_marker not in text:
    raise SystemExit('mfa-retirement-wordpress-mysql.yml: SQL reset marker missing')
text = text.replace(sql_marker, sql_replacement, 1)

verify_marker = """            SMC_Schema_Compat::assert_current_queue_indexes();
            $current_queue = $wpdb->get_col("""
verify_add = """            SMC_Schema_Compat::assert_current_queue_indexes();
            $admin = get_user_by("login", "founder");
            $checkpoint = $wpdb->get_row($wpdb->prepare("SELECT status,cursor_value FROM {$wpdb->prefix}smc_migrations WHERE migration_key=%s", "role-grants-to-1.3.0"), ARRAY_A);
            if ( ! $checkpoint || "complete" !== ($checkpoint["status"] ?? "") ) { fwrite(STDERR,"Administrator role-grant checkpoint did not complete\\n"); exit(60); }
            $before = (array) get_option("smc_v1238_admin_roles_before", array()); sort($before);
            $after = array_values((array)get_userdata($admin->ID)->roles); sort($after);
            if ( $before !== $after || ! in_array("administrator", $after, true) ) { fwrite(STDERR,"Administrator native roles were mutated by File 00 backfill\\n"); exit(61); }
            $grant = $wpdb->get_row($wpdb->prepare("SELECT membership_type,status FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d AND membership_type=%s", $admin->ID, "member"), ARRAY_A);
            if ( ! $grant || "pending" !== ($grant["status"] ?? "") ) { fwrite(STDERR,"Administrator canonical membership grant was not backfilled\\n"); exit(62); }
            $state = smc_membership_state($admin->ID);
            if ( empty($state["institutional_account"]) || empty($state["approved"]) || "verified" !== ($state["status"] ?? "") ) { fwrite(STDERR,"Legacy application overrode Administrator institutional identity\\n"); exit(63); }
            delete_option("smc_v1238_admin_roles_before");
            $current_queue = $wpdb->get_col("""
if verify_marker not in text:
    raise SystemExit('mfa-retirement-wordpress-mysql.yml: verify marker missing')
text = text.replace(verify_marker, verify_add, 1)
text = text.replace(
    'echo "PASS exact live legacy queue + decision transitions repaired and migration promoted\\n";',
    'echo "PASS exact live legacy queue + decision transitions and Administrator role backfill repaired without native-role mutation\\n";',
    1,
)
write(mysql_workflow, text)

# README current release only; 1.2.37 changelog stays immutable history.
readme = 'source/sabri-membership-core/README.txt'
text = read(readme)
if 'Stable tag: 1.2.37' not in text:
    raise SystemExit('README stable tag marker missing')
text = text.replace('Stable tag: 1.2.37', 'Stable tag: 1.2.38', 1)
marker = '== Changelog ==\n\n= 1.2.37 ='
section = """== Changelog ==

= 1.2.38 =
* Repairs the live-proven role-grant migration failure triggered when a pre-existing File 00 application belongs to a WordPress Administrator/institutional account.
* Preserves the Administrator native WordPress role unchanged: `sync_wordpress_roles()` now treats the deliberate Administrator no-mutation boundary as a successful no-op rather than a failed write.
* Missing users and privacy-erasure locks remain genuine fail-closed synchronization failures; ordinary member role reconciliation is unchanged.
* Extends the real WordPress 7.0.1 + MariaDB 11.4 regression fixture with an Administrator + draft membership application, forces the `role-grants-to-1.3.0` backfill to rerun, and proves DB promotion, checkpoint completion, canonical grant creation, institutional-identity precedence, and exact role-set preservation.
* Runtime 1.2.38; DB schema 1.4.4; public contract 1.2.2. Live resolution still requires deployment and post-deployment verification.

= 1.2.37 ="""
if marker not in text:
    raise SystemExit('README changelog marker missing')
text = text.replace(marker, section, 1)
write(readme, text)

# Master implementation index: advance current identity and append a new live
# evidence section without changing historical 1.2.37 evidence.
master = 'docs/FILE-00-MASTER-PLAN-2026.md'
text = read(master)
if '- Runtime implementation release: `1.2.37`' not in text:
    raise SystemExit('master plan current runtime marker missing')
text = text.replace('- Runtime implementation release: `1.2.37`', '- Runtime implementation release: `1.2.38`', 1)
marker = '## Current evidence\n'
section = """## Live-proven institutional Administrator role-backfill correction — 1.2.38

After `1.2.37` was deployed, live Hostinger evidence proved that the named-index compatibility transitions were crossed but the database still remained at `1.2.0` because migration advanced to `Role-grant backfill failed.` The live Founder account (`user_id=1`) had a surviving File 00 application (`membership_type=member`, `status=draft`) while its native WordPress account retained Administrator authority. No `role-grants-to-1.3.0` checkpoint was created, proving failure occurred inside the first backfill item before checkpoint persistence.

Exact v1.2.37 source showed that `SMC_Contracts::sync_wordpress_roles()` deliberately returned `false` for every `manage_options` user to protect Administrator roles from File 00 membership-role mutation. `SMC_Installer::backfill_role_grants()` interpreted that same `false` as a failed write and aborted. The protection itself was correct; the boolean contract was not.

Release `1.2.38` preserves the institutional boundary and changes only its success semantics: a valid Administrator is a protected successful no-op for WordPress role synchronization, while a missing user or privacy-erasure lock still returns failure. The canonical File 00 role grant can therefore be backfilled without adding/removing native Administrator roles. The real WordPress 7.0.1 + MariaDB 11.4 gate now recreates the Administrator + legacy application condition, removes the role-grant checkpoint, reruns migration, and requires DB `1.4.4`, a complete role-grant checkpoint, the expected pending canonical grant, unchanged Administrator roles, preserved institutional identity precedence, current named indexes, and downstream tables.

"""
if marker not in text:
    raise SystemExit('master plan Current evidence marker missing')
text = text.replace(marker, section + marker, 1)
write(master, text)

# Truthful release record.
release = ROOT / 'RELEASE-1.2.38.md'
if release.exists():
    raise SystemExit('RELEASE-1.2.38.md already exists unexpectedly')
release.write_text("""# File 00 — Sabri Membership Core 1.2.38

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
""", encoding='utf-8')

# Self-remove all temporary mutation machinery before the candidate commit.
for path in [
    ROOT / '.github/workflows/_temporary-file00-v1238-authorized-fix.yml',
    ROOT / 'tools/_temporary_apply_v1238.py',
]:
    if not path.exists():
        raise SystemExit(f'temporary file missing before self-removal: {path}')
    path.unlink()

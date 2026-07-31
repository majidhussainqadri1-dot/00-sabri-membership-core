<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Identity evidence is intentionally not deleted merely because plugin code is
 * removed. Administrators must first complete the WordPress privacy-erasure
 * workflow and verify all deferred file jobs. This prevents an uninstall from
 * destroying recoverable evidence or falsely reporting privacy completion.
 */
if ( ! defined( 'SMC_DELETE_CONFIGURATION_ON_UNINSTALL' ) || true !== SMC_DELETE_CONFIGURATION_ON_UNINSTALL ) {
	return;
}

delete_option( 'smc_page_map' );
delete_option( 'smc_release_version' );
delete_option( 'smc_last_migration_failure' );
delete_option( 'smc_schema_owner_lock' );

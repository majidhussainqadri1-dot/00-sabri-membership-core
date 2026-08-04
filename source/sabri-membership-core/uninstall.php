<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'smc_lifecycle_daily', 'smc_process_file_jobs', 'smc_process_event_outbox', 'smc_reconcile_applications', 'smc_continue_migration' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

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

delete_option( 'smc_safe_mode' );
delete_option( 'smc_operational_owners' );
delete_option( 'smc_service_levels' );
delete_option( 'smc_last_restore_test' );

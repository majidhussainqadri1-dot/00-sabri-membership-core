<?php
/**
 * Plugin Name: Sabri Membership Core
 * Plugin URI: https://github.com/majidhussainqadri1-dot/00-sabri-membership-core
 * Description: Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.
 * Version: 1.2.19
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-membership-core
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_VERSION', '1.2.19' );
define( 'SMC_DB_VERSION', '1.4.0' );
define( 'SMC_CONTRACT_VERSION', '1.2.1' );
define( 'SMC_CF01_CONTRACT_VERSION', '1.0.0' );
define( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0' );
define( 'SMC_FILE', __FILE__ );
define( 'SMC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMC_URL', plugin_dir_url( __FILE__ ) );

require_once SMC_PATH . 'includes/functions.php';
require_once SMC_PATH . 'includes/class-smc-installer.php';
require_once SMC_PATH . 'includes/class-smc-security.php';
require_once SMC_PATH . 'includes/class-smc-events.php';
require_once SMC_PATH . 'includes/class-smc-completion.php';
require_once SMC_PATH . 'includes/class-smc-contracts.php';
require_once SMC_PATH . 'includes/class-smc-cf01-contract.php';
require_once SMC_PATH . 'includes/class-smc-authorization.php';
require_once SMC_PATH . 'includes/class-smc-workflow.php';
require_once SMC_PATH . 'includes/class-smc-admin.php';
require_once SMC_PATH . 'includes/class-smc-privacy.php';
require_once SMC_PATH . 'includes/class-smc-lifecycle.php';
require_once SMC_PATH . 'includes/class-smc-three-plan.php';
require_once SMC_PATH . 'includes/class-smc-latest-central-2026.php';
require_once SMC_PATH . 'includes/class-smc-advanced-trust-2026.php';

/**
 * Activation entry point for existing installations whose encryption keyring
 * is not configured yet. Schema/role/page upgrades may be installed safely,
 * but legacy identity migration must wait for the keyring so activation can
 * remain fail-closed instead of crashing the WordPress plugin screen.
 */
function smc_activation_entrypoint() {
	$existing_db_version = (string) get_option( 'smc_db_version', '' );
	$existing_release    = (string) get_option( 'smc_release_version', '' );
	$defer_legacy        = '' !== $existing_db_version && ! SMC_Security::key_ready();

	if ( $defer_legacy ) {
		delete_option( 'smc_db_version' );
		delete_option( 'smc_release_version' );
	}

	try {
		SMC_Installer::activate();
	} finally {
		if ( $defer_legacy ) {
			update_option( 'smc_db_version', $existing_db_version, false );
			if ( '' !== $existing_release ) {
				update_option( 'smc_release_version', $existing_release, false );
			} else {
				delete_option( 'smc_release_version' );
			}
			update_option(
				'smc_migration_deferred_v1',
				array(
					'reason'             => 'key_configuration_required',
					'source_db_version'  => $existing_db_version,
					'target_db_version'  => SMC_DB_VERSION,
					'target_release'     => SMC_VERSION,
					'updated_at'         => current_time( 'mysql', true ),
				),
				false
			);
		}
	}
}

register_activation_hook( SMC_FILE, 'smc_activation_entrypoint' );
register_deactivation_hook( SMC_FILE, array( 'SMC_Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'sabri-membership-core', false, dirname( plugin_basename( SMC_FILE ) ) . '/languages' );
		SMC_Security::init();
		SMC_Events::init();
		SMC_Completion::init();
		SMC_Contracts::init();
		SMC_CF01_Contract::init();
		SMC_Authorization::init();
		SMC_Workflow::init();
		SMC_Admin::init();
		SMC_Privacy::init();
		SMC_Lifecycle::init();
		SMC_Three_Plan::init();
		SMC_Latest_Central_2026::init();
		SMC_Advanced_Trust_2026::init();
	},
	20
);

add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$deferred = get_option( 'smc_migration_deferred_v1', array() );
		if ( SMC_Security::key_ready() ) {
			SMC_Installer::maybe_upgrade();
			if ( SMC_DB_VERSION === get_option( 'smc_db_version', '' ) ) {
				delete_option( 'smc_migration_deferred_v1' );
			}
		} elseif ( is_array( $deferred ) && ! empty( $deferred ) ) {
			// Keep the plugin active but fail-closed. The data migration resumes
			// automatically after SMC_MASTER_KEY and SMC_MASTER_KEY_ID are ready.
		} elseif ( SMC_DB_VERSION !== get_option( 'smc_db_version', '' ) && '' !== get_option( 'smc_db_version', '' ) ) {
			update_option(
				'smc_migration_deferred_v1',
				array(
					'reason'            => 'key_configuration_required',
					'source_db_version' => (string) get_option( 'smc_db_version', '' ),
					'target_db_version' => SMC_DB_VERSION,
					'target_release'    => SMC_VERSION,
					'updated_at'        => current_time( 'mysql', true ),
				),
				false
			);
		}
		if ( SMC_Security::key_ready() && SMC_VERSION !== get_option( 'smc_institutional_repair_version', '' ) ) {
			$repaired = SMC_Lifecycle::repair_institutional_accounts();
			if ( SMC_Lifecycle::institutional_repair_complete() ) {
				update_option( 'smc_institutional_repair_version', SMC_VERSION, false );
				update_option( 'smc_release_version', SMC_VERSION, false );
				if ( $repaired > 0 ) {
					set_transient( 'smc_institutional_repair_notice', (string) $repaired, 300 );
				}
			} else {
				update_option( 'smc_last_migration_failure', array( 'message' => __( 'Institutional lifecycle repair remains incomplete and will retry.', 'sabri-membership-core' ), 'updated_at' => current_time( 'mysql', true ) ), false );
			}
		}
	}
);
add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$deferred = get_option( 'smc_migration_deferred_v1', array() );
		if ( is_array( $deferred ) && ! empty( $deferred ) && ! SMC_Security::key_ready() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Sabri Membership Core is active in fail-closed mode. Legacy membership migration is paused until both SMC_MASTER_KEY and SMC_MASTER_KEY_ID are securely configured; migration will then resume automatically.', 'sabri-membership-core' ) . '</p></div>';
		}
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! smc_is_membership_page() ) {
			return;
		}
		wp_enqueue_style( 'smc-membership', SMC_URL . 'assets/membership.css', array(), SMC_VERSION );
		wp_style_add_data( 'smc-membership', 'rtl', 'replace' );
		wp_enqueue_script( 'smc-membership', SMC_URL . 'assets/membership.js', array(), SMC_VERSION, true );
		$policy = smc_policy();
		wp_localize_script(
			'smc-membership',
			'smcPolicy',
			array(
				'maleMinimumAge'   => (int) $policy['male_minimum_age'],
				'femaleMinimumAge' => (int) $policy['female_minimum_age'],
				'guardianAge'      => (int) $policy['guardian_required_under'],
				'professionalAge'  => (int) $policy['professional_minimum_age'],
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'draftNonce'       => wp_create_nonce( 'smc_application_draft' ),
				'messages'         => array(
					'draftSaved'    => __( 'Draft saved securely.', 'sabri-membership-core' ),
					'draftFailed'   => __( 'Draft could not be saved. Your current form remains on this device until you leave the page.', 'sabri-membership-core' ),
					'uploading'     => __( 'Uploading authenticated evidence…', 'sabri-membership-core' ),
					'networkError'  => __( 'Network interrupted. Review the form and retry; the server remains authoritative.', 'sabri-membership-core' ),
				),
			)
		);
	}
);

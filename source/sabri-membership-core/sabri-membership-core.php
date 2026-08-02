<?php
/**
 * Plugin Name: Sabri Membership Core
 * Plugin URI: https://github.com/majidhussainqadri1-dot/00-sabri-membership-core
 * Description: Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.
 * Version: 1.2.4
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-membership-core
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_VERSION', '1.2.4' );
define( 'SMC_DB_VERSION', '1.2.0' );
define( 'SMC_CONTRACT_VERSION', '1.1.3' );
define( 'SMC_FILE', __FILE__ );
define( 'SMC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMC_URL', plugin_dir_url( __FILE__ ) );

require_once SMC_PATH . 'includes/functions.php';
require_once SMC_PATH . 'includes/class-smc-installer.php';
require_once SMC_PATH . 'includes/class-smc-security.php';
require_once SMC_PATH . 'includes/class-smc-contracts.php';
require_once SMC_PATH . 'includes/class-smc-authorization.php';
require_once SMC_PATH . 'includes/class-smc-workflow.php';
require_once SMC_PATH . 'includes/class-smc-admin.php';
require_once SMC_PATH . 'includes/class-smc-privacy.php';
require_once SMC_PATH . 'includes/class-smc-lifecycle.php';

register_activation_hook( SMC_FILE, array( 'SMC_Installer', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'sabri-membership-core', false, dirname( plugin_basename( SMC_FILE ) ) . '/languages' );
		SMC_Security::init();
		SMC_Contracts::init();
		SMC_Authorization::init();
		SMC_Workflow::init();
		SMC_Admin::init();
		SMC_Privacy::init();
		SMC_Lifecycle::init();
	},
	20
);

add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		SMC_Installer::maybe_upgrade();
		if ( SMC_VERSION !== get_option( 'smc_institutional_repair_version', '' ) ) {
			$repaired = SMC_Lifecycle::repair_institutional_accounts();
			update_option( 'smc_institutional_repair_version', SMC_VERSION, false );
			update_option( 'smc_release_version', SMC_VERSION, false );
			if ( $repaired > 0 ) {
				set_transient( 'smc_institutional_repair_notice', (string) $repaired, 300 );
			}
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
			)
		);
	}
);

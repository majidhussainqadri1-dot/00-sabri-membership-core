<?php
/**
 * Plugin Name: Sabri Membership Core
 * Plugin URI: https://sabrihomeopathy.com/
 * Description: Canonical membership, identity assurance, guardian consent, roles, verification and security assertions for the Sabri Social Homeopathy Platform.
 * Version: 1.2.12
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-membership-core
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_VERSION', '1.2.12' );
define( 'SMC_DB_VERSION', '1.3.0' );
define( 'SMC_CONTRACT_VERSION', '1.2.0' );
define( 'SMC_CF01_CONTRACT_VERSION', '1.0.0' );
define( 'SMC_AUTHENTICATION_CONTRACT_VERSION', '1.0.0' );
define( 'SMC_FILE', __FILE__ );
define( 'SMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMC_URL', plugin_dir_url( __FILE__ ) );

require_once SMC_DIR . 'includes/functions.php';
require_once SMC_DIR . 'includes/class-smc-security.php';
require_once SMC_DIR . 'includes/class-smc-completion.php';
require_once SMC_DIR . 'includes/class-smc-contracts.php';
require_once SMC_DIR . 'includes/class-smc-cf01-contract.php';
require_once SMC_DIR . 'includes/class-smc-authentication-contract.php';
require_once SMC_DIR . 'includes/class-smc-private-files.php';
require_once SMC_DIR . 'includes/class-smc-installer.php';
require_once SMC_DIR . 'includes/class-smc-registration.php';
require_once SMC_DIR . 'includes/class-smc-profile.php';
require_once SMC_DIR . 'includes/class-smc-workflow.php';
require_once SMC_DIR . 'includes/class-smc-admin.php';
require_once SMC_DIR . 'includes/class-smc-privacy.php';
require_once SMC_DIR . 'includes/class-smc-donations.php';

register_activation_hook( SMC_FILE, array( 'SMC_Installer', 'activate' ) );
register_deactivation_hook( SMC_FILE, array( 'SMC_Installer', 'deactivate' ) );

function smc_bootstrap() {
	SMC_Security::init();
	SMC_Completion::init();
	SMC_Contracts::init();
	SMC_CF01_Contract::init();
	SMC_Authentication_Contract::init();
	SMC_Private_Files::init();
	SMC_Installer::maybe_upgrade();
	SMC_Registration::init();
	SMC_Profile::init();
	SMC_Workflow::init();
	SMC_Admin::init();
	SMC_Privacy::init();
	SMC_Donations::init();
}
add_action( 'plugins_loaded', 'smc_bootstrap', 20 );

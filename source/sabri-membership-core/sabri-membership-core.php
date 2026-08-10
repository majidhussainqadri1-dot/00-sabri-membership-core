<?php
/**
 * Plugin Name: Sabri Membership Core
 * Plugin URI: https://github.com/majidhussainqadri1-dot/00-sabri-membership-core
 * Description: Canonical membership eligibility, identity assurance, guardian consent, security assertions, and verification governance for the Sabri Social Homeopathy Platform.
 * Version: 1.2.39
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed
 * License: GPL-2.0-or-later
 * Text Domain: sabri-membership-core
 */

defined( 'ABSPATH' ) || exit;

define( 'SMC_VERSION', '1.2.39' );
define( 'SMC_DB_VERSION', '1.4.4' );
define( 'SMC_CONTRACT_VERSION', '1.2.2' );
define( 'SMC_CF01_CONTRACT_VERSION', '1.1.0' );
define( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0' );
define( 'SMC_FILE', __FILE__ );
define( 'SMC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMC_URL', plugin_dir_url( __FILE__ ) );

require_once SMC_PATH . 'includes/functions.php';
require_once SMC_PATH . 'includes/class-smc-installer.php';
require_once SMC_PATH . 'includes/class-smc-schema-compat.php';
require_once SMC_PATH . 'includes/class-smc-host-compat.php';
require_once SMC_PATH . 'includes/class-smc-security.php';
require_once SMC_PATH . 'includes/class-smc-events.php';
require_once SMC_PATH . 'includes/class-smc-completion.php';
require_once SMC_PATH . 'includes/class-smc-contracts.php';
require_once SMC_PATH . 'includes/class-smc-cf01-contract.php';
require_once SMC_PATH . 'includes/class-smc-authorization.php';
require_once SMC_PATH . 'includes/class-smc-workflow.php';
require_once SMC_PATH . 'includes/class-smc-mfa-retirement.php';
require_once SMC_PATH . 'includes/class-smc-contact-delivery.php';
require_once SMC_PATH . 'includes/class-smc-admin.php';
require_once SMC_PATH . 'includes/class-smc-privacy.php';
require_once SMC_PATH . 'includes/class-smc-lifecycle.php';
require_once SMC_PATH . 'includes/class-smc-three-plan.php';
require_once SMC_PATH . 'includes/class-smc-latest-central-2026.php';
require_once SMC_PATH . 'includes/class-smc-advanced-trust-2026.php';

/**
 * Minimal activation entry point.
 *
 * WordPress displays only a generic fatal-error message when an activation
 * hook throws. File 00 therefore performs no schema migration, advisory lock,
 * encryption migration, role mutation, page creation, or rewrite flush in the
 * activation request itself. Those operations are deferred to admin_init and
 * are wrapped in a fail-closed diagnostic boundary. This keeps the plugin
 * activatable on heterogeneous hosts while preserving migration safety.
 */
function smc_activation_entrypoint() {
	$now = current_time( 'mysql', true );
	update_option(
		'smc_activation_pending_v2',
		array(
			'target_release'    => SMC_VERSION,
			'target_db_version' => SMC_DB_VERSION,
			'requested_at'      => $now,
		),
		false
	);
	update_option(
		'smc_activation_bootstrap_state_v2',
		array(
			'status'     => 'queued',
			'phase'      => 'activation',
			'message'    => 'Activation accepted; protected bootstrap is deferred to the next administrator request.',
			'updated_at' => $now,
		),
		false
	);
	set_transient( 'smc_activation_notice', '1', 180 );
}

register_activation_hook( SMC_FILE, 'smc_activation_entrypoint' );
register_deactivation_hook( SMC_FILE, array( 'SMC_Installer', 'deactivate' ) );

/** Record a bootstrap/runtime failure without allowing it to take down wp-admin. */
function smc_record_bootstrap_failure( $phase, Throwable $error ) {
	update_option(
		'smc_activation_bootstrap_state_v2',
		array(
			'status'      => 'failed_safe',
			'phase'       => sanitize_key( (string) $phase ),
			'error_class' => get_class( $error ),
			'message'     => sanitize_text_field( $error->getMessage() ),
			'updated_at'  => current_time( 'mysql', true ),
		),
		false
	);
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'sabri-membership-core', false, dirname( plugin_basename( SMC_FILE ) ) . '/languages' );
		$initializers = array(
			array( 'SMC_Security', 'init' ), array( 'SMC_Events', 'init' ), array( 'SMC_Completion', 'init' ),
			array( 'SMC_Contracts', 'init' ), array( 'SMC_CF01_Contract', 'init' ), array( 'SMC_Authorization', 'init' ),
			array( 'SMC_Workflow', 'init' ), array( 'SMC_MFA_Retirement', 'init' ), array( 'SMC_Host_Compat', 'init' ),
			array( 'SMC_Contact_Delivery', 'init' ), array( 'SMC_Admin', 'init' ), array( 'SMC_Privacy', 'init' ),
			array( 'SMC_Lifecycle', 'init' ), array( 'SMC_Three_Plan', 'init' ), array( 'SMC_Latest_Central_2026', 'init' ),
			array( 'SMC_Advanced_Trust_2026', 'init' ),
		);
		foreach ( $initializers as $initializer ) {
			try { call_user_func( $initializer ); }
			catch ( Throwable $error ) { smc_record_bootstrap_failure( strtolower( $initializer[0] . '_' . $initializer[1] ), $error ); break; }
		}
	},
	20
);

add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		try {
			$key_ready = SMC_Security::ensure_key_ready();
			if ( is_wp_error( $key_ready ) ) { throw new RuntimeException( $key_ready->get_error_message() ); }
			$audit_ready = SMC_Installer::ensure_audit_infrastructure();
			if ( is_wp_error( $audit_ready ) ) { throw new RuntimeException( $audit_ready->get_error_code() . ': ' . $audit_ready->get_error_message() ); }
			SMC_Schema_Compat::reconcile_verification_queue_index();
			SMC_Installer::maybe_upgrade();
			if ( SMC_DB_VERSION === (string) get_option( 'smc_db_version', '' ) ) { SMC_Schema_Compat::assert_current_queue_indexes(); }
		} catch ( Throwable $error ) {
			smc_record_bootstrap_failure( 'deferred_upgrade', $error );
			return;
		}

		$current_db = (string) get_option( 'smc_db_version', '' );
		$deferred   = get_option( 'smc_migration_deferred_v1', array() );
		$failure    = get_option( 'smc_last_migration_failure', array() );
		if ( SMC_DB_VERSION !== $current_db ) {
			$message = 'Protected bootstrap is deferred until its prerequisites are available.';
			if ( is_array( $deferred ) && 'key_configuration_required' === (string) ( $deferred['reason'] ?? '' ) ) {
				$message = 'Encryption key configuration is required before the protected legacy migration can resume.';
			} elseif ( is_array( $failure ) && ! empty( $failure['message'] ) ) {
				$message = sanitize_text_field( (string) $failure['message'] );
			}
			update_option( 'smc_activation_bootstrap_state_v2', array( 'status' => 'deferred_safe', 'phase' => 'deferred_upgrade', 'message' => $message, 'updated_at' => current_time( 'mysql', true ) ), false );
			return;
		}

		delete_option( 'smc_activation_pending_v2' );
		delete_option( 'smc_migration_deferred_v1' );
		$completed_failure = get_option( 'smc_last_migration_failure', array() );
		if ( is_array( $completed_failure ) && SMC_Schema_Compat::ORPHAN_BACKFILL_FAILURE === (string) ( $completed_failure['message'] ?? '' ) ) {
			delete_option( 'smc_last_migration_failure' );
		}
		update_option( 'smc_activation_bootstrap_state_v2', array( 'status' => 'ready', 'phase' => 'deferred_upgrade', 'message' => 'Protected bootstrap completed.', 'updated_at' => current_time( 'mysql', true ) ), false );

		if ( SMC_Security::key_ready() && SMC_VERSION !== get_option( 'smc_institutional_repair_version', '' ) ) {
			try {
				$repaired = SMC_Lifecycle::repair_institutional_accounts();
				if ( SMC_Lifecycle::institutional_repair_complete() ) {
					update_option( 'smc_institutional_repair_version', SMC_VERSION, false );
					update_option( 'smc_release_version', SMC_VERSION, false );
					if ( $repaired > 0 ) { set_transient( 'smc_institutional_repair_notice', (string) $repaired, 300 ); }
				} else {
					update_option( 'smc_last_migration_failure', array( 'message' => __( 'Institutional lifecycle repair remains incomplete and will retry.', 'sabri-membership-core' ), 'updated_at' => current_time( 'mysql', true ) ), false );
				}
			} catch ( Throwable $error ) { smc_record_bootstrap_failure( 'institutional_repair', $error ); }
		}
	}
);

add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$state = get_option( 'smc_activation_bootstrap_state_v2', array() );
		if ( ! is_array( $state ) || empty( $state['status'] ) || 'ready' === $state['status'] ) { return; }
		$status  = sanitize_key( (string) $state['status'] );
		$phase   = sanitize_text_field( (string) ( $state['phase'] ?? 'bootstrap' ) );
		$message = sanitize_text_field( (string) ( $state['message'] ?? 'Protected bootstrap is pending.' ) );
		$class   = 'failed_safe' === $status ? 'notice notice-error' : 'notice notice-warning';
		echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html__( 'Sabri Membership Core bootstrap status:', 'sabri-membership-core' ) . '</strong> ' . esc_html( $status . ' / ' . $phase . ' — ' . $message ) . '</p></div>';
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! smc_is_membership_page() ) { return; }
		$css_path = SMC_PATH . 'assets/membership.css';
		$js_path  = SMC_PATH . 'assets/membership.js';
		$css_hash = is_readable( $css_path ) ? substr( hash_file( 'sha256', $css_path ), 0, 12 ) : 'base';
		$js_hash  = is_readable( $js_path ) ? substr( hash_file( 'sha256', $js_path ), 0, 12 ) : 'base';
		wp_enqueue_style( 'smc-membership', SMC_URL . 'assets/membership.css', array(), SMC_VERSION . '-' . $css_hash );
		wp_enqueue_script( 'smc-membership', SMC_URL . 'assets/membership.js', array(), SMC_VERSION . '-' . $js_hash, true );
		$policy = smc_policy();
		wp_localize_script( 'smc-membership', 'smcPolicy', array(
			'maleMinimumAge' => (int) $policy['male_minimum_age'], 'femaleMinimumAge' => (int) $policy['female_minimum_age'],
			'guardianAge' => (int) $policy['guardian_required_under'], 'professionalAge' => (int) $policy['professional_minimum_age'],
			'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'draftNonce' => wp_create_nonce( 'smc_application_draft' ),
			'messages' => array(
				'draftSaved' => __( 'Draft saved securely.', 'sabri-membership-core' ),
				'draftFailed' => __( 'Draft could not be saved. Your current form remains on this device until you leave the page.', 'sabri-membership-core' ),
				'uploading' => __( 'Uploading authenticated evidence…', 'sabri-membership-core' ),
				'networkError' => __( 'Network interrupted. Review the form and retry; the server remains authoritative.', 'sabri-membership-core' ),
			),
		) );
	}
);

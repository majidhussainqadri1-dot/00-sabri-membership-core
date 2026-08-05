<?php
defined( 'ABSPATH' ) || exit;

/**
 * Three-plan harmonization layer for the 5 August 2026 recovered directives.
 * It owns no duplicate membership data; it configures the institutional AI
 * identity, its least-privilege roles, and the associated governance surface.
 */
final class SMC_Three_Plan {
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'reconcile_roles' ), 40 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_smc_save_institutional_ai', array( __CLASS__, 'save_institutional_ai' ) );
	}

	public static function reconcile_roles() {
		self::reconcile_role(
			'sabri_institutional_ai_teacher',
			__( 'Institutional AI Teacher', 'sabri-membership-core' ),
			array( 'read' => true, 'smc_ai_generate_educational_content' => true, 'smc_ai_submit_educational_content' => true )
		);
		self::reconcile_role(
			'sabri_institutional_ai_publisher',
			__( 'Institutional AI Publisher', 'sabri-membership-core' ),
			array( 'read' => true, 'smc_ai_submit_educational_content' => true )
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'smc_configure_institutional_ai' );
		}
	}

	private static function reconcile_role( $slug, $label, $caps ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			$role = add_role( $slug, $label, $caps );
		}
		if ( ! $role ) {
			return false;
		}
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! isset( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( $caps as $cap => $grant ) {
			$grant ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
		}
		return true;
	}

	public static function menu() {
		add_submenu_page(
			'smc-membership',
			__( 'Institutional AI Identity', 'sabri-membership-core' ),
			__( 'Institutional AI', 'sabri-membership-core' ),
			'manage_options',
			'smc-institutional-ai',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$id = smc_institutional_ai_user_id();
		$policy = smc_institutional_ai_policy();
		echo '<div class="wrap"><h1>' . esc_html__( 'Institutional AI Teacher / Publisher', 'sabri-membership-core' ) . '</h1>';
		echo '<p><strong>' . esc_html( $policy['public_name'] ) . '</strong> — ' . esc_html( $policy['provider_disclosure'] ) . '</p>';
		echo '<p>' . esc_html__( 'This identity is never presented as a human or verified doctor. It has no diagnosis, prescription, dosage, emergency or patient-specific clinical authority. The first 30 days require human review; later low-risk auto-publication remains an explicit Founder-controlled policy.', 'sabri-membership-core' ) . '</p>';
		echo '<p>' . esc_html__( 'The current financial constitution is one free baseline: registration, membership, education, AI, clinic and marketplace access are not unlocked by payment. Donation remains optional and non-privileging.', 'sabri-membership-core' ) . '</p>';
		if ( defined( 'SMC_INSTITUTIONAL_AI_USER_ID' ) ) {
			echo '<p><code>SMC_INSTITUTIONAL_AI_USER_ID=' . absint( SMC_INSTITUTIONAL_AI_USER_ID ) . '</code></p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="smc_save_institutional_ai">';
		wp_nonce_field( 'smc_save_institutional_ai', 'smc_nonce' );
		echo '<p><label>' . esc_html__( 'Exact WordPress user ID', 'sabri-membership-core' ) . ' <input type="number" name="ai_user_id" value="' . absint( $id ) . '" min="1" required></label></p>';
		echo '<p><label><input type="checkbox" name="low_risk_auto_publish" value="1" ' . checked( ! empty( $policy['low_risk_auto_publish'] ), true, false ) . '> ' . esc_html__( 'After the 30-day review period, allow only downstream-policy-approved low-risk educational auto-publication.', 'sabri-membership-core' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I confirm this is a distinct institutional AI account with mandatory AI/provider disclosure, no doctor claim and no clinical authority.', 'sabri-membership-core' ) . '</label></p>';
		echo '<button class="button button-primary">' . esc_html__( 'Save Institutional AI Identity', 'sabri-membership-core' ) . '</button></form></div>';
	}

	public static function save_institutional_ai() {
		if ( ! current_user_can( 'manage_options' ) || defined( 'SMC_INSTITUTIONAL_AI_USER_ID' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'smc_save_institutional_ai', 'smc_nonce' );
		$id = absint( $_POST['ai_user_id'] ?? 0 );
		$user = get_userdata( $id );
		if ( ! $user || empty( $_POST['confirm'] ) || smc_is_founder( $id ) || user_can( $user, 'manage_options' ) ) {
			wp_die( esc_html__( 'Select a distinct non-administrator account and confirm the institutional AI safeguards.', 'sabri-membership-core' ), '', array( 'response' => 400 ) );
		}
		$previous = smc_institutional_ai_user_id();
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		update_option( 'smc_institutional_ai_user_id', $id, false );
		if ( ! get_option( 'smc_institutional_ai_activated_at', '' ) || $previous !== $id ) {
			update_option( 'smc_institutional_ai_activated_at', current_time( 'mysql', true ), false );
		}
		update_option( 'smc_institutional_ai_low_risk_auto_publish', ! empty( $_POST['low_risk_auto_publish'] ), false );
		if ( $previous && $previous !== $id ) {
			$old = get_userdata( $previous );
			if ( $old ) {
				$old->remove_role( 'sabri_institutional_ai_teacher' );
				$old->remove_role( 'sabri_institutional_ai_publisher' );
			}
		}
		$user->add_role( 'sabri_institutional_ai_teacher' );
		$user->add_role( 'sabri_institutional_ai_publisher' );
		$stored = smc_institutional_ai_user_id();
		$audit_ok = $stored === $id && SMC_Security::audit(
			'institutional_ai_account_configured',
			$id,
			array( 'configured_by' => get_current_user_id(), 'doctor_claim' => false, 'clinical_authority' => false, 'policy_version' => 'CHAT-AI-001-v2.1' )
		);
		if ( $stored !== $id || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Institutional AI identity could not be committed with audit evidence.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		clean_user_cache( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=smc-institutional-ai&updated=1' ) );
		exit;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Governed lost-factor recovery for File 00.
 *
 * This is deliberately not a support bypass. The subject must reauthenticate
 * with the current WordPress credential, the recovery case is durable and
 * audited, privileged accounts require two distinct MFA-verified approvers,
 * every case observes a cooling period, existing sessions are revoked before
 * factor reset, and old-factor material is removed only after the case is
 * eligible for completion.
 */
final class SMC_Account_Recovery {
	const REPAIR_TYPE      = 'lost_factor_recovery';
	const EVIDENCE_VERSION = 'lost-factor-v1';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_shortcode( 'smc_membership_recovery', array( __CLASS__, 'shortcode' ) );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'append_security_recovery_link' ), 20, 4 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_page' ), 30 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );

		add_action( 'admin_post_smc_account_recovery_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_smc_account_recovery_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_smc_account_recovery_complete', array( __CLASS__, 'handle_complete' ) );
		add_action( 'admin_post_smc_account_recovery_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_smc_account_recovery_reject', array( __CLASS__, 'handle_reject' ) );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'smc_application_repairs';
	}

	private static function table_ready() {
		global $wpdb;
		$table = self::table_name();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function audit_ready() {
		if ( ! class_exists( 'SMC_Installer' ) || ! is_callable( array( 'SMC_Installer', 'audit_infrastructure_ready' ) ) ) {
			return false;
		}
		return true === SMC_Installer::audit_infrastructure_ready();
	}

	private static function now_mysql() {
		return current_time( 'mysql', true );
	}

	private static function recovery_url( $args = array() ) {
		$url = smc_page_url( 'recovery', '/membership-recovery/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	private static function redirect( $message, $args = array() ) {
		$args['smc_recovery_message'] = sanitize_key( (string) $message );
		wp_safe_redirect( self::recovery_url( $args ) );
		exit;
	}

	private static function admin_redirect( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'smc-account-recovery',
					'smc_recovery_message' => sanitize_key( (string) $message ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	private static function message() {
		$key = isset( $_GET['smc_recovery_message'] ) ? sanitize_key( wp_unslash( $_GET['smc_recovery_message'] ) ) : '';
		$messages = array(
			'requested'      => array( __( 'A governed lost-factor recovery case was opened. It cannot bypass identity assurance or the cooling period.', 'sabri-membership-core' ), 'success' ),
			'cancelled'      => array( __( 'The recovery case was cancelled. No factor state was changed.', 'sabri-membership-core' ), 'success' ),
			'approved'       => array( __( 'The recovery case has the required approval evidence. Completion remains subject to the cooling period and final password reauthentication.', 'sabri-membership-core' ), 'success' ),
			'approval_saved' => array( __( 'The independent recovery approval was recorded.', 'sabri-membership-core' ), 'success' ),
			'rejected'       => array( __( 'The recovery case was rejected without changing authentication factors.', 'sabri-membership-core' ), 'warning' ),
			'not_ready'      => array( __( 'The recovery case is not yet eligible for factor reset.', 'sabri-membership-core' ), 'warning' ),
			'retry_login'    => array( __( 'Existing sessions were revoked for safety, but the final factor-reset transaction did not complete. Sign in again and retry the approved case.', 'sabri-membership-core' ), 'warning' ),
			'unavailable'    => array( __( 'Governed recovery infrastructure is not ready. No authentication factor was changed.', 'sabri-membership-core' ), 'error' ),
			'invalid'        => array( __( 'The recovery request could not be verified. No authentication factor was changed.', 'sabri-membership-core' ), 'error' ),
			'rate'           => array( __( 'Too many recovery attempts were recorded. Wait before trying again.', 'sabri-membership-core' ), 'warning' ),
		);
		return isset( $messages[ $key ] ) ? smc_notice( $messages[ $key ][0], $messages[ $key ][1] ) : '';
	}

	private static function parse_details( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function encode_details( $details ) {
		return wp_json_encode( is_array( $details ) ? $details : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function load_case_by_id( $case_id, $for_update = false ) {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return false;
		}
		$table = self::table_name();
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND repair_type=%s LIMIT 1", absint( $case_id ), self::REPAIR_TYPE );
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( is_array( $row ) ) {
			$row['details_array'] = self::parse_details( $row['details'] ?? '' );
		}
		return $row;
	}

	private static function current_case( $user_id ) {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return false;
		}
		$table = self::table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id=%d AND repair_type=%s AND status IN ('requested','cooling','approved') ORDER BY id DESC LIMIT 1",
				absint( $user_id ),
				self::REPAIR_TYPE
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			$row['details_array'] = self::parse_details( $row['details'] ?? '' );
		}
		return $row;
	}

	private static function is_privileged_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( function_exists( 'smc_is_founder' ) && smc_is_founder( $user_id ) ) {
			return true;
		}
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user && user_can( $user, 'manage_options' );
	}

	private static function policy_for_user( $user_id ) {
		$privileged = self::is_privileged_user( $user_id );
		$default_seconds = $privileged ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		$default_approvals = $privileged ? 2 : 1;
		$seconds = absint( apply_filters( 'smc_account_recovery_cooling_seconds', $default_seconds, absint( $user_id ), $privileged ) );
		$approvals = absint( apply_filters( 'smc_account_recovery_required_approvals', $default_approvals, absint( $user_id ), $privileged ) );
		return array(
			'privileged'         => $privileged,
			'cooling_seconds'    => max( $privileged ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS, $seconds ),
			'required_approvals' => max( $privileged ? 2 : 1, $approvals ),
		);
	}

	private static function current_contact_hash( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new WP_Error( 'smc_recovery_contact', __( 'A current account email is required for recovery continuity.', 'sabri-membership-core' ) );
		}
		return SMC_Security::blind_index( strtolower( trim( $user->user_email ) ), 'account-recovery-old-contact' );
	}

	private static function contact_is_unchanged( $case ) {
		$details = self::parse_details( $case['details_array'] ?? ( $case['details'] ?? '' ) );
		$stored = isset( $details['old_contact_hash'] ) ? (string) $details['old_contact_hash'] : '';
		$current = self::current_contact_hash( absint( $case['user_id'] ?? 0 ) );
		return '' !== $stored && ! is_wp_error( $current ) && hash_equals( $stored, (string) $current );
	}

	private static function approvals( $details ) {
		$approvals = isset( $details['approvals'] ) && is_array( $details['approvals'] ) ? $details['approvals'] : array();
		$clean = array();
		foreach ( $approvals as $approval ) {
			if ( ! is_array( $approval ) || empty( $approval['actor_id'] ) || empty( $approval['reference_hash'] ) ) {
				continue;
			}
			$clean[] = $approval;
		}
		return $clean;
	}

	private static function approval_count( $details ) {
		$actors = array();
		foreach ( self::approvals( $details ) as $approval ) {
			$actors[ absint( $approval['actor_id'] ) ] = true;
		}
		return count( $actors );
	}

	private static function case_ready( $case, $now = null ) {
		if ( ! is_array( $case ) ) {
			return false;
		}
		$details = self::parse_details( $case['details_array'] ?? ( $case['details'] ?? '' ) );
		$required = max( 1, absint( $details['required_approvals'] ?? 1 ) );
		$ready_after = isset( $details['ready_after'] ) ? strtotime( (string) $details['ready_after'] . ' UTC' ) : false;
		$now = null === $now ? time() : absint( $now );
		return self::approval_count( $details ) >= $required && $ready_after && $ready_after <= $now && in_array( (string) ( $case['status'] ?? '' ), array( 'cooling', 'approved' ), true );
	}

	private static function can_approve() {
		$actor_id = get_current_user_id();
		if ( ! $actor_id || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return class_exists( 'SMC_Security' ) && SMC_Security::session_is_verified( $actor_id );
	}

	private static function guard_subject_action( $nonce_action ) {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		check_admin_referer( $nonce_action, 'smc_nonce' );
		if ( ! self::table_ready() || ! self::audit_ready() ) {
			self::redirect( 'unavailable' );
		}
	}

	private static function verify_password( $user_id, $password ) {
		$user = get_userdata( absint( $user_id ) );
		return $user && '' !== (string) $password && wp_check_password( (string) $password, $user->user_pass, absint( $user_id ) );
	}

	private static function emit_event( $event_type, $user_id, $trace_id, $reason_code ) {
		if ( ! class_exists( 'SMC_Events' ) ) {
			return false;
		}
		return SMC_Events::emit(
			$event_type,
			absint( $user_id ),
			array(
				'trace_id'    => sanitize_text_field( $trace_id ),
				'reason_code' => sanitize_key( $reason_code ),
			),
			'account-recovery|' . sanitize_key( $event_type ) . '|' . sanitize_text_field( $trace_id )
		);
	}

	private static function notify_best_effort( $user_id, $type, $trace_id ) {
		if ( ! function_exists( 'smc_notify' ) ) {
			return false;
		}
		$labels = array(
			'requested' => array( __( 'Account recovery requested', 'sabri-membership-core' ), __( 'A governed lost-factor recovery was requested for your account. If this was not you, contact the platform administrator immediately.', 'sabri-membership-core' ) ),
			'approved'  => array( __( 'Account recovery approved', 'sabri-membership-core' ), __( 'Required recovery approvals were recorded. The factor reset still requires the cooling period and final password reauthentication.', 'sabri-membership-core' ) ),
			'completed' => array( __( 'Authentication factors reset', 'sabri-membership-core' ), __( 'Existing sessions and old recovery factors were invalidated. Sign in again and enroll the new authenticator shown by Membership Security.', 'sabri-membership-core' ) ),
			'rejected'  => array( __( 'Account recovery rejected', 'sabri-membership-core' ), __( 'The governed lost-factor recovery was rejected. No authentication factor was changed.', 'sabri-membership-core' ) ),
			'cancelled' => array( __( 'Account recovery cancelled', 'sabri-membership-core' ), __( 'The governed lost-factor recovery was cancelled. No authentication factor was changed.', 'sabri-membership-core' ) ),
		);
		if ( ! isset( $labels[ $type ] ) ) {
			return false;
		}
		return smc_notify( absint( $user_id ), 'account_recovery_' . sanitize_key( $type ), $labels[ $type ][0], $labels[ $type ][1] . ' ' . sprintf( __( 'Reference: %s', 'sabri-membership-core' ), sanitize_text_field( $trace_id ) ), 'critical', self::recovery_url() );
	}

	public static function ensure_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$map = (array) get_option( 'smc_page_map', array() );
		$id = ! empty( $map['recovery'] ) ? absint( $map['recovery'] ) : 0;
		$definition = array(
			'post_title'   => 'Membership Recovery',
			'post_name'    => 'membership-recovery',
			'post_content' => '[smc_membership_recovery]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);
		if ( $id && 'page' === get_post_type( $id ) && '1' === get_post_meta( $id, '_smc_managed_page', true ) ) {
			$definition['ID'] = $id;
			$result = wp_update_post( $definition, true );
		} else {
			$result = wp_insert_post( $definition, true );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			return;
		}
		$id = absint( $result );
		update_post_meta( $id, '_smc_managed_page', '1' );
		$map['recovery'] = $id;
		if ( get_option( 'smc_page_map' ) !== $map ) {
			update_option( 'smc_page_map', $map, false );
		}
	}

	public static function append_security_recovery_link( $output, $tag, $attr, $match ) {
		unset( $attr, $match );
		if ( 'smc_membership_security' !== $tag || ! is_user_logged_in() ) {
			return $output;
		}
		$user_id = get_current_user_id();
		if ( ! class_exists( 'SMC_Security' ) || ! SMC_Security::two_factor_ready( $user_id ) ) {
			return $output;
		}
		$panel  = '<section class="smc-subpanel smc-account-recovery-entry">';
		$panel .= '<h2>' . esc_html__( 'Lost authenticator recovery', 'sabri-membership-core' ) . '</h2>';
		$panel .= '<p>' . esc_html__( 'If the authenticator and the saved recovery codes are both unavailable, use the governed recovery case. This path does not bypass identity assurance.', 'sabri-membership-core' ) . '</p>';
		$panel .= '<p><a class="smc-button smc-button--secondary" href="' . esc_url( self::recovery_url() ) . '">' . esc_html__( 'Open Governed Recovery', 'sabri-membership-core' ) . '</a></p>';
		$panel .= '</section>';
		return $output . $panel;
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return smc_notice( __( 'Sign in with the account credential before starting governed recovery.', 'sabri-membership-core' ), 'warning' );
		}
		$user_id = get_current_user_id();
		$case = self::current_case( $user_id );
		$infrastructure_ready = self::table_ready() && self::audit_ready();
		$factor_ready = class_exists( 'SMC_Security' ) && SMC_Security::two_factor_ready( $user_id );
		ob_start();
		?>
		<main class="smc-panel" aria-labelledby="smc-account-recovery-title">
			<?php echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h1 id="smc-account-recovery-title"><?php esc_html_e( 'Governed Account Recovery', 'sabri-membership-core' ); ?></h1>
			<p><?php esc_html_e( 'This path is only for a lost authenticator when usable recovery codes are also unavailable. Password reset remains owned by Sabri Authentication (File 02).', 'sabri-membership-core' ); ?></p>
			<?php if ( ! $infrastructure_ready ) : ?>
				<?php echo smc_notice( __( 'Recovery infrastructure is not ready because the required File 00 database/audit upgrade has not completed. No factor-reset action is available.', 'sabri-membership-core' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php elseif ( $case ) : ?>
				<?php self::render_case( $case ); ?>
			<?php elseif ( ! $factor_ready ) : ?>
				<?php echo smc_notice( __( 'No active File 00 authenticator factor requires lost-factor recovery. Use Membership Security to enroll or finish the pending authenticator setup.', 'sabri-membership-core' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><a class="smc-button" href="<?php echo esc_url( smc_page_url( 'security', '/membership-security/' ) ); ?>"><?php esc_html_e( 'Open Membership Security', 'sabri-membership-core' ); ?></a></p>
			<?php else : ?>
				<section class="smc-subpanel">
					<h2><?php esc_html_e( 'Request lost-factor recovery', 'sabri-membership-core' ); ?></h2>
					<p><?php esc_html_e( 'The request records an immutable audit trail, binds the current account contact, starts a cooling period, and requires independent approval evidence. Founder/Administrator accounts require two distinct approvers.', 'sabri-membership-core' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form">
						<input type="hidden" name="action" value="smc_account_recovery_request">
						<?php wp_nonce_field( 'smc_account_recovery_request', 'smc_nonce' ); ?>
						<label><?php esc_html_e( 'Current account password', 'sabri-membership-core' ); ?><input type="password" name="password" autocomplete="current-password" required></label>
						<label class="smc-check"><input type="checkbox" name="confirm_lost_factors" value="1" required> <?php esc_html_e( 'I confirm that my authenticator and usable recovery codes are unavailable.', 'sabri-membership-core' ); ?></label>
						<button class="smc-button"><?php esc_html_e( 'Open Recovery Case', 'sabri-membership-core' ); ?></button>
					</form>
				</section>
			<?php endif; ?>
		</main>
		<?php
		return ob_get_clean();
	}

	private static function render_case( $case ) {
		$details = self::parse_details( $case['details_array'] ?? ( $case['details'] ?? '' ) );
		$required = max( 1, absint( $details['required_approvals'] ?? 1 ) );
		$count = self::approval_count( $details );
		$ready = self::case_ready( $case );
		$ready_after = sanitize_text_field( (string) ( $details['ready_after'] ?? '' ) );
		$trace = sanitize_text_field( (string) ( $case['trace_id'] ?? '' ) );
		?>
		<section class="smc-subpanel">
			<h2><?php esc_html_e( 'Current recovery case', 'sabri-membership-core' ); ?></h2>
			<dl class="smc-status-grid">
				<div><dt><?php esc_html_e( 'Reference', 'sabri-membership-core' ); ?></dt><dd><code><?php echo esc_html( $trace ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'State', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( sanitize_key( (string) $case['status'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Independent approvals', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( $count . ' / ' . $required ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Cooling period ends', 'sabri-membership-core' ); ?></dt><dd><?php echo esc_html( $ready_after ?: '—' ); ?> UTC</dd></div>
				<div><dt><?php esc_html_e( 'Old contact continuity', 'sabri-membership-core' ); ?></dt><dd><?php echo self::contact_is_unchanged( $case ) ? esc_html__( 'Unchanged', 'sabri-membership-core' ) : esc_html__( 'Changed — completion blocked', 'sabri-membership-core' ); ?></dd></div>
			</dl>
			<?php if ( $ready && self::contact_is_unchanged( $case ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form">
					<input type="hidden" name="action" value="smc_account_recovery_complete">
					<input type="hidden" name="case_id" value="<?php echo absint( $case['id'] ); ?>">
					<?php wp_nonce_field( 'smc_account_recovery_complete_' . absint( $case['id'] ), 'smc_nonce' ); ?>
					<label><?php esc_html_e( 'Current account password', 'sabri-membership-core' ); ?><input type="password" name="password" autocomplete="current-password" required></label>
					<label class="smc-check"><input type="checkbox" name="confirm_reset" value="1" required> <?php esc_html_e( 'I understand that every existing session and old two-factor recovery code will be invalidated.', 'sabri-membership-core' ); ?></label>
					<button class="smc-button smc-button--danger"><?php esc_html_e( 'Reset Lost Factors and Sign Out Everywhere', 'sabri-membership-core' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'No factor reset can occur until the required independent approvals, cooling period and old-contact continuity checks all pass.', 'sabri-membership-core' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smc-form">
				<input type="hidden" name="action" value="smc_account_recovery_cancel">
				<input type="hidden" name="case_id" value="<?php echo absint( $case['id'] ); ?>">
				<?php wp_nonce_field( 'smc_account_recovery_cancel_' . absint( $case['id'] ), 'smc_nonce' ); ?>
				<label><?php esc_html_e( 'Current account password', 'sabri-membership-core' ); ?><input type="password" name="password" autocomplete="current-password" required></label>
				<button class="smc-button smc-button--secondary"><?php esc_html_e( 'Cancel Recovery Case', 'sabri-membership-core' ); ?></button>
			</form>
		</section>
		<?php
	}

	public static function handle_request() {
		self::guard_subject_action( 'smc_account_recovery_request' );
		$user_id = get_current_user_id();
		$password = (string) wp_unslash( $_POST['password'] ?? '' );
		if ( empty( $_POST['confirm_lost_factors'] ) || ! self::verify_password( $user_id, $password ) ) {
			self::redirect( 'invalid' );
		}
		if ( function_exists( 'smc_privacy_erasure_lock' ) && smc_privacy_erasure_lock( $user_id ) ) {
			self::redirect( 'invalid' );
		}
		if ( ! class_exists( 'SMC_Security' ) || ! SMC_Security::two_factor_ready( $user_id ) ) {
			self::redirect( 'invalid' );
		}
		if ( SMC_Security::rate_limited( 'account-recovery-request|' . $user_id, 3, DAY_IN_SECONDS ) ) {
			self::redirect( 'rate' );
		}
		if ( self::current_case( $user_id ) ) {
			self::redirect( 'requested' );
		}
		$contact_hash = self::current_contact_hash( $user_id );
		if ( is_wp_error( $contact_hash ) ) {
			self::redirect( 'invalid' );
		}
		$policy = self::policy_for_user( $user_id );
		$trace = wp_generate_uuid4();
		$now = self::now_mysql();
		$ready_after = gmdate( 'Y-m-d H:i:s', time() + (int) $policy['cooling_seconds'] );
		$details = array(
			'version'            => 1,
			'evidence_version'   => self::EVIDENCE_VERSION,
			'privileged'         => (bool) $policy['privileged'],
			'required_approvals' => (int) $policy['required_approvals'],
			'cooling_seconds'    => (int) $policy['cooling_seconds'],
			'ready_after'        => $ready_after,
			'old_contact_hash'   => (string) $contact_hash,
			'approvals'          => array(),
			'requested_at'       => $now,
			'notice_route'       => 'file19_outbox',
		);
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id=%d AND repair_type=%s AND status IN ('requested','cooling','approved') LIMIT 1 FOR UPDATE", $user_id, self::REPAIR_TYPE ) );
		if ( $duplicate ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'requested' );
		}
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (trace_id,user_id,repair_type,status,details,attempts,next_attempt_at,last_error,resolved_at,created_at,updated_at) VALUES (%s,%d,%s,'requested',%s,0,%s,NULL,NULL,%s,%s)",
				$trace,
				$user_id,
				self::REPAIR_TYPE,
				self::encode_details( $details ),
				$ready_after,
				$now,
				$now
			)
		);
		$audit_ok = 1 === $inserted && SMC_Security::audit(
			'account_recovery_requested',
			$user_id,
			array(
				'trace_id'           => $trace,
				'evidence_version'    => self::EVIDENCE_VERSION,
				'privileged'          => (bool) $policy['privileged'],
				'required_approvals'  => (int) $policy['required_approvals'],
				'ready_after'         => $ready_after,
			)
		);
		$event_ok = $audit_ok && self::emit_event( 'AccountRecoveryRequested', $user_id, $trace, 'lost_factor' );
		if ( 1 !== $inserted || ! $audit_ok || ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'unavailable' );
		}
		self::notify_best_effort( $user_id, 'requested', $trace );
		self::redirect( 'requested', array( 'trace_id' => $trace ) );
	}

	public static function handle_cancel() {
		$case_id = absint( $_POST['case_id'] ?? 0 );
		self::guard_subject_action( 'smc_account_recovery_cancel_' . $case_id );
		$user_id = get_current_user_id();
		$password = (string) wp_unslash( $_POST['password'] ?? '' );
		if ( ! $case_id || ! self::verify_password( $user_id, $password ) || SMC_Security::rate_limited( 'account-recovery-cancel|' . $user_id, 7, HOUR_IN_SECONDS ) ) {
			self::redirect( 'invalid' );
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		$case = self::load_case_by_id( $case_id, true );
		if ( ! $case || absint( $case['user_id'] ) !== $user_id || ! in_array( $case['status'], array( 'requested', 'cooling', 'approved' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'invalid' );
		}
		$now = self::now_mysql();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='cancelled',resolved_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND status=%s", $now, $now, $case_id, $user_id, $case['status'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'account_recovery_cancelled', $user_id, array( 'trace_id' => $case['trace_id'], 'evidence_version' => self::EVIDENCE_VERSION ) );
		$event_ok = $audit_ok && self::emit_event( 'AccountRecoveryCancelled', $user_id, $case['trace_id'], 'user_cancelled' );
		if ( 1 !== $updated || ! $audit_ok || ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( 'invalid' );
		}
		self::notify_best_effort( $user_id, 'cancelled', $case['trace_id'] );
		self::redirect( 'cancelled' );
	}

	private static function can_review_cases() {
		return current_user_can( 'manage_options' );
	}

	public static function admin_menu() {
		add_management_page(
			__( 'Membership Recovery', 'sabri-membership-core' ),
			__( 'Membership Recovery', 'sabri-membership-core' ),
			'manage_options',
			'smc-account-recovery',
			array( __CLASS__, 'admin_page' )
		);
	}

	public static function admin_page() {
		if ( ! self::can_review_cases() ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Governed Membership Recovery', 'sabri-membership-core' ) . '</h1>';
		echo self::message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! self::table_ready() || ! self::audit_ready() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Recovery infrastructure is unavailable until the File 00 schema and audit chain are ready.', 'sabri-membership-core' ) . '</p></div></div>';
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE repair_type=%s ORDER BY id DESC LIMIT 100", self::REPAIR_TYPE ), ARRAY_A );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No lost-factor recovery cases exist.', 'sabri-membership-core' ) . '</p></div>';
			return;
		}
		foreach ( $rows as $row ) {
			$details = self::parse_details( $row['details'] ?? '' );
			$user = get_userdata( absint( $row['user_id'] ) );
			$count = self::approval_count( $details );
			$required = max( 1, absint( $details['required_approvals'] ?? 1 ) );
			echo '<div class="card" style="max-width:960px">';
			echo '<h2>' . esc_html( '#' . absint( $row['id'] ) . ' · ' . ( $user ? $user->display_name : __( 'Unknown user', 'sabri-membership-core' ) ) ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Trace:', 'sabri-membership-core' ) . '</strong> <code>' . esc_html( (string) $row['trace_id'] ) . '</code></p>';
			echo '<p><strong>' . esc_html__( 'State:', 'sabri-membership-core' ) . '</strong> ' . esc_html( (string) $row['status'] ) . ' · <strong>' . esc_html__( 'Approvals:', 'sabri-membership-core' ) . '</strong> ' . esc_html( $count . '/' . $required ) . ' · <strong>' . esc_html__( 'Ready after:', 'sabri-membership-core' ) . '</strong> ' . esc_html( (string) ( $details['ready_after'] ?? '—' ) ) . ' UTC</p>';
			if ( in_array( $row['status'], array( 'requested', 'cooling' ), true ) ) {
				if ( self::can_approve() && get_current_user_id() !== absint( $row['user_id'] ) ) {
					self::render_admin_approval_form( $row );
					self::render_admin_reject_form( $row );
				} else {
					echo '<p>' . esc_html__( 'Approval controls require an independent Administrator with a current File 00 MFA-verified session.', 'sabri-membership-core' ) . '</p>';
				}
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_admin_approval_form( $case ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0;padding:1rem;border:1px solid #ccd0d4">
			<input type="hidden" name="action" value="smc_account_recovery_approve">
			<input type="hidden" name="case_id" value="<?php echo absint( $case['id'] ); ?>">
			<?php wp_nonce_field( 'smc_account_recovery_approve_' . absint( $case['id'] ), 'smc_nonce' ); ?>
			<p><label><?php esc_html_e( 'Out-of-band evidence type', 'sabri-membership-core' ); ?> <select name="evidence_type" required><option value=""><?php esc_html_e( 'Select', 'sabri-membership-core' ); ?></option><option value="video"><?php esc_html_e( 'Verified video call', 'sabri-membership-core' ); ?></option><option value="phone"><?php esc_html_e( 'Verified known-number call', 'sabri-membership-core' ); ?></option><option value="in_person"><?php esc_html_e( 'In-person identity check', 'sabri-membership-core' ); ?></option><option value="document"><?php esc_html_e( 'Independent document evidence', 'sabri-membership-core' ); ?></option><option value="other"><?php esc_html_e( 'Other approved out-of-band evidence', 'sabri-membership-core' ); ?></option></select></label></p>
			<p><label><?php esc_html_e( 'Evidence reference (ticket/case/reference; stored only as a keyed hash)', 'sabri-membership-core' ); ?> <input type="text" name="evidence_reference" minlength="12" maxlength="190" required></label></p>
			<p><label><input type="checkbox" name="attest" value="1" required> <?php esc_html_e( 'I independently verified the evidence and am not the recovering account holder.', 'sabri-membership-core' ); ?></label></p>
			<p><button class="button button-primary"><?php esc_html_e( 'Record Independent Approval', 'sabri-membership-core' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_admin_reject_form( $case ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0">
			<input type="hidden" name="action" value="smc_account_recovery_reject">
			<input type="hidden" name="case_id" value="<?php echo absint( $case['id'] ); ?>">
			<?php wp_nonce_field( 'smc_account_recovery_reject_' . absint( $case['id'] ), 'smc_nonce' ); ?>
			<label><?php esc_html_e( 'Rejection reason', 'sabri-membership-core' ); ?> <select name="reason_code" required><option value="identity_failed"><?php esc_html_e( 'Identity evidence failed', 'sabri-membership-core' ); ?></option><option value="contact_changed"><?php esc_html_e( 'Account contact changed', 'sabri-membership-core' ); ?></option><option value="duplicate"><?php esc_html_e( 'Duplicate/replaced case', 'sabri-membership-core' ); ?></option><option value="policy_denied"><?php esc_html_e( 'Policy denied', 'sabri-membership-core' ); ?></option></select></label>
			<button class="button"><?php esc_html_e( 'Reject Recovery', 'sabri-membership-core' ); ?></button>
		</form>
		<?php
	}

	public static function handle_approve() {
		$case_id = absint( $_POST['case_id'] ?? 0 );
		if ( ! is_user_logged_in() || ! $case_id ) {
			auth_redirect();
		}
		check_admin_referer( 'smc_account_recovery_approve_' . $case_id, 'smc_nonce' );
		if ( ! self::can_approve() || ! self::table_ready() || ! self::audit_ready() ) {
			self::admin_redirect( 'invalid' );
		}
		$actor_id = get_current_user_id();
		if ( SMC_Security::rate_limited( 'account-recovery-approve|' . $actor_id, 20, HOUR_IN_SECONDS ) ) {
			self::admin_redirect( 'rate' );
		}
		$type = sanitize_key( wp_unslash( $_POST['evidence_type'] ?? '' ) );
		$reference = sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) );
		$allowed_types = array( 'video', 'phone', 'in_person', 'document', 'other' );
		if ( empty( $_POST['attest'] ) || ! in_array( $type, $allowed_types, true ) || strlen( $reference ) < 12 || strlen( $reference ) > 190 ) {
			self::admin_redirect( 'invalid' );
		}
		$reference_hash = SMC_Security::blind_index( $reference, 'account-recovery-evidence' );
		if ( is_wp_error( $reference_hash ) ) {
			self::admin_redirect( 'invalid' );
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		$case = self::load_case_by_id( $case_id, true );
		if ( ! $case || ! in_array( $case['status'], array( 'requested', 'cooling' ), true ) || $actor_id === absint( $case['user_id'] ) || ! self::contact_is_unchanged( $case ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::admin_redirect( 'invalid' );
		}
		$details = self::parse_details( $case['details_array'] ?? $case['details'] );
		$approvals = self::approvals( $details );
		foreach ( $approvals as $approval ) {
			if ( $actor_id === absint( $approval['actor_id'] ?? 0 ) || hash_equals( (string) ( $approval['reference_hash'] ?? '' ), (string) $reference_hash ) ) {
				$wpdb->query( 'ROLLBACK' );
				self::admin_redirect( 'invalid' );
			}
		}
		$approvals[] = array(
			'actor_id'         => $actor_id,
			'evidence_type'    => $type,
			'reference_hash'   => (string) $reference_hash,
			'approved_at'      => self::now_mysql(),
			'evidence_version' => self::EVIDENCE_VERSION,
		);
		$details['approvals'] = $approvals;
		$count = self::approval_count( $details );
		$required = max( 1, absint( $details['required_approvals'] ?? 1 ) );
		$ready_after = isset( $details['ready_after'] ) ? strtotime( (string) $details['ready_after'] . ' UTC' ) : false;
		$new_status = $count >= $required && $ready_after && $ready_after <= time() ? 'approved' : 'cooling';
		$now = self::now_mysql();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status=%s,details=%s,attempts=attempts+1,updated_at=%s WHERE id=%d AND status=%s", $new_status, self::encode_details( $details ), $now, $case_id, $case['status'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit(
			'account_recovery_approval_recorded',
			absint( $case['user_id'] ),
			array(
				'trace_id'           => $case['trace_id'],
				'approval_count'     => $count,
				'required_approvals' => $required,
				'evidence_type'      => $type,
				'evidence_version'   => self::EVIDENCE_VERSION,
			)
		);
		$event_ok = $audit_ok && ( 'approved' !== $new_status || self::emit_event( 'AccountRecoveryApproved', absint( $case['user_id'] ), $case['trace_id'], 'approvals_complete' ) );
		if ( 1 !== $updated || ! $audit_ok || ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::admin_redirect( 'invalid' );
		}
		if ( 'approved' === $new_status ) {
			self::notify_best_effort( absint( $case['user_id'] ), 'approved', $case['trace_id'] );
			self::admin_redirect( 'approved' );
		}
		self::admin_redirect( 'approval_saved' );
	}

	public static function handle_reject() {
		$case_id = absint( $_POST['case_id'] ?? 0 );
		if ( ! is_user_logged_in() || ! $case_id ) {
			auth_redirect();
		}
		check_admin_referer( 'smc_account_recovery_reject_' . $case_id, 'smc_nonce' );
		if ( ! self::can_approve() || ! self::table_ready() || ! self::audit_ready() ) {
			self::admin_redirect( 'invalid' );
		}
		$actor_id = get_current_user_id();
		$reason = sanitize_key( wp_unslash( $_POST['reason_code'] ?? '' ) );
		$allowed = array( 'identity_failed', 'contact_changed', 'duplicate', 'policy_denied' );
		if ( ! in_array( $reason, $allowed, true ) ) {
			self::admin_redirect( 'invalid' );
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		$case = self::load_case_by_id( $case_id, true );
		if ( ! $case || ! in_array( $case['status'], array( 'requested', 'cooling', 'approved' ), true ) || $actor_id === absint( $case['user_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::admin_redirect( 'invalid' );
		}
		$now = self::now_mysql();
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='rejected',last_error=%s,resolved_at=%s,updated_at=%s WHERE id=%d AND status=%s", $reason, $now, $now, $case_id, $case['status'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'account_recovery_rejected', absint( $case['user_id'] ), array( 'trace_id' => $case['trace_id'], 'reason_code' => $reason, 'evidence_version' => self::EVIDENCE_VERSION ) );
		$event_ok = $audit_ok && self::emit_event( 'AccountRecoveryRejected', absint( $case['user_id'] ), $case['trace_id'], $reason );
		if ( 1 !== $updated || ! $audit_ok || ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::admin_redirect( 'invalid' );
		}
		self::notify_best_effort( absint( $case['user_id'] ), 'rejected', $case['trace_id'] );
		self::admin_redirect( 'rejected' );
	}

	private static function clear_old_factor_state( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$meta_keys = array(
			'_smc_totp_secret_enc',
			'_smc_totp_secret',
			'_smc_2fa_enabled',
			'_smc_factor_replace_receipt',
			'_smc_totp_pending_enc',
			'_smc_totp_pending_expires',
			'_smc_recovery_receipt_v2',
			'_smc_recovery_receipt',
			'_smc_recovery_receipt_expires',
		);
		foreach ( $meta_keys as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
			if ( metadata_exists( 'user', $user_id, $meta_key ) ) {
				return false;
			}
		}
		$codes_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d", $user_id ) );
		$state_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d", $user_id ) );
		return false !== $codes_deleted && false !== $state_deleted;
	}

	public static function handle_complete() {
		$case_id = absint( $_POST['case_id'] ?? 0 );
		self::guard_subject_action( 'smc_account_recovery_complete_' . $case_id );
		$user_id = get_current_user_id();
		$password = (string) wp_unslash( $_POST['password'] ?? '' );
		if ( ! $case_id || empty( $_POST['confirm_reset'] ) || ! self::verify_password( $user_id, $password ) ) {
			self::redirect( 'invalid' );
		}
		if ( SMC_Security::rate_limited( 'account-recovery-complete|' . $user_id, 5, HOUR_IN_SECONDS ) ) {
			self::redirect( 'rate' );
		}
		$case = self::load_case_by_id( $case_id, false );
		if ( ! $case || absint( $case['user_id'] ) !== $user_id || ! self::case_ready( $case ) || ! self::contact_is_unchanged( $case ) ) {
			self::redirect( 'not_ready' );
		}
		$new_secret = SMC_Security::base32_secret();
		$pending_expires = time() + 20 * MINUTE_IN_SECONDS;
		$pending_envelope = SMC_Security::encrypt( $new_secret, 'totp-pending', array( 'user_id' => $user_id, 'expires' => $pending_expires ) );
		if ( is_wp_error( $pending_envelope ) ) {
			self::redirect( 'unavailable' );
		}

		/* Revoke first. SMC_Security refuses nested revocation transactions. */
		if ( ! SMC_Security::revoke_all_sessions( $user_id, 'lost_factor_recovery' ) ) {
			self::redirect( 'unavailable' );
		}

		global $wpdb;
		$table = self::table_name();
		$wpdb->query( 'START TRANSACTION' );
		$locked = self::load_case_by_id( $case_id, true );
		if ( ! $locked || absint( $locked['user_id'] ) !== $user_id || ! self::case_ready( $locked ) || ! self::contact_is_unchanged( $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_clear_auth_cookie();
			wp_safe_redirect( wp_login_url( self::recovery_url( array( 'smc_recovery_message' => 'retry_login' ) ) ) );
			exit;
		}
		$old_state_cleared = self::clear_old_factor_state( $user_id );
		update_user_meta( $user_id, '_smc_totp_pending_enc', $pending_envelope );
		update_user_meta( $user_id, '_smc_totp_pending_expires', $pending_expires );
		update_user_meta( $user_id, '_smc_revalidation_required_at', time() );
		$pending_ok = $old_state_cleared
			&& hash_equals( (string) $pending_envelope, (string) get_user_meta( $user_id, '_smc_totp_pending_enc', true ) )
			&& $pending_expires === absint( get_user_meta( $user_id, '_smc_totp_pending_expires', true ) )
			&& ! metadata_exists( 'user', $user_id, '_smc_2fa_enabled' )
			&& ! metadata_exists( 'user', $user_id, '_smc_totp_secret_enc' );
		$now = self::now_mysql();
		$updated = $pending_ok ? $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='completed',resolved_at=%s,updated_at=%s,last_error=NULL WHERE id=%d AND user_id=%d AND status=%s", $now, $now, $case_id, $user_id, $locked['status'] ) ) : false;
		$audit_ok = 1 === $updated && SMC_Security::audit(
			'account_recovery_factor_reset_completed',
			$user_id,
			array(
				'trace_id'             => $locked['trace_id'],
				'evidence_version'     => self::EVIDENCE_VERSION,
				'old_sessions_revoked' => true,
				'new_factor_pending'   => true,
			)
		);
		$event_ok = $audit_ok && self::emit_event( 'AccountRecoveryFactorResetCompleted', $user_id, $locked['trace_id'], 'lost_factor_reset' );
		if ( 1 !== $updated || ! $audit_ok || ! $event_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			wp_clear_auth_cookie();
			wp_safe_redirect( wp_login_url( self::recovery_url( array( 'smc_recovery_message' => 'retry_login' ) ) ) );
			exit;
		}
		clean_user_cache( $user_id );
		self::notify_best_effort( $user_id, 'completed', $locked['trace_id'] );
		wp_clear_auth_cookie();
		wp_safe_redirect( wp_login_url( smc_page_url( 'security', '/membership-security/' ) ) );
		exit;
	}
}

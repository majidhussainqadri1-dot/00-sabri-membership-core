<?php
defined( 'ABSPATH' ) || exit;

/**
 * Host compatibility and bounded runtime protection for File 00.
 *
 * Shared-host PHP builds can differ from CI in optional extensions and provider
 * wiring. File 00 must never turn an ordinary membership button into a generic
 * WordPress critical-error screen. All user-facing and reviewer-facing
 * admin-post actions therefore pass through one bounded dispatcher that records
 * diagnostics, fails closed, and returns the user to a safe screen.
 */
final class SMC_Host_Compat {
	private static $initialized = false;

	public static function lowercase( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value ); }
	private static $protected   = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Run after every File 00 component has registered its native handlers.
		add_action( 'init', array( __CLASS__, 'protect_actions' ), PHP_INT_MAX );
	}

	/**
	 * Canonical action registry. The native handler remains the source of truth;
	 * this class only supplies a host/runtime safety boundary around it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function action_registry() {
		return array(
			'submit_application'     => array( 'callback' => array( 'SMC_Workflow', 'handle_submit_application' ), 'target' => 'application' ),
			'request_contact_otp'    => array( 'callback' => array( 'SMC_Workflow', 'handle_request_contact_otp' ), 'target' => 'status' ),
			'verify_contact_otp'     => array( 'callback' => array( 'SMC_Workflow', 'handle_verify_contact_otp' ), 'target' => 'status' ),
			'start_2fa'              => array( 'callback' => array( 'SMC_Workflow', 'handle_start_2fa' ), 'target' => 'security' ),
			'finish_2fa'             => array( 'callback' => array( 'SMC_Workflow', 'handle_finish_2fa' ), 'target' => 'security' ),
			'challenge_2fa'          => array( 'callback' => array( 'SMC_Workflow', 'handle_challenge_2fa' ), 'target' => 'security' ),
			'rotate_recovery'        => array( 'callback' => array( 'SMC_Workflow', 'handle_rotate_recovery' ), 'target' => 'security' ),
			'ack_recovery_receipt'   => array( 'callback' => array( 'SMC_Workflow', 'handle_ack_recovery_receipt' ), 'target' => 'security' ),
			'revoke_session'         => array( 'callback' => array( 'SMC_Workflow', 'handle_revoke_session' ), 'target' => 'security' ),
			'revoke_all_sessions'    => array( 'callback' => array( 'SMC_Workflow', 'handle_revoke_all_sessions' ), 'target' => 'security' ),
			'resubmit'               => array( 'callback' => array( 'SMC_Workflow', 'handle_resubmit' ), 'target' => 'status' ),
			'appeal'                 => array( 'callback' => array( 'SMC_Workflow', 'handle_appeal' ), 'target' => 'status' ),
			'withdraw_guardian'      => array( 'callback' => array( 'SMC_Workflow', 'handle_withdraw_guardian' ), 'target' => 'status' ),
			'verify_guardian'        => array( 'callback' => array( 'SMC_Workflow', 'handle_verify_guardian' ), 'target' => 'guardian', 'nopriv' => true ),
			'review_transition'      => array( 'callback' => array( 'SMC_Admin', 'handle_transition' ), 'target' => 'admin' ),
			'review_document'        => array( 'callback' => array( 'SMC_Admin', 'handle_document' ), 'target' => 'admin' ),
			'assign_review'          => array( 'callback' => array( 'SMC_Admin', 'handle_assignment' ), 'target' => 'admin' ),
			'declare_conflict'       => array( 'callback' => array( 'SMC_Admin', 'handle_conflict' ), 'target' => 'admin' ),
			'save_founder'           => array( 'callback' => array( 'SMC_Admin', 'save_founder' ), 'target' => 'admin' ),
			'retry_repair'           => array( 'callback' => array( 'SMC_Completion', 'retry_repair' ), 'target' => 'admin' ),
			'retry_outbox'           => array( 'callback' => array( 'SMC_Completion', 'retry_outbox' ), 'target' => 'admin' ),
			'post_restore_reconcile' => array( 'callback' => array( 'SMC_Completion', 'post_restore_reconcile' ), 'target' => 'admin' ),
			'download_backup_manifest' => array( 'callback' => array( 'SMC_Completion', 'download_backup_manifest' ), 'target' => 'admin', 'streaming' => true ),
			'create_retention_hold'  => array( 'callback' => array( 'SMC_Completion', 'create_retention_hold' ), 'target' => 'admin' ),
			'release_retention_hold' => array( 'callback' => array( 'SMC_Completion', 'release_retention_hold' ), 'target' => 'admin' ),
			'save_institutional_ai'  => array( 'callback' => array( 'SMC_Three_Plan', 'save_institutional_ai' ), 'target' => 'admin' ),
			'private_document'       => array( 'callback' => array( 'SMC_Security', 'serve_document' ), 'target' => 'admin', 'streaming' => true ),
		);
	}

	/** Replace native admin-post callbacks with the bounded dispatcher. */
	public static function protect_actions() {
		if ( self::$protected ) {
			return;
		}
		self::$protected = true;

		foreach ( self::action_registry() as $action => $definition ) {
			$hook = 'admin_post_smc_' . $action;
			remove_action( $hook, $definition['callback'] );
			add_action( $hook, array( __CLASS__, 'dispatch' ), 10, 0 );

			if ( ! empty( $definition['nopriv'] ) ) {
				$nopriv_hook = 'admin_post_nopriv_smc_' . $action;
				remove_action( $nopriv_hook, $definition['callback'] );
				add_action( $nopriv_hook, array( __CLASS__, 'dispatch' ), 10, 0 );
			}
		}
	}

	/** Execute one protected action. */
	public static function dispatch() {
		$hook   = (string) current_filter();
		$action = preg_replace( '/^admin_post_(?:nopriv_)?smc_/', '', $hook );
		$action = sanitize_key( (string) $action );
		$all    = self::action_registry();

		if ( ! isset( $all[ $action ] ) || ! is_callable( $all[ $action ]['callback'] ) ) {
			self::record_failure( $action ?: 'unknown', new RuntimeException( 'Registered File 00 action handler is unavailable.' ) );
			self::safe_return( $action, $all[ $action ] ?? array( 'target' => 'status' ) );
		}

		try {
			/**
			 * Test/diagnostic hook only. Production callbacks should normally leave
			 * the value unchanged. Throwing here is deliberately caught below.
			 */
			do_action( 'smc_before_protected_action', $action );
			call_user_func( $all[ $action ]['callback'] );
		} catch ( Throwable $error ) {
			self::record_failure( $action, $error );
			self::safe_return( $action, $all[ $action ] );
		}

		// Native handlers normally redirect/exit. If one unexpectedly returns,
		// avoid leaving the user on a blank wp-admin/admin-post.php response.
		if ( empty( $all[ $action ]['streaming'] ) ) {
			self::safe_return( $action, $all[ $action ], false );
		}
	}

	private static function record_failure( $action, Throwable $error ) {
		$user_id = get_current_user_id();
		$message = sanitize_text_field( $error->getMessage() );
		$record  = array(
			'action'         => sanitize_key( (string) $action ),
			'user_id'        => absint( $user_id ),
			'error_class'    => sanitize_key( get_class( $error ) ),
			'message_digest' => hash( 'sha256', $message ),
			'updated_at'     => current_time( 'mysql', true ),
		);
		update_option( 'smc_last_action_runtime_failure', $record, false );
		if ( $user_id > 0 ) {
			set_transient( 'smc_action_diag_' . $user_id, $record, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Return to the appropriate user/admin surface without exposing internals.
	 */
	private static function safe_return( $action, $definition, $runtime_failure = true ) {
		$target = (string) ( $definition['target'] ?? 'status' );

		if ( 'admin' === $target ) {
			$url = wp_get_referer();
			if ( ! $url || false === strpos( $url, admin_url() ) ) {
				$url = admin_url( 'admin.php?page=smc-membership' );
			}
			if ( $runtime_failure ) {
				$url = add_query_arg( array( 'smc_action_error' => sanitize_key( (string) $action ) ), $url );
			}
			wp_safe_redirect( $url );
			exit;
		}

		$paths = array(
			'application' => '/membership-application/',
			'status'      => '/membership-status/',
			'security'    => '/membership-security/',
			'guardian'    => '/guardian-consent/',
		);
		$key = isset( $paths[ $target ] ) ? $target : 'status';
		$url = smc_page_url( $key, $paths[ $key ] );
		if ( 'guardian' === $key && ! empty( $_POST['guardian_token'] ) ) {
			$guardian_token = sanitize_text_field( wp_unslash( $_POST['guardian_token'] ) );
			if ( preg_match( '/^[A-Za-z0-9]{20,80}$/', $guardian_token ) ) {
				$url = add_query_arg( 'guardian_token', rawurlencode( $guardian_token ), $url );
			}
		}
		if ( $runtime_failure ) {
			$url = add_query_arg(
				array(
					'smc_message' => 'provider',
					'smc_diag'    => sanitize_key( (string) $action ),
				),
				$url
			);
		}
		wp_safe_redirect( $url );
		exit;
	}
}
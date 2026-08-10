<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-subject advisory serialization for governed lost-factor recovery.
 *
 * Recovery request/cancel/complete mutations can otherwise race before an
 * application-repair row exists. A connection-scoped MariaDB/MySQL advisory
 * lock gives those subject mutations one deterministic serialization point
 * without introducing a parallel state store. The lock is released explicitly
 * at shutdown and is also released automatically if the DB connection dies.
 */
final class SMC_Account_Recovery_Lock {
	private static $initialized = false;
	private static $locks       = array();
	private static $shutdown_registered = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'admin_post_smc_account_recovery_request', array( __CLASS__, 'acquire_for_current_subject' ), 1 );
		add_action( 'admin_post_smc_account_recovery_cancel', array( __CLASS__, 'acquire_for_current_subject' ), 1 );
		add_action( 'admin_post_smc_account_recovery_complete', array( __CLASS__, 'acquire_for_current_subject' ), 1 );
	}

	private static function lock_name( $user_id ) {
		return 'smc_recovery_' . substr( hash( 'sha256', 'subject|' . absint( $user_id ) ), 0, 40 );
	}

	private static function recovery_url( $message ) {
		return add_query_arg(
			'smc_recovery_message',
			sanitize_key( (string) $message ),
			smc_page_url( 'recovery', '/membership-recovery/' )
		);
	}

	public static function acquire_for_current_subject() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_safe_redirect( self::recovery_url( 'invalid' ) );
			exit;
		}

		$name = self::lock_name( $user_id );
		if ( ! empty( self::$locks[ $name ] ) ) {
			return;
		}

		global $wpdb;
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, 5 ) );
		if ( 1 !== $got ) {
			wp_safe_redirect( self::recovery_url( 'unavailable' ) );
			exit;
		}

		self::$locks[ $name ] = true;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'release_all' ) );
		}
	}

	public static function release_all() {
		if ( ! self::$locks ) {
			return;
		}
		global $wpdb;
		foreach ( array_keys( self::$locks ) as $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			unset( self::$locks[ $name ] );
		}
	}
}

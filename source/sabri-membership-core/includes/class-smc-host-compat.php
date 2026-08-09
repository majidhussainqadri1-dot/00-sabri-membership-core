<?php
defined( 'ABSPATH' ) || exit;

/**
 * Host compatibility and bounded runtime protection for File 00.
 *
 * Some shared-host PHP builds omit mbstring even when PHP itself is current.
 * File 00 only needs a lower-case normalization fallback for ASCII-oriented
 * blind-index inputs (OTP codes, email/phone identifiers, tokens). When
 * mbstring is unavailable, provide the PHP-compatible function name so the
 * security layer cannot fatal during contact verification.
 */
if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $string, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $encoding );
		return strtolower( (string) $string );
	}
}

final class SMC_Host_Compat {
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Replace only the contact-OTP request action with a Throwable boundary.
		// The underlying workflow remains canonical; this wrapper prevents a host
		// extension/provider failure from becoming a WordPress critical-error page.
		remove_action( 'admin_post_smc_request_contact_otp', array( 'SMC_Workflow', 'handle_request_contact_otp' ) );
		add_action( 'admin_post_smc_request_contact_otp', array( __CLASS__, 'request_contact_otp' ) );
	}

	public static function request_contact_otp() {
		try {
			SMC_Workflow::handle_request_contact_otp();
		} catch ( Throwable $error ) {
			$user_id = get_current_user_id();
			$record  = array(
				'error_class' => get_class( $error ),
				'message'     => sanitize_text_field( $error->getMessage() ),
				'updated_at'  => current_time( 'mysql', true ),
			);
			update_option( 'smc_last_contact_otp_runtime_failure', $record, false );
			if ( $user_id > 0 ) {
				set_transient( 'smc_contact_otp_diag_' . $user_id, $record, 10 * MINUTE_IN_SECONDS );
			}
			$url = add_query_arg(
				array(
					'smc_message' => 'provider',
					'smc_diag'    => 'runtime',
				),
				smc_page_url( 'status', '/membership-status/' )
			);
			wp_safe_redirect( $url );
			exit;
		}
	}
}

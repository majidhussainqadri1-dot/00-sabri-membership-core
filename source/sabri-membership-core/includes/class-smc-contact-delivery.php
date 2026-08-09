<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contact-ownership provider bridge for File 00.
 *
 * File 00 owns OTP generation, hashing, expiry, attempts and verification
 * state. File 19 remains the sole owner of external delivery. This class only
 * translates the File 00 OTP contract into provider hooks and exposes clear
 * readiness guidance in the membership UI.
 */
final class SMC_Contact_Delivery {
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'smc_send_contact_otp', array( __CLASS__, 'deliver' ), 100, 2 );
		add_filter( 'smc_send_guardian_invitation', array( __CLASS__, 'deliver_guardian_invitation' ), 100, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'render_status_assistance' ), 99 );
	}

	/** Execute the canonical File 19 contact-OTP bridge and preserve a receipt-bearing result. */
	public static function send_otp( $payload ) {
		return self::deliver( false, is_array( $payload ) ? $payload : array() );
	}

	/**
	 * @param mixed               $sent Existing provider result.
	 * @param array<string,mixed> $payload OTP payload from File 00.
	 * @return bool|array<string,mixed>
	 */
	public static function deliver( $sent, $payload ) {
		if ( is_array( $sent ) && ! empty( $sent['accepted'] ) ) { return $sent; }
		if ( true === $sent ) { return true; }
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$channel = sanitize_key( (string) ( $payload['channel'] ?? '' ) );
		$target  = trim( (string) ( $payload['target'] ?? '' ) );
		$code    = preg_replace( '/\D/', '', (string) ( $payload['code'] ?? '' ) );
		$user_id = absint( $payload['user_id'] ?? 0 );
		if ( 0 === $user_id || 6 !== strlen( $code ) || ! in_array( $channel, array( 'email', 'mobile' ), true ) ) {
			return false;
		}

		$provider_payload = array(
			'user_id'    => $user_id,
			'channel'    => $channel,
			'target'     => $target,
			'code'       => $code,
			'expires_in' => max( 60, absint( $payload['expires_in'] ?? 600 ) ),
			'purpose'    => 'membership_contact_ownership',
			'category'   => 'security',
		);

		/*
		 * Canonical external-delivery boundary. File 19 (or an explicitly
		 * Founder-approved provider adapter) should attach here and return a structured accepted result with a provider receipt/reference after the provider has accepted the message. File 00 deliberately
		 * does not invoke mail, SMTP, SMS, push or third-party provider APIs.
		 */
		$result = apply_filters( 'smc_external_contact_otp_delivery', null, $provider_payload );
		if ( is_array( $result ) && ! empty( $result['accepted'] ) ) { return $result; }
		if ( true === $result ) { return true; }
		if ( is_wp_error( $result ) ) {
			return false;
		}

		/* Backward-compatible dedicated channel hooks for provider adapters. */
		if ( 'email' === $channel && is_email( $target ) ) {
			$result = apply_filters( 'smc_send_email_otp', null, $target, $code, $provider_payload );
			if ( is_array( $result ) && ! empty( $result['accepted'] ) ) { return $result; }
			return true === $result;
		}
		if ( 'mobile' === $channel && preg_match( '/^\+[1-9][0-9]{7,14}$/', $target ) ) {
			$body = sprintf(
				/* translators: %s: six-digit OTP. */
				__( 'Sabri Homeopathy verification code: %s. Expires in 10 minutes. Do not share this code.', 'sabri-membership-core' ),
				$code
			);
			$result = apply_filters( 'smc_send_sms_otp', null, $target, $body, $provider_payload );
			if ( is_array( $result ) && ! empty( $result['accepted'] ) ) { return $result; }
			return true === $result;
		}

		return false;
	}


	/** Provider bridge for guardian invitations; File 19 remains delivery owner. */
	public static function deliver_guardian_invitation( $sent, $payload ) {
		if ( is_array( $sent ) && ! empty( $sent['accepted'] ) ) { return $sent; }
		if ( true === $sent ) { return true; }
		if ( ! is_array( $payload ) || empty( $payload['user_id'] ) || empty( $payload['consent_id'] ) || empty( $payload['generation'] ) ) { return false; }
		$provider = apply_filters( 'smc_external_guardian_invitation_delivery', null, $payload );
		if ( is_array( $provider ) && ! empty( $provider['accepted'] ) ) { return $provider; }
		if ( true === $provider ) { return true; }
		return false;
	}

	public static function email_provider_ready() {
		return (bool) apply_filters( 'smc_email_otp_provider_configured', false ) || (bool) apply_filters( 'smc_external_contact_otp_provider_configured', false, 'email' );
	}

	public static function mobile_provider_ready() {
		return (bool) apply_filters( 'smc_sms_provider_configured', false ) || (bool) apply_filters( 'smc_external_contact_otp_provider_configured', false, 'mobile' );
	}

	/**
	 * Make Membership Status navigable and expose real provider dependencies.
	 */
	public static function render_status_assistance() {
		if ( ! is_user_logged_in() || ! function_exists( 'smc_is_membership_page' ) || ! smc_is_membership_page() ) {
			return;
		}
		$application   = esc_url_raw( smc_page_url( 'application', '/membership-application/' ) );
		$security      = esc_url_raw( smc_page_url( 'security', '/membership-security/' ) );
		$home          = esc_url_raw( home_url( '/' ) );
		$email_ready   = self::email_provider_ready() ? 'true' : 'false';
		$mobile_ready  = self::mobile_provider_ready() ? 'true' : 'false';
		?>
		<script>
		(function(){
			'use strict';
			var title=document.getElementById('smc-status-title');
			if(!title){return;}
			var panel=title.closest('.smc-panel');
			if(!panel){return;}
			if(!panel.querySelector('.smc-status-journey')){
				var nav=document.createElement('nav');
				nav.className='smc-status-journey';
				nav.setAttribute('aria-label','Membership journey');
				nav.innerHTML='<a class="smc-button" href="<?php echo esc_js( $application ); ?>">← Membership Application</a> <a class="smc-button" href="<?php echo esc_js( $security ); ?>">Continue to Security Center →</a> <a class="smc-button" href="<?php echo esc_js( $home ); ?>">Home</a>';
				title.insertAdjacentElement('afterend',nav);
			}
			var emailReady=<?php echo $email_ready; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
			var mobileReady=<?php echo $mobile_ready; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
			var sections=panel.querySelectorAll('.smc-subpanel');
			sections.forEach(function(section){
				var heading=section.querySelector('h2');
				if(!heading){return;}
				var text=heading.textContent||'';
				if(/email/i.test(text) && !emailReady && !section.querySelector('.smc-email-provider-warning')){
					var emailNote=document.createElement('p');
					emailNote.className='smc-notice smc-notice--warning smc-email-provider-warning';
					emailNote.textContent='Email OTP delivery provider is not configured yet. File 19 must provide the external delivery adapter before email ownership can be verified.';
					heading.insertAdjacentElement('afterend',emailNote);
				}
				if(/mobile/i.test(text) && !mobileReady && !section.querySelector('.smc-sms-provider-warning')){
					var mobileNote=document.createElement('p');
					mobileNote.className='smc-notice smc-notice--warning smc-sms-provider-warning';
					mobileNote.textContent='Mobile SMS provider is not configured yet. File 19 must provide a real SMS adapter before mobile ownership can be verified.';
					heading.insertAdjacentElement('afterend',mobileNote);
				}
			});
		})();
		</script>
		<style>.smc-status-journey{display:flex;gap:.65rem;flex-wrap:wrap;margin:1rem 0 1.25rem}.smc-email-provider-warning,.smc-sms-provider-warning{margin:.75rem 0}</style>
		<?php
	}
}
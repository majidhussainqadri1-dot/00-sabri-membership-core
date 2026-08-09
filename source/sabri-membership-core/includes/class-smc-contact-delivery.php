<?php
defined( 'ABSPATH' ) || exit;

/**
 * Real contact-ownership delivery bridge for File 00.
 *
 * Email OTP uses WordPress' configured mail transport as a safe built-in
 * fallback. Mobile OTP remains fail-closed unless a real SMS provider is
 * configured through File 19's provider hook or the File 00 SMS hook.
 */
final class SMC_Contact_Delivery {
	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'smc_send_contact_otp', array( __CLASS__, 'deliver' ), 100, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'render_status_assistance' ), 99 );
	}

	/**
	 * @param mixed               $sent Existing provider result.
	 * @param array<string,mixed> $payload OTP payload from File 00.
	 * @return bool
	 */
	public static function deliver( $sent, $payload ) {
		if ( true === $sent ) {
			return true;
		}
		if ( ! is_array( $payload ) ) {
			return false;
		}
		$channel = sanitize_key( (string) ( $payload['channel'] ?? '' ) );
		$target  = trim( (string) ( $payload['target'] ?? '' ) );
		$code    = preg_replace( '/\D/', '', (string) ( $payload['code'] ?? '' ) );
		$user_id = absint( $payload['user_id'] ?? 0 );
		if ( 0 === $user_id || 6 !== strlen( $code ) ) {
			return false;
		}

		if ( 'email' === $channel ) {
			return self::send_email( $target, $code );
		}
		if ( 'mobile' === $channel ) {
			return self::send_sms( $target, $code, $user_id, $payload );
		}
		return false;
	}

	private static function send_email( $target, $code ) {
		if ( ! is_email( $target ) || ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( __( '%s verification code', 'sabri-membership-core' ), $site_name );
		$message   = sprintf(
			/* translators: 1: site name, 2: six-digit OTP. */
			__( "Use this one-time code to verify your email ownership on %1$s:\n\n%2$s\n\nThis code expires in 10 minutes. If you did not request it, you can ignore this message.", 'sabri-membership-core' ),
			$site_name,
			$code
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return true === wp_mail( $target, $subject, $message, $headers );
	}

	private static function send_sms( $target, $code, $user_id, $payload ) {
		if ( ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $target ) ) {
			return false;
		}
		$body = sprintf(
			/* translators: %s: six-digit OTP. */
			__( 'Sabri Homeopathy verification code: %s. Expires in 10 minutes. Do not share this code.', 'sabri-membership-core' ),
			$code
		);

		// Dedicated onboarding/identity SMS provider hook.
		$result = apply_filters( 'smc_send_sms_otp', null, $target, $body, $payload );
		if ( true === $result || ( is_array( $result ) && ! empty( $result['accepted'] ) ) ) {
			return true;
		}
		if ( is_wp_error( $result ) ) {
			return false;
		}

		// Reuse File 19's raw configured SMS provider. Do not route through its
		// normal verified-phone notification gate: this OTP establishes phone
		// ownership in the first place.
		if ( (bool) apply_filters( 'sun_sms_adapter_configured', false ) ) {
			$delivery = array(
				'recipient_id' => $user_id,
				'purpose'      => 'membership_contact_ownership',
			);
			$notification = array(
				'category' => 'security',
				'external' => array( 'sms' => array( 'body' => $body ) ),
			);
			$result = apply_filters( 'sun_send_sms', null, $target, $body, $delivery, $notification );
			if ( true === $result || ( is_array( $result ) && ! empty( $result['accepted'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function mobile_provider_ready() {
		return (bool) apply_filters( 'smc_sms_provider_configured', false ) || (bool) apply_filters( 'sun_sms_adapter_configured', false );
	}

	/**
	 * Make Membership Status navigable and expose the real SMS dependency.
	 */
	public static function render_status_assistance() {
		if ( ! is_user_logged_in() || ! function_exists( 'smc_is_membership_page' ) || ! smc_is_membership_page() ) {
			return;
		}
		$application  = esc_url_raw( smc_page_url( 'application', '/membership-application/' ) );
		$security     = esc_url_raw( smc_page_url( 'security', '/membership-security/' ) );
		$home         = esc_url_raw( home_url( '/' ) );
		$mobile_ready = self::mobile_provider_ready() ? 'true' : 'false';
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
			if(<?php echo $mobile_ready; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>===false){
				var sections=panel.querySelectorAll('.smc-subpanel');
				sections.forEach(function(section){
					var heading=section.querySelector('h2');
					if(heading && /mobile/i.test(heading.textContent||'') && !section.querySelector('.smc-sms-provider-warning')){
						var note=document.createElement('p');
						note.className='smc-notice smc-notice--warning smc-sms-provider-warning';
						note.textContent='Mobile SMS provider is not configured yet. A real SMS provider must be connected before mobile ownership can be verified; File 00 will not falsely mark a phone as OTP-verified.';
						heading.insertAdjacentElement('afterend',note);
					}
				});
			}
		})();
		</script>
		<style>.smc-status-journey{display:flex;gap:.65rem;flex-wrap:wrap;margin:1rem 0 1.25rem}.smc-sms-provider-warning{margin:.75rem 0}</style>
		<?php
	}
}

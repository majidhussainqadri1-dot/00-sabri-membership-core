#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('apply.py')
text = path.read_text(encoding='utf-8')

start_marker = "# Draft authenticated expiry: store issued/expires inside payload."
end_marker = "# Queue effects repair helper and process in reconciliation."
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('postpreflight: completion draft markers not found')

stanza = r"""# Draft authenticated expiry: expiry is sealed in the AES-GCM authenticated context and payload.
replace_function(
    completion, "public", "load_draft", "private", "sanitize_draft",
    r'''	public static function load_draft( $user_id ) {
		$receipt = get_user_meta( absint( $user_id ), self::DRAFT_META, true );
		$issued_at = absint( is_array( $receipt ) ? ( $receipt['issued_at'] ?? 0 ) : 0 );
		$expires_at = absint( is_array( $receipt ) ? ( $receipt['expires'] ?? 0 ) : 0 );
		if ( ! is_array( $receipt ) || empty( $receipt['envelope'] ) || ! $issued_at || $expires_at !== $issued_at + self::DRAFT_TTL || $expires_at < time() ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			return array();
		}
		$context = array_merge( self::draft_context( $user_id ), array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at ) );
		$json = SMC_Security::decrypt( $receipt['envelope'], 'application-draft', $context );
		if ( is_wp_error( $json ) ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			SMC_Security::audit( 'application_draft_decryption_failed', absint( $user_id ) );
			return array();
		}
		$sealed = json_decode( $json, true );
		if ( ! is_array( $sealed ) || absint( $sealed['issued_at'] ?? 0 ) !== $issued_at || absint( $sealed['expires_at'] ?? 0 ) !== $expires_at || ! is_array( $sealed['draft'] ?? null ) ) {
			delete_user_meta( absint( $user_id ), self::DRAFT_META );
			SMC_Security::audit( 'application_draft_invalid', absint( $user_id ) );
			return array();
		}
		return $sealed['draft'];
	}'''
)
replace_function(
    completion, "public", "ajax_save_draft", "public", "clear_draft",
    r'''	public static function ajax_save_draft() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'smc_application_draft', 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'unauthorized' ), 403 );
		}
		$user_id = get_current_user_id();
		if ( SMC_Security::rate_limited( 'application-draft|' . $user_id, 120, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'code' => 'rate_limited' ), 429 );
		}
		$raw = isset( $_POST['draft'] ) ? json_decode( wp_unslash( $_POST['draft'] ), true ) : array();
		$data = self::sanitize_draft( $raw );
		$issued_at = time();
		$expires_at = $issued_at + self::DRAFT_TTL;
		$sealed = wp_json_encode( array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at, 'draft'=>$data ) );
		$context = array_merge( self::draft_context( $user_id ), array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at ) );
		$envelope = SMC_Security::encrypt( $sealed, 'application-draft', $context );
		if ( is_wp_error( $envelope ) ) { wp_send_json_error( array( 'code'=>'encryption_unavailable' ), 503 ); }
		$receipt = array( 'envelope'=>$envelope, 'issued_at'=>$issued_at, 'expires'=>$expires_at, 'updated_at'=>$issued_at );
		update_user_meta( $user_id, self::DRAFT_META, $receipt );
		$stored = get_user_meta( $user_id, self::DRAFT_META, true );
		if ( ! is_array( $stored ) || ! hash_equals( (string) $envelope, (string) ( $stored['envelope'] ?? '' ) ) || absint( $stored['expires'] ?? 0 ) !== $expires_at ) {
			wp_send_json_error( array( 'code'=>'draft_not_persisted' ), 500 );
		}
		wp_send_json_success( array( 'updated_at'=>$data['updated_at'], 'expires'=>$expires_at ) );
	}'''
)
"""
text = text[:start] + stanza + text[end:]
path.write_text(text, encoding='utf-8', newline='\n')
print('audit32 post-preflight exact-baseline adjustments applied')

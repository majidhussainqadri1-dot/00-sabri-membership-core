#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('apply.py')
text = path.read_text(encoding='utf-8')

def swap(old: str, new: str, label: str) -> None:
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'preflight: {label} expected 1 match, found {count}')
    text = text.replace(old, new, 1)

swap('''replace_once(
    lifecycle,
    "\\tpublic static function daily() {\\n\\t\\tself::recheck_ages();",
    "\\tpublic static function daily() {\\n\\t\\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\\n\\t\\tself::recheck_ages();",
)''','''replace_once(
    lifecycle,
    "\\tpublic static function daily() {\\n\\t\\tglobal $wpdb;",
    "\\tpublic static function daily() {\\n\\t\\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\\n\\t\\tglobal $wpdb;",
)''','daily')

swap('''replace_once(
    lifecycle,
    "\\tprivate static function is_institutional_user( $user_id ) {\\n\\t\\t$user = get_userdata( absint( $user_id ) );\\n\\t\\treturn $user && ( smc_is_founder( $user_id ) || user_can( $user, 'manage_options' ) );\\n\\t}",
    "\\tprivate static function is_institutional_user( $user_id ) {\\n\\t\\treturn function_exists( 'smc_is_institutional_account' ) && smc_is_institutional_account( absint( $user_id ) );\\n\\t}",
)''','''replace_once(
    lifecycle,
    "\\tprivate static function is_institutional_user( $user_id ) {\\n\\t\\t$user = get_userdata( absint( $user_id ) );\\n\\t\\treturn smc_is_founder( $user_id ) || ( $user && user_can( $user, 'manage_options' ) );\\n\\t}",
    "\\tprivate static function is_institutional_user( $user_id ) {\\n\\t\\treturn function_exists( 'smc_is_institutional_account' ) && smc_is_institutional_account( absint( $user_id ) );\\n\\t}",
)''','institutional predicate')

swap('''replace_once(
    lifecycle,
    "$minimum = smc_minimum_age_for_gender( $app['gender'] );",
    "$minimum = smc_effective_minimum_age( $app['gender'], $app['residence_country'] ?? '' );",
)''','''replace_once(
    lifecycle,
    "$minimum_age = smc_minimum_age_for_gender( $app['gender'] );",
    "$minimum_age = smc_effective_minimum_age( $app['gender'], $app['residence_country'] ?? '' );",
)''','effective age')

swap(
    '''    r"\\n\\tprivate static function restrict\\( \\$app, \\$status, \\$reason \\) \\{.*?\\n\\t\\}\\n\\n\\tprivate static function cleanup_database",''',
    '''    r"\\n\\tprivate static function restrict\\( \\$app, \\$reason, \\$status = 'suspended' \\) \\{.*?\\n\\t\\}\\n\\n\\tprivate static function cleanup_database",''',
    'restrict regex',
)
swap(
    '''\tprivate static function restrict( $app, $status, $reason ) {''',
    '''\tprivate static function restrict( $app, $reason, $status = 'suspended' ) {''',
    'restrict replacement signature',
)

swap('''replace_once(
    lifecycle,
    "\\t\\t$wpdb->query( \\\"DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE (revoked_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()) AND created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)\\\" );",
    "\\t\\t$expired_sessions = $wpdb->get_results( \\\"SELECT id,user_id,token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE (revoked_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()) AND created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT 500\\\", ARRAY_A );\\n\\t\\tforeach ( (array) $expired_sessions as $session ) {\\n\\t\\t\\tif ( SMC_Security::delete_session_token_envelope( (int) $session['user_id'], (string) $session['token_hash'] ) ) { $wpdb->delete( $wpdb->prefix . 'smc_auth_sessions', array( 'id' => (int) $session['id'] ), array( '%d' ) ); }\\n\\t\\t}\\n\\t\\t$session_users = array_values( array_unique( array_map( 'absint', array_column( (array) $expired_sessions, 'user_id' ) ) ) );\\n\\t\\tforeach ( $session_users as $session_user_id ) { $live = $wpdb->get_col( $wpdb->prepare( \\\"SELECT token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()\\\", $session_user_id ) ); SMC_Security::sweep_session_token_envelopes( $session_user_id, $live ); }",
)''','''replace_once(
    lifecycle,
    "\\t\\t$wpdb->query( \\\"DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 30 DAY OR revoked_at<UTC_TIMESTAMP() - INTERVAL 30 DAY\\\" );",
    "\\t\\t$expired_sessions = $wpdb->get_results( \\\"SELECT id,user_id,token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE expires_at<UTC_TIMESTAMP() - INTERVAL 30 DAY OR revoked_at<UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 500\\\", ARRAY_A );\\n\\t\\tforeach ( (array) $expired_sessions as $session ) {\\n\\t\\t\\tif ( SMC_Security::delete_session_token_envelope( (int) $session['user_id'], (string) $session['token_hash'] ) ) { $wpdb->delete( $wpdb->prefix . 'smc_auth_sessions', array( 'id' => (int) $session['id'] ), array( '%d' ) ); }\\n\\t\\t}\\n\\t\\t$session_users = array_values( array_unique( array_map( 'absint', array_column( (array) $expired_sessions, 'user_id' ) ) ) );\\n\\t\\tforeach ( $session_users as $session_user_id ) { $live = $wpdb->get_col( $wpdb->prepare( \\\"SELECT token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()\\\", $session_user_id ) ); SMC_Security::sweep_session_token_envelopes( $session_user_id, $live ); }",
)''','session cleanup')

swap(
    '''replace_once(workflow, "array( 'draft', 'more_information', 'rejected' )", "array( 'draft', 'more_information' )")''',
    '''text = read(workflow)\nold_reapply = "array( 'draft', 'more_information', 'rejected' )"\nif text.count(old_reapply) != 2:\n    raise SystemExit(f"{workflow}: expected two rejected-reapply guards, found {text.count(old_reapply)}")\nwrite(workflow, text.replace(old_reapply, "array( 'draft', 'more_information' )"))''',
    'rejected reapplication guards',
)

swap('''# Server-side submit guard against rejected/suspended/appeal bypass.
replace_once(
    workflow,
    "\\t\\t$app = smc_application( $user_id );\\n\\t\\tif ( ! $app ) {",
    "\\t\\t$app = smc_application( $user_id );\\n\\t\\tif ( $app && in_array( sanitize_key( $app['status'] ?? '' ), array( 'rejected','suspended','appeal_review' ), true ) ) { self::redirect( 'status', 'appeal_required' ); }\\n\\t\\tif ( ! $app ) {",
)''','''# Server-side rejected/suspended/appeal bypass is blocked by the existing application-state guard above.''','redundant submit guard')

# Replace the brittle guardian regex patch with a whole-function rewrite against the exact baseline.
start_marker = "# Guardian: durable generation first, delivery second. Replace block from send to insert/upssert."
end_marker = "# Queries for guardian verification use current generation."
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('preflight: guardian patch markers not found')
guardian_stanza = r"""# Guardian: persist a distinct immutable consent generation before delivery; revert current-generation pointer on provider failure.
replace_function(
    workflow, "private", "create_guardian_invitation", "private", "submit_request",
    r'''	private static function create_guardian_invitation( $user_id ) {
		$name = sanitize_text_field( wp_unslash( $_POST['guardian_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['guardian_email'] ?? '' ) );
		$phone = smc_normalize_phone( wp_unslash( $_POST['guardian_phone'] ?? '' ) );
		$relationship = sanitize_key( wp_unslash( $_POST['guardian_relationship'] ?? '' ) );
		if ( ! $name || ! is_email( $email ) || is_wp_error( $phone ) || ! in_array( $relationship, array( 'parent', 'legal_guardian' ), true ) || empty( $_POST['guardian_authority'] ) ) { return false; }
		$code = (string) random_int( 100000, 999999 );
		$token = wp_generate_password( 48, false, false );
		$context = array( 'user_id' => absint( $user_id ) );
		$name_enc = SMC_Security::encrypt( $name, 'guardian-name', $context );
		$email_enc = SMC_Security::encrypt( $email, 'guardian-email', $context );
		$phone_enc = SMC_Security::encrypt( $phone, 'guardian-phone', $context );
		$email_hash = SMC_Security::blind_index( $email, 'guardian-email' );
		$phone_hash = SMC_Security::blind_index( $phone, 'guardian-phone' );
		$lookup = SMC_Security::blind_index( $code, 'guardian-otp' );
		$token_hash = SMC_Security::blind_index( $token, 'guardian-invitation' );
		foreach ( array( $name_enc, $email_enc, $phone_enc, $email_hash, $phone_hash, $lookup, $token_hash ) as $value ) { if ( is_wp_error( $value ) ) { return false; } }
		$consent_text = __( 'I confirm that I am the parent or lawful guardian, consent to this minor using the platform under its published rules, and understand that I may withdraw consent.', 'sabri-membership-core' );
		$link = add_query_arg( 'guardian_token', rawurlencode( $token ), smc_page_url( 'guardian', '/guardian-consent/' ) );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT id,generation FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 ORDER BY generation DESC,id DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$generation = $previous ? (int) $previous['generation'] + 1 : 1;
		if ( $previous && 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=0 WHERE id=%d AND user_id=%d AND is_current=1", (int) $previous['id'], $user_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$inserted = $wpdb->insert( $wpdb->prefix . 'smc_guardian_consents', array(
			'user_id'=>$user_id,'generation'=>$generation,'is_current'=>1,'guardian_name_enc'=>$name_enc,'guardian_email_enc'=>$email_enc,'guardian_email_hash'=>$email_hash,'guardian_phone_enc'=>$phone_enc,'guardian_phone_hash'=>$phone_hash,'relationship'=>$relationship,'legal_authority_confirmed'=>1,'status'=>'pending','consent_text'=>$consent_text,'consent_hash'=>hash('sha256',$consent_text),'policy_version'=>smc_policy()['version'],'otp_hash'=>wp_hash_password($code),'otp_lookup_hash'=>$lookup,'invitation_token_hash'=>$token_hash,'otp_attempts'=>0,'otp_expires_at'=>gmdate('Y-m-d H:i:s',time()+15*MINUTE_IN_SECONDS),'requested_at'=>$now,'verified_at'=>null,'withdrawn_at'=>null,'ip_hash'=>SMC_Security::blind_index($_SERVER['REMOTE_ADDR']??'','guardian-ip'),'device_hash'=>SMC_Security::blind_index($_SERVER['HTTP_USER_AGENT']??'','guardian-device')
		) );
		if ( 1 !== $inserted ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$consent_id = (int) $wpdb->insert_id;
		if ( ! SMC_Security::audit( 'guardian_invitation_created', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return false; }
		$sent = apply_filters( 'smc_send_guardian_invitation', false, array( 'user_id'=>$user_id,'consent_id'=>$consent_id,'generation'=>$generation,'guardian_name'=>$name,'guardian_email'=>$email,'guardian_phone'=>$phone,'code'=>$code,'link'=>$link,'expires_in'=>900 ) );
		if ( true !== $sent ) {
			$wpdb->query( 'START TRANSACTION' );
			$new_failed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='delivery_failed',is_current=0,withdrawn_at=%s WHERE id=%d AND user_id=%d AND status='pending' AND is_current=1", current_time('mysql',true), $consent_id, $user_id ) );
			$old_restored = ! $previous || 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=1 WHERE id=%d AND user_id=%d AND is_current=0", (int) $previous['id'], $user_id ) );
			$audit_ok = $new_failed && $old_restored && SMC_Security::audit( 'guardian_invitation_delivery_failed', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) );
			if ( ! $new_failed || ! $old_restored || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); return false; }
			$wpdb->query( 'COMMIT' );
			return false;
		}
		return true;
	}'''
)
"""
text = text[:start] + guardian_stanza + text[end:]

path.write_text(text, encoding='utf-8', newline='\n')
print('audit32 driver preflight adjusted')

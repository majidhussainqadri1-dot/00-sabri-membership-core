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

path.write_text(text, encoding='utf-8', newline='\n')
print('audit32 driver preflight adjusted')

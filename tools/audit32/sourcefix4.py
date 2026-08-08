#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'source/sabri-membership-core/includes/class-smc-lifecycle.php'
text = path.read_text(encoding='utf-8')
old = "\tprivate static function is_institutional_user( $user_id ) {\n\t\treturn function_exists( 'smc_is_institutional_account' ) && smc_is_institutional_account( absint( $user_id ) );\n\t}"
new = "\tprivate static function is_institutional_user( $user_id ) {\n\t\t$user_id = absint( $user_id );\n\t\tif ( function_exists( 'smc_is_institutional_account' ) ) { return (bool) smc_is_institutional_account( $user_id ); }\n\t\t// Isolated repair/CLI contexts may load Lifecycle before functions.php. Preserve\n\t\t// the canonical Founder/Admin rule and include the AI predicate when available.\n\t\t$user = get_userdata( $user_id );\n\t\treturn smc_is_founder( $user_id ) || ( function_exists( 'smc_is_institutional_ai' ) && smc_is_institutional_ai( $user_id ) ) || ( $user && user_can( $user, 'manage_options' ) );\n\t}"
if text.count(old) != 1:
    raise SystemExit(f'sourcefix4: institutional predicate expected once, found {text.count(old)}')
text = text.replace(old, new, 1)
old = "\t\t\t'reason' => is_array( $decoded ) && isset( $decoded['reason'] ) ? sanitize_key( $decoded['reason'] ) : '',"
new = "\t\t\t'reason' => is_array( $decoded ) ? sanitize_key( $decoded['reason_code'] ?? $decoded['reason'] ?? '' ) : '',"
if text.count(old) != 1:
    raise SystemExit(f'sourcefix4: hard-block reason parser expected once, found {text.count(old)}')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8', newline='\n')
print('institutional lifecycle predicate fallback and reason-code parsing fixed')

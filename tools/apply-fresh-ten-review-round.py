#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
hard=root/'qa/advanced-trust-review-hardening-runtime.php'
s=source.read_text()
start=s.index("\tprivate static function acquire_break_glass_lock() {")
end=s.index("\n\tprivate static function current_guardian_consent_id",start)
replacement="""\tprivate static function acquire_break_glass_lock() {\n\t\tglobal $wpdb;\n\t\tif ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return false; }\n\t\t$lock_name = 'smc_emergency_governance_v2';\n\t\t$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock_name, 3 ) );\n\t\treturn '1' === (string) $locked ? $lock_name : false;\n\t}\n\n\tprivate static function release_break_glass_lock( $token ) {\n\t\tglobal $wpdb;\n\t\tif ( 'smc_emergency_governance_v2' !== (string) $token || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return; }\n\t\t$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $token ) );\n\t}\n"""
s=s[:start]+replacement+s[end:]
source.write_text(s)
h=hard.read_text()
anchor="$bg=SMC_Advanced_Trust_2026::open_break_glass(7,1,'recovery');$current_user_id=2;$ap=SMC_Advanced_Trust_2026::approve_break_glass($bg['id'],2);$current_user_id=1;$one=SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1);$two=SMC_Advanced_Trust_2026::consume_break_glass($bg['id'],1);t('break-glass one-time consumption',is_array($one)&&$two===false&&$ap===true);\n"
if anchor not in h: raise SystemExit('round8 test anchor not found')
h=h.replace(anchor,"$wpdb->lock_calls=0;"+anchor+"t('emergency governance uses serialized database advisory locks',$wpdb->lock_calls>=6);\n",1)
hard.write_text(h)

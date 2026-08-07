#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
hard=root/'qa/advanced-trust-review-hardening-runtime.php'
s=source.read_text()
old="""\tpublic static function daily_reverification_sweep() {\n\t\tglobal $wpdb;\n\t\t$batch = 200;\n\t\t$last_id = max( 0, absint( get_option( self::REVERIFY_CURSOR_OPTION, 0 ) ) );\n\t\tif ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->users ) ) {\n\t\t\treturn;\n\t\t}\n\t\t$ids = $wpdb->get_col( $wpdb->prepare( \"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d\", $last_id, $batch ) );\n\t\tforeach ( (array) $ids as $user_id ) {\n\t\t\t$user_id = absint( $user_id );\n\t\t\t$status = self::reverification_status( $user_id );\n\t\t\tif ( ! empty( $status['overdue'] ) ) {\n\t\t\t\tupdate_user_meta( $user_id, '_smc_reverification_required', 1 );\n\t\t\t}\n\t\t}\n\t\t$next = count( (array) $ids ) < $batch ? 0 : absint( end( $ids ) );\n\t\tupdate_option( self::REVERIFY_CURSOR_OPTION, $next, false );\n\t}\n"""
new="""\tpublic static function daily_reverification_sweep() {\n\t\tglobal $wpdb;\n\t\t$batch = 200;\n\t\t$last_id = max( 0, absint( get_option( self::REVERIFY_CURSOR_OPTION, 0 ) ) );\n\t\tif ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->users ) ) { return; }\n\t\t$ids = $wpdb->get_col( $wpdb->prepare( \"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d\", $last_id, $batch ) );\n\t\t$cursor = $last_id;\n\t\tforeach ( (array) $ids as $user_id ) {\n\t\t\t$user_id = absint( $user_id );\n\t\t\t$status = self::reverification_status( $user_id );\n\t\t\tif ( ! empty( $status['overdue'] ) && ! get_user_meta( $user_id, '_smc_reverification_required', true ) ) {\n\t\t\t\tif ( ! self::write_user_meta_verified( $user_id, '_smc_reverification_required', 1 )\n\t\t\t\t\t|| ! SMC_Security::audit( 'membership_reverification_overdue', $user_id, array( 'due_at' => absint( $status['due_at'] ?? 0 ) ) )\n\t\t\t\t\t|| false === self::bump_revocation_epoch( $user_id, 'membership_reverification_overdue' ) ) {\n\t\t\t\t\tself::write_option_verified( self::REVERIFY_CURSOR_OPTION, $cursor );\n\t\t\t\t\treturn;\n\t\t\t\t}\n\t\t\t}\n\t\t\t$cursor = $user_id;\n\t\t}\n\t\t$next = count( (array) $ids ) < $batch ? 0 : $cursor;\n\t\tself::write_option_verified( self::REVERIFY_CURSOR_OPTION, $next );\n\t}\n"""
if old not in s: raise SystemExit('round7 sweep block not found')
s=s.replace(old,new,1);source.write_text(s)
h=hard.read_text()
oldstub="function get_col($q){return [];}"
newstub="public $ids=[];function get_col($q){return $this->ids;}"
if oldstub not in h: raise SystemExit('round7 stub get_col not found')
h=h.replace(oldstub,newstub,1)
anchor="$wpdb->approved_at=gmdate('Y-m-d H:i:s',time()-2*YEAR_IN_SECONDS);t('expired approval baseline blocks synchronously without waiting for cron',!SMC_Advanced_Trust_2026::protected_actions_allowed(10));$wpdb->approved_at=gmdate('Y-m-d H:i:s');t('recent approval baseline remains current before periodic due date',SMC_Advanced_Trust_2026::protected_actions_allowed(10));\n"
insert=anchor+"$actions=[];$wpdb->approved_at=gmdate('Y-m-d H:i:s',time()-2*YEAR_IN_SECONDS);$wpdb->ids=[20];SMC_Advanced_Trust_2026::daily_reverification_sweep();$has_revocation=false;foreach($actions as $a){if($a[0]==='smc_trust_revocation_invalidated')$has_revocation=true;}t('overdue sweep persists hold and propagates revocation',get_user_meta(20,'_smc_reverification_required',true)==1&&$has_revocation);$wpdb->ids=[];$wpdb->approved_at=gmdate('Y-m-d H:i:s');\n"
if anchor not in h: raise SystemExit('round7 test anchor not found')
h=h.replace(anchor,insert,1);hard.write_text(h)

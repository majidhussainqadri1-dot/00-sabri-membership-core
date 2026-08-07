#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = root / 'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
test = root / 'qa/advanced-trust-review-hardening-runtime.php'

s = source.read_text()
old = """\tpublic static function reverification_status( $user_id ) {\n\t\t$user_id = absint( $user_id );\n\t\t$state = get_user_meta( $user_id, self::REVERIFY_META, true );\n\t\t$state = is_array( $state ) ? $state : array();\n\t\t$verified_at = absint( $state['verified_at'] ?? 0 );\n\t\t$interval = absint( apply_filters( 'smc_reverification_interval_seconds', YEAR_IN_SECONDS, $user_id ) );\n\t\t$interval = max( DAY_IN_SECONDS, $interval );\n\t\t$due_at = absint( $state['due_at'] ?? ( $verified_at ? $verified_at + $interval : 0 ) );\n\t\t$current = $verified_at > 0 && $due_at > time();\n\t\treturn array(\n\t\t\t'current' => (bool) $current,\n\t\t\t'verified_at' => $verified_at,\n\t\t\t'due_at' => $due_at,\n\t\t\t'overdue' => $due_at > 0 && $due_at <= time(),\n\t\t\t'interval_seconds' => $interval,\n\t\t);\n\t}\n"""
new = """\tpublic static function reverification_status( $user_id ) {\n\t\t$user_id = absint( $user_id );\n\t\t$state = get_user_meta( $user_id, self::REVERIFY_META, true );\n\t\t$state = is_array( $state ) ? $state : array();\n\t\t$interval = absint( apply_filters( 'smc_reverification_interval_seconds', YEAR_IN_SECONDS, $user_id ) );\n\t\t$interval = max( DAY_IN_SECONDS, $interval );\n\t\t$verified_at = absint( $state['verified_at'] ?? 0 );\n\t\t$applicable = $verified_at > 0;\n\t\t$source = sanitize_key( $state['source'] ?? '' );\n\t\tif ( $verified_at <= 0 ) {\n\t\t\t$baseline = self::initial_reverification_baseline( $user_id );\n\t\t\t$applicable = ! empty( $baseline['applicable'] );\n\t\t\t$verified_at = absint( $baseline['verified_at'] ?? 0 );\n\t\t\t$source = sanitize_key( $baseline['source'] ?? '' );\n\t\t}\n\t\t$due_at = absint( $state['due_at'] ?? ( $verified_at ? $verified_at + $interval : 0 ) );\n\t\t$current = ! $applicable || ( $verified_at > 0 && $due_at > time() );\n\t\treturn array(\n\t\t\t'applicable' => (bool) $applicable,\n\t\t\t'current' => (bool) $current,\n\t\t\t'verified_at' => $verified_at,\n\t\t\t'due_at' => $due_at,\n\t\t\t'overdue' => $applicable && ( $due_at <= 0 || $due_at <= time() ),\n\t\t\t'interval_seconds' => $interval,\n\t\t\t'source' => $source,\n\t\t);\n\t}\n\n\tprivate static function initial_reverification_baseline( $user_id ) {\n\t\tglobal $wpdb;\n\t\t$user_id = absint( $user_id );\n\t\tif ( $user_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {\n\t\t\treturn array( 'applicable' => false, 'verified_at' => 0, 'source' => 'none' );\n\t\t}\n\t\t$approved_at = $wpdb->get_var(\n\t\t\t$wpdb->prepare(\n\t\t\t\t\"SELECT approved_at FROM {$wpdb->prefix}smc_role_grants WHERE user_id=%d AND status='approved' AND approved_at IS NOT NULL ORDER BY approved_at DESC LIMIT 1\",\n\t\t\t\t$user_id\n\t\t\t)\n\t\t);\n\t\t$source = 'role_grant_approval';\n\t\tif ( ! $approved_at ) {\n\t\t\t$approved_at = $wpdb->get_var(\n\t\t\t\t$wpdb->prepare(\n\t\t\t\t\t\"SELECT COALESCE(decided_at,updated_at,created_at) FROM {$wpdb->prefix}smc_applications WHERE user_id=%d AND status='approved' ORDER BY id DESC LIMIT 1\",\n\t\t\t\t\t$user_id\n\t\t\t\t)\n\t\t\t);\n\t\t\t$source = 'membership_approval';\n\t\t}\n\t\tif ( ! $approved_at ) {\n\t\t\treturn array( 'applicable' => false, 'verified_at' => 0, 'source' => 'none' );\n\t\t}\n\t\t$timestamp = strtotime( (string) $approved_at . ' UTC' );\n\t\treturn array(\n\t\t\t'applicable' => true,\n\t\t\t'verified_at' => $timestamp > 0 ? $timestamp : 0,\n\t\t\t'source' => $source,\n\t\t);\n\t}\n"""
if old not in s:
    raise SystemExit('round1 reverification block not found')
s = s.replace(old, new, 1)
old2 = """\t\t$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );\n\t\t$critical = get_user_meta( $user_id, self::CRITICAL_IDENTITY_META, true );\n"""
new2 = """\t\t$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );\n\t\t$reverification = self::reverification_status( $user_id );\n\t\t$reverification_stale = ! empty( $reverification['applicable'] ) && empty( $reverification['current'] );\n\t\t$critical = get_user_meta( $user_id, self::CRITICAL_IDENTITY_META, true );\n"""
if old2 not in s:
    raise SystemExit('round1 protected block prelude not found')
s = s.replace(old2, new2, 1)
old3 = """\t\treturn 'clear' === ( $containment['state'] ?? 'unknown' ) && 'active' === ( $continuity['state'] ?? 'unknown' ) && ! $reverification_required && ! $critical_pending && ! $merge_finalizing;\n"""
new3 = """\t\treturn 'clear' === ( $containment['state'] ?? 'unknown' ) && 'active' === ( $continuity['state'] ?? 'unknown' ) && ! $reverification_required && ! $reverification_stale && ! $critical_pending && ! $merge_finalizing;\n"""
if old3 not in s:
    raise SystemExit('round1 protected return not found')
s = s.replace(old3, new3, 1)
source.write_text(s)

t = test.read_text()
old4 = "class WPDBStub{public $users='wp_users';public $prefix='wp_';function prepare($q,...$a){return $q;}function get_col($q){return [];}function get_var($q){return 2;}function get_results($q,$mode){return [['id'=>1,'action'=>'membership_reverified','created_at'=>'2026-08-07 00:00:00']];}}"
new4 = "class WPDBStub{public $users='wp_users';public $prefix='wp_';public $approved_at;function __construct(){$this->approved_at=gmdate('Y-m-d H:i:s');}function prepare($q,...$a){return $q;}function get_col($q){return [];}function get_var($q){if(strpos($q,'smc_role_grants')!==false||strpos($q,'smc_applications')!==false)return $this->approved_at;return 2;}function get_results($q,$mode){return [['id'=>1,'action'=>'membership_reverified','created_at'=>'2026-08-07 00:00:00']];}}"
if old4 not in t:
    raise SystemExit('round1 WPDB stub not found')
t = t.replace(old4, new4, 1)
anchor = "update_user_meta(7,'_smc_reverification_required',1);t('reverification marker blocks protected actions',!SMC_Advanced_Trust_2026::protected_actions_allowed(7));delete_user_meta(7,'_smc_reverification_required');\n"
insert = anchor + "$wpdb->approved_at=gmdate('Y-m-d H:i:s',time()-2*YEAR_IN_SECONDS);t('expired approval baseline blocks synchronously without waiting for cron',!SMC_Advanced_Trust_2026::protected_actions_allowed(7));$wpdb->approved_at=gmdate('Y-m-d H:i:s');t('recent approval baseline remains current before periodic due date',SMC_Advanced_Trust_2026::protected_actions_allowed(7));\n"
if anchor not in t:
    raise SystemExit('round1 test anchor not found')
t = t.replace(anchor, insert, 1)
test.write_text(t)

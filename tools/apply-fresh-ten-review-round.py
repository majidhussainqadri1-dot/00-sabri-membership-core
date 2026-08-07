#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
hard=root/'qa/advanced-trust-review-hardening-runtime.php'
runtime=root/'qa/advanced-trust-runtime.php'
s=source.read_text()
old="""\tpublic static function bump_revocation_epoch( $user_id, $reason ) {\n\t\t$user_id = absint( $user_id );\n\t\tif ( $user_id <= 0 ) { return false; }\n\t\t$next = max( time(), self::revocation_epoch( $user_id ) + 1 );\n\t\tif ( ! self::write_user_meta_verified( $user_id, self::REVOCATION_META, $next ) ) {\n\t\t\treturn false;\n\t\t}\n\t\t$event = array(\n\t\t\t'subject' => self::subject_reference( $user_id ),\n\t\t\t'epoch' => $next,\n\t\t\t'reason' => sanitize_key( $reason ),\n\t\t\t'invalidated_at' => time(),\n\t\t\t'consumer_deadline' => time() + self::REVOCATION_PROPAGATION_SLA,\n\t\t\t'sla_seconds' => self::REVOCATION_PROPAGATION_SLA,\n\t\t);\n\t\tdo_action( 'smc_trust_revocation_invalidated', $user_id, $event );\n\t\treturn $event;\n\t}\n"""
new="""\tpublic static function bump_revocation_epoch( $user_id, $reason ) {\n\t\t$user_id = absint( $user_id );\n\t\tif ( $user_id <= 0 ) { return false; }\n\t\t$lock = self::acquire_revocation_lock( $user_id );\n\t\tif ( false === $lock ) { return false; }\n\t\t$next = max( time(), self::revocation_epoch( $user_id ) + 1 );\n\t\tif ( ! self::write_user_meta_verified( $user_id, self::REVOCATION_META, $next ) ) {\n\t\t\tself::release_revocation_lock( $lock );\n\t\t\treturn false;\n\t\t}\n\t\t$event = array(\n\t\t\t'subject' => self::subject_reference( $user_id ),\n\t\t\t'epoch' => $next,\n\t\t\t'reason' => sanitize_key( $reason ),\n\t\t\t'invalidated_at' => time(),\n\t\t\t'consumer_deadline' => time() + self::REVOCATION_PROPAGATION_SLA,\n\t\t\t'sla_seconds' => self::REVOCATION_PROPAGATION_SLA,\n\t\t);\n\t\tdo_action( 'smc_trust_revocation_invalidated', $user_id, $event );\n\t\tself::release_revocation_lock( $lock );\n\t\treturn $event;\n\t}\n\n\tprivate static function acquire_revocation_lock( $user_id ) {\n\t\tglobal $wpdb;\n\t\tif ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) { return false; }\n\t\t$subject = class_exists( 'SMC_Security' ) ? SMC_Security::subject_hash( absint( $user_id ) ) : (string) absint( $user_id );\n\t\t$lock_name = 'smc_rev_' . substr( hash( 'sha256', (string) $subject ), 0, 40 );\n\t\t$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $lock_name, 2 ) );\n\t\treturn '1' === (string) $locked ? $lock_name : false;\n\t}\n\n\tprivate static function release_revocation_lock( $lock_name ) {\n\t\tglobal $wpdb;\n\t\tif ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {\n\t\t\t$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock_name ) );\n\t\t}\n\t}\n"""
if old not in s: raise SystemExit('round2 bump block not found')
s=s.replace(old,new,1); source.write_text(s)

h=hard.read_text()
oldstub="class WPDBStub{public $users='wp_users';public $prefix='wp_';public $approved_at;function __construct(){$this->approved_at=gmdate('Y-m-d H:i:s');}function prepare($q,...$a){return $q;}function get_col($q){return [];}function get_var($q){if(strpos($q,'smc_role_grants')!==false||strpos($q,'smc_applications')!==false)return $this->approved_at;return 2;}function get_results($q,$mode){return [['id'=>1,'action'=>'membership_reverified','created_at'=>'2026-08-07 00:00:00']];}}"
newstub="class WPDBStub{public $users='wp_users';public $prefix='wp_';public $approved_at;public $lock_calls=0;function __construct(){$this->approved_at=gmdate('Y-m-d H:i:s');}function prepare($q,...$a){return $q;}function get_col($q){return [];}function get_var($q){if(strpos($q,'GET_LOCK')!==false||strpos($q,'RELEASE_LOCK')!==false){$this->lock_calls++;return 1;}if(strpos($q,'smc_role_grants')!==false||strpos($q,'smc_applications')!==false)return $this->approved_at;return 2;}function get_results($q,$mode){return [['id'=>1,'action'=>'membership_reverified','created_at'=>'2026-08-07 00:00:00']];}}"
if oldstub not in h: raise SystemExit('round2 hard stub not found')
h=h.replace(oldstub,newstub,1)
anchor="$actions=[];$rev=SMC_Advanced_Trust_2026::bump_revocation_epoch(7,'x');t('revocation payload excludes raw user id',is_array($rev)&&!array_key_exists('user_id',$rev)&&$rev['subject']==='uuid-7');\n"
insert=anchor+"$before_epoch=SMC_Advanced_Trust_2026::revocation_epoch(7);$rev2=SMC_Advanced_Trust_2026::bump_revocation_epoch(7,'x2');t('revocation epoch is strictly monotonic under serialized mutation',is_array($rev2)&&$rev2['epoch']>$before_epoch&&$wpdb->lock_calls>=4);\n"
if anchor not in h: raise SystemExit('round2 hard test anchor not found')
h=h.replace(anchor,insert,1); hard.write_text(h)

r=runtime.read_text()
anchor2="class SMC_Security {\n"
stub2="class WPDBRevocationStub { public $prefix='wp_'; public function prepare($q,...$a){ return $q; } public function get_var($q){ return 1; } }\n$wpdb=new WPDBRevocationStub();\nclass SMC_Security {\n"
if anchor2 not in r: raise SystemExit('round2 runtime stub anchor not found')
r=r.replace(anchor2,stub2,1); runtime.write_text(r)

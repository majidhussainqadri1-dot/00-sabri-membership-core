#!/usr/bin/env python3
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]

def read(rel): return (ROOT/rel).read_text(encoding='utf-8')
def write(rel,text): (ROOT/rel).write_text(text,encoding='utf-8',newline='\n')
def swap(rel,old,new,label):
    text=read(rel); count=text.count(old)
    if count!=1: raise SystemExit(f'{rel}: {label} expected 1 match, found {count}')
    write(rel,text.replace(old,new,1))

workflow='source/sabri-membership-core/includes/class-smc-workflow.php'
security='source/sabri-membership-core/includes/class-smc-security.php'
three='source/sabri-membership-core/includes/class-smc-three-plan.php'

# L01 — recovery codes stay available until the browser/user explicitly acknowledges them.
swap(workflow,"\t\t\t\t'rotate_recovery',\n\t\t\t\t'revoke_session',","\t\t\t\t'rotate_recovery',\n\t\t\t\t'ack_recovery_receipt',\n\t\t\t\t'revoke_session',",'register recovery acknowledgement')
swap(workflow,"\t\tif ( ! is_array( $codes ) || ! self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' ) ) {\n\t\t\treturn array();\n\t\t}\n\t\treturn $codes;","\t\tif ( ! is_array( $codes ) ) { return array(); }\n\t\treturn $codes;",'retain recovery receipt until ack')
swap(workflow,"<?php if ( $receipt ) : ?><section class=\"smc-subpanel\" role=\"status\"><h2><?php esc_html_e( 'One-time recovery codes', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Save these now. They will not be displayed again.', 'sabri-membership-core' ); ?></p><ol><?php foreach ( $receipt as $code ) : ?><li><code><?php echo esc_html( $code ); ?></code></li><?php endforeach; ?></ol></section><?php endif; ?>","<?php if ( $receipt ) : ?><section class=\"smc-subpanel\" role=\"status\"><h2><?php esc_html_e( 'One-time recovery codes', 'sabri-membership-core' ); ?></h2><p><?php esc_html_e( 'Save these now. This protected receipt remains available for five minutes or until you explicitly confirm that it was saved.', 'sabri-membership-core' ); ?></p><ol><?php foreach ( $receipt as $code ) : ?><li><code><?php echo esc_html( $code ); ?></code></li><?php endforeach; ?></ol><form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\"><input type=\"hidden\" name=\"action\" value=\"smc_ack_recovery_receipt\"><?php wp_nonce_field( 'smc_ack_recovery_receipt', 'smc_nonce' ); ?><button class=\"smc-button\"><?php esc_html_e( 'I saved these recovery codes', 'sabri-membership-core' ); ?></button></form></section><?php endif; ?>",'recovery receipt acknowledgement UI')
insert_before="\n\tpublic static function guardian_shortcode() {"
handler=r'''
	public static function handle_ack_recovery_receipt() {
		self::guard_user_action( 'smc_ack_recovery_receipt' );
		$user_id = get_current_user_id();
		$receipt = get_user_meta( $user_id, '_smc_recovery_receipt_v2', true );
		if ( ! is_array( $receipt ) || empty( $receipt['envelope'] ) || absint( $receipt['expires'] ?? 0 ) < time() ) { self::redirect( 'security', 'invalid' ); }
		if ( ! SMC_Security::audit( 'recovery_codes_receipt_acknowledged', $user_id, array( 'receipt_version'=>(int)($receipt['version']??0) ) ) ) { self::redirect( 'security', 'invalid' ); }
		if ( ! self::delete_user_meta_verified( $user_id, '_smc_recovery_receipt_v2' ) ) { self::redirect( 'security', 'invalid' ); }
		self::redirect( 'security', 'challenge' );
	}
'''
text=read(workflow)
if text.count(insert_before)!=1: raise SystemExit('workflow ack handler insertion point missing')
write(workflow,text.replace(insert_before,'\n'+handler+insert_before,1))

# M02 — direct file worker is paused in Safe Mode, not only the HTTP/lifecycle wrapper.
swap(security,"\tpublic static function process_file_jobs() {\n\t\tglobal $wpdb;","\tpublic static function process_file_jobs() {\n\t\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\n\t\tglobal $wpdb;",'safe mode file worker')

# H11 — explicit compensation for WordPress options/roles outside SQL rollback semantics.
text=read(three)
start=text.find("\tpublic static function save_institutional_ai() {")
end=text.find("\n\t}\n}",start)
if start<0 or end<0: raise SystemExit('three-plan save function bounds not found')
end += len("\n\t}")
new_func=r'''	public static function save_institutional_ai() {
		if ( ! current_user_can( 'manage_options' ) || defined( 'SMC_INSTITUTIONAL_AI_USER_ID' ) ) { wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'smc_save_institutional_ai', 'smc_nonce' );
		$id = absint( $_POST['ai_user_id'] ?? 0 );
		$user = get_userdata( $id );
		if ( ! $user || empty( $_POST['confirm'] ) || smc_is_founder( $id ) || user_can( $user, 'manage_options' ) ) { wp_die( esc_html__( 'Select a distinct non-administrator account and confirm the institutional AI safeguards.', 'sabri-membership-core' ), '', array( 'response'=>400 ) ); }
		$previous = smc_institutional_ai_user_id();
		$previous_activated = get_option( 'smc_institutional_ai_activated_at', '' );
		$previous_auto = get_option( 'smc_institutional_ai_low_risk_auto_publish', false );
		$new_auto = ! empty( $_POST['low_risk_auto_publish'] );
		$hold = array( 'operation'=>'institutional_ai_rebind','new_user_id'=>$id,'previous_user_id'=>$previous,'started_at'=>time() );
		update_user_meta( $id, '_smc_membership_effects_hold_v1', $hold );
		if ( $previous && $previous !== $id ) { update_user_meta( $previous, '_smc_membership_effects_hold_v1', $hold ); }
		if ( get_user_meta( $id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { wp_die( esc_html__( 'The institutional identity could not enter its fail-closed reconciliation state.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		update_option( 'smc_institutional_ai_user_id', $id, false );
		if ( ! $previous_activated || $previous !== $id ) { update_option( 'smc_institutional_ai_activated_at', current_time( 'mysql', true ), false ); }
		update_option( 'smc_institutional_ai_low_risk_auto_publish', $new_auto, false );
		$stored = smc_institutional_ai_user_id();
		$audit_ok = $stored === $id && SMC_Security::audit( 'institutional_ai_account_configured', $id, array( 'configured_by'=>get_current_user_id(),'doctor_claim'=>false,'clinical_authority'=>false,'policy_version'=>'CHAT-AI-001-v2.1' ) );
		if ( $stored !== $id || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			update_option( 'smc_institutional_ai_user_id', $previous, false );
			update_option( 'smc_institutional_ai_activated_at', $previous_activated, false );
			update_option( 'smc_institutional_ai_low_risk_auto_publish', $previous_auto, false );
			wp_cache_delete( 'alloptions', 'options' );
			wp_die( esc_html__( 'Institutional AI identity could not be committed with audit evidence; the previous configuration was restored.', 'sabri-membership-core' ), '', array( 'response'=>409 ) );
		}
		$old = $previous && $previous !== $id ? get_userdata( $previous ) : null;
		if ( $old ) { $old->remove_role( 'sabri_institutional_ai_teacher' ); $old->remove_role( 'sabri_institutional_ai_publisher' ); }
		$user->add_role( 'sabri_institutional_ai_teacher' );
		$user->add_role( 'sabri_institutional_ai_publisher' );
		clean_user_cache( $id ); if ( $previous ) { clean_user_cache( $previous ); }
		$new_check = get_userdata( $id ); $old_check = $old ? get_userdata( $previous ) : null;
		$roles_ok = $new_check && in_array( 'sabri_institutional_ai_teacher', (array)$new_check->roles, true ) && in_array( 'sabri_institutional_ai_publisher', (array)$new_check->roles, true );
		if ( $old_check ) { $roles_ok = $roles_ok && ! in_array( 'sabri_institutional_ai_teacher', (array)$old_check->roles, true ) && ! in_array( 'sabri_institutional_ai_publisher', (array)$old_check->roles, true ); }
		if ( ! $roles_ok ) {
			update_option( 'smc_institutional_ai_user_id', $previous, false ); update_option( 'smc_institutional_ai_activated_at', $previous_activated, false ); update_option( 'smc_institutional_ai_low_risk_auto_publish', $previous_auto, false );
			$user->remove_role( 'sabri_institutional_ai_teacher' ); $user->remove_role( 'sabri_institutional_ai_publisher' );
			if ( $old ) { $old->add_role( 'sabri_institutional_ai_teacher' ); $old->add_role( 'sabri_institutional_ai_publisher' ); }
			$compensated = smc_institutional_ai_user_id() === $previous && SMC_Security::audit( 'institutional_ai_configuration_compensated', $id, array( 'reason_code'=>'role_projection_failed','previous_user_id'=>$previous ) );
			if ( $compensated ) { delete_user_meta( $id, '_smc_membership_effects_hold_v1' ); if($previous){delete_user_meta($previous,'_smc_membership_effects_hold_v1');} }
			wp_die( esc_html__( 'Institutional AI role projection failed; the previous configuration was restored and the account remains fail-closed if compensation was incomplete.', 'sabri-membership-core' ), '', array( 'response'=>503 ) );
		}
		delete_user_meta( $id, '_smc_membership_effects_hold_v1' ); if($previous){delete_user_meta($previous,'_smc_membership_effects_hold_v1');}
		wp_safe_redirect( admin_url( 'admin.php?page=smc-institutional-ai&updated=1' ) ); exit;
	}'''
write(three,text[:start]+new_func+text[end:])

print('audit32 final source fixes applied')

<?php
if ( ! defined( 'ABSPATH' ) ) { exit(2); }
global $wpdb;
SMC_Schema_Compat::reconcile_verification_queue_index();
SMC_Installer::maybe_upgrade();
update_option('smc_institutional_repair_cursor',0,false);
$template_user=wp_insert_user(['user_login'=>'cursor_template','user_email'=>'cursor-template@example.test','user_pass'=>'Cursor-Password!9','role'=>'subscriber']);
$template=smc_application($template_user);
$dummy=0;
if($template){$clone=$template;unset($clone['id']);$clone['status']='suspended';$clone['row_version']=1;for($i=0;$i<500;$i++){$clone['user_id']=900000+$i;$clone['created_at']=current_time('mysql',true);$clone['updated_at']=$clone['created_at'];if(1===$wpdb->insert($wpdb->prefix.'smc_applications',$clone)){$dummy++;}}}
$admin=wp_insert_user(['user_login'=>'cursor_admin','user_email'=>'cursor-admin@example.test','user_pass'=>'Cursor-Password!9','role'=>'administrator']);
$target=smc_application($admin);$target_id=(int)$target['id'];
$wpdb->update($wpdb->prefix.'smc_applications',['status'=>'suspended','updated_at'=>current_time('mysql',true)],['id'=>$target_id]);
$audit_ok=SMC_Security::audit('membership_restricted',$admin,['reason_code'=>'age_eligibility_failed']);
$subject=SMC_Security::subject_hash($admin);
$latest=$wpdb->get_row($wpdb->prepare("SELECT id,action,details FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s ORDER BY id DESC LIMIT 10",$subject),ARRAY_A);
$hard=$wpdb->get_row($wpdb->prepare("SELECT id,action,details FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s AND action IN ('membership_restricted','membership_suspended','membership_rejected','membership_appeal_review','membership_erasure_pending','membership_erasure_requested') ORDER BY id DESC LIMIT 1",$subject),ARRAY_A);
$manual=$wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$wpdb->prefix}smc_audit_log WHERE subject_hash=%s AND action IN ('membership_suspended','membership_rejected','membership_appeal_review','membership_erasure_pending','membership_erasure_requested')",$subject));
$rows0=$wpdb->get_results($wpdb->prepare("SELECT id,user_id,status FROM {$wpdb->prefix}smc_applications WHERE status='suspended' AND id>%d ORDER BY id ASC LIMIT 500",0),ARRAY_A);
echo 'dummy='.$dummy.' target_id='.$target_id.' total_suspended_to_target='.(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}smc_applications WHERE status='suspended' AND id<=%d",$target_id))."\n";
echo 'pre_rows='.count($rows0).' first='.(int)($rows0[0]['id']??0).' last='.(int)($rows0[count($rows0)-1]['id']??0).' option='.(int)get_option('smc_institutional_repair_cursor',0)."\n";
echo 'audit_ok='.($audit_ok?'1':'0').' latest='.wp_json_encode($latest).' hard='.wp_json_encode($hard).' manual='.(int)$manual."\n";
$r1=SMC_Lifecycle::repair_institutional_accounts();$c1=(int)get_option('smc_institutional_repair_cursor',0);$s1=smc_application($admin);echo 'after1 repaired='.$r1.' cursor='.$c1.' target_status='.($s1['status']??'')."\n";
$rows1=$wpdb->get_results($wpdb->prepare("SELECT id,user_id,status FROM {$wpdb->prefix}smc_applications WHERE status='suspended' AND id>%d ORDER BY id ASC LIMIT 500",$c1),ARRAY_A);echo 'next_rows='.count($rows1).' first='.(int)($rows1[0]['id']??0).' last='.(int)($rows1[count($rows1)-1]['id']??0)."\n";
$r2=SMC_Lifecycle::repair_institutional_accounts();$c2=(int)get_option('smc_institutional_repair_cursor',0);$s2=smc_application($admin);echo 'after2 repaired='.$r2.' cursor='.$c2.' target_status='.($s2['status']??'')."\n";
exit(0);

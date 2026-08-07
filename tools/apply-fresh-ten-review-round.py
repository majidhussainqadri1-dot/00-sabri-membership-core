#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
runtime=root/'qa/advanced-trust-runtime.php'
s=source.read_text()
old="""\t\t$service = get_user_meta( $user_id, self::SERVICE_IDENTITY_META, true );\n\t\tif ( is_array( $service ) && 'service' === ( $service['kind'] ?? '' ) && ! empty( $service['approved'] ) ) {\n\t\t\treturn array( 'kind' => 'service', 'human' => false, 'doctor' => false, 'owner' => 'file00', 'purpose' => sanitize_key( $service['purpose'] ?? '' ) );\n\t\t}\n\t\treturn array( 'kind' => 'human', 'human' => true, 'doctor' => false, 'owner' => 'file00' );\n"""
new="""\t\t$service = get_user_meta( $user_id, self::SERVICE_IDENTITY_META, true );\n\t\tif ( is_array( $service ) && 'service' === ( $service['kind'] ?? '' ) ) {\n\t\t\treturn array( 'kind' => 'service', 'human' => false, 'doctor' => false, 'owner' => 'file00', 'purpose' => sanitize_key( $service['purpose'] ?? '' ), 'approved' => ! empty( $service['approved'] ) );\n\t\t}\n\t\treturn array( 'kind' => 'human', 'human' => true, 'doctor' => false, 'owner' => 'file00' );\n"""
if old not in s: raise SystemExit('round5 service block not found')
s=s.replace(old,new,1)
old2="""\tpublic static function open_break_glass( $subject_user_id, $actor_id, $purpose ) {\n\t\t$subject_user_id = absint( $subject_user_id );\n\t\t$actor_id = absint( $actor_id );\n\t\tif ( ! $subject_user_id || ! $actor_id || ! self::actor_is_current( $actor_id, 'manage_options', true ) || ! SMC_Security::session_is_verified( $actor_id ) ) {\n"""
new2="""\tpublic static function open_break_glass( $subject_user_id, $actor_id, $purpose ) {\n\t\t$subject_user_id = absint( $subject_user_id );\n\t\t$actor_id = absint( $actor_id );\n\t\t$purpose = sanitize_text_field( $purpose );\n\t\tif ( ! $subject_user_id || ! $actor_id || '' === $purpose || ! self::actor_is_current( $actor_id, 'manage_options', true ) || ! SMC_Security::session_is_verified( $actor_id ) ) {\n"""
if old2 not in s: raise SystemExit('round5 breakglass open block not found')
s=s.replace(old2,new2,1)
s=s.replace("'id' => wp_generate_uuid4(), 'subject_user_id' => $subject_user_id, 'purpose' => sanitize_text_field( $purpose ),","'id' => wp_generate_uuid4(), 'subject_user_id' => $subject_user_id, 'purpose' => $purpose,",1)
old3="""\t\treturn array( 'authorized' => true, 'request_id' => $request_id, 'expires_at' => min( absint( $request['expires_at'] ), time() + 300 ) );\n"""
new3="""\t\treturn array( 'authorized' => true, 'request_id' => $request_id, 'subject' => self::subject_reference( absint( $request['subject_user_id'] ) ), 'purpose' => sanitize_text_field( $request['purpose'] ?? '' ), 'expires_at' => min( absint( $request['expires_at'] ), time() + 300 ) );\n"""
if old3 not in s: raise SystemExit('round5 consume token not found')
s=s.replace(old3,new3,1); source.write_text(s)
r=runtime.read_text()
anchor="t('break glass requires/uses two approvals', is_array($token) && !empty($token['authorized']));\n"
insert=anchor+"t('break glass authority is subject and purpose bound', is_array($token) && $token['subject']==='uuid-7' && $token['purpose']==='founder recovery');\nt('blank break glass purpose is rejected', is_wp_error(SMC_Advanced_Trust_2026::open_break_glass(7,1,'   ')));\n"
if anchor not in r: raise SystemExit('round5 runtime breakglass anchor not found')
r=r.replace(anchor,insert,1)
anchor2="$kind=SMC_Advanced_Trust_2026::subject_kind(99);\n"
insert2="update_user_meta(14,'_smc_service_identity_v1',['kind'=>'service','purpose'=>'integration','approved'=>false]);$disabled_service=SMC_Advanced_Trust_2026::subject_kind(14);t('disabled service identity remains non-human', $disabled_service['kind']==='service' && !$disabled_service['human'] && !$disabled_service['approved']);\n"+anchor2
if anchor2 not in r: raise SystemExit('round5 service test anchor not found')
r=r.replace(anchor2,insert2,1); runtime.write_text(r)

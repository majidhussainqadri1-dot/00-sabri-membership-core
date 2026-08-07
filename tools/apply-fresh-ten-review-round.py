#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php'
hard=root/'qa/advanced-trust-review-hardening-runtime.php'
s=source.read_text()

# R9-D01: File02 assurance must be newer than any File00 revalidation boundary.
old="""\t\t$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;\n\t\t$elevated = $level > (int) $baseline['level'] || ! empty( $claim['passkey_asserted'] ) || ! empty( $claim['hardware_backed'] );\n\t\tif ( $verified_at > time() + 60 || ( $elevated && ( ! $owner_ok || ! $contract_ok || ! $fresh ) ) ) {\n"""
new="""\t\t$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );\n\t\t$fresh = $verified_at > 0 && $verified_at <= time() + 60 && $verified_at >= time() - 5 * MINUTE_IN_SECONDS;\n\t\t$fresh_after_revalidation = 0 === $required_after || $verified_at >= $required_after;\n\t\t$elevated = $level > (int) $baseline['level'] || ! empty( $claim['passkey_asserted'] ) || ! empty( $claim['hardware_backed'] );\n\t\tif ( $verified_at > time() + 60 || ( $elevated && ( ! $owner_ok || ! $contract_ok || ! $fresh || ! $fresh_after_revalidation ) ) ) {\n"""
if old not in s: raise SystemExit('R9 auth freshness anchor missing')
s=s.replace(old,new,1)

# R9-D02: step-up result is not authorization-complete unless membership state itself is operational.
old="""\t\t$satisfied = (int) $profile['identity_assurance_level'] >= $required[0]\n\t\t\t&& (int) $profile['authentication_assurance_level'] >= $required[1]\n\t\t\t&& ( ! $required[2] || ! empty( $profile['hardware_backed'] ) );\n\t\treturn array(\n"""
new="""\t\t$membership_operational = self::protected_actions_allowed( absint( $user_id ) );\n\t\t$satisfied = $membership_operational\n\t\t\t&& (int) $profile['identity_assurance_level'] >= $required[0]\n\t\t\t&& (int) $profile['authentication_assurance_level'] >= $required[1]\n\t\t\t&& ( ! $required[2] || ! empty( $profile['hardware_backed'] ) );\n\t\treturn array(\n"""
if old not in s: raise SystemExit('R9 stepup anchor missing')
s=s.replace(old,new,1)
old="""\t\t\t'current_authentication_level' => (int) $profile['authentication_assurance_level'],\n\t\t\t'satisfied' => (bool) $satisfied,\n"""
new="""\t\t\t'current_authentication_level' => (int) $profile['authentication_assurance_level'],\n\t\t\t'membership_operational' => (bool) $membership_operational,\n\t\t\t'satisfied' => (bool) $satisfied,\n"""
if old not in s: raise SystemExit('R9 stepup return anchor missing')
s=s.replace(old,new,1)

# R9-D02: direct protected gate enforces fresh authentication after a revalidation timestamp.
old="""\t\t$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );\n\t\t$reverification = self::reverification_status( $user_id );\n"""
new="""\t\t$reverification_required = (bool) get_user_meta( $user_id, '_smc_reverification_required', true );\n\t\t$required_after = absint( get_user_meta( $user_id, '_smc_revalidation_required_at', true ) );\n\t\t$auth = self::authentication_assurance( $user_id );\n\t\t$revalidation_current = 0 === $required_after || ( (int) ( $auth['level'] ?? 0 ) >= 2 && absint( $auth['verified_at'] ?? 0 ) >= $required_after );\n\t\t$reverification = self::reverification_status( $user_id );\n"""
if old not in s: raise SystemExit('R9 protected prelude anchor missing')
s=s.replace(old,new,1)
old="""\t\treturn 'clear' === ( $containment['state'] ?? 'unknown' ) && 'active' === ( $continuity['state'] ?? 'unknown' ) && ! $reverification_required && ! $reverification_stale && ! $critical_pending && ! $merge_finalizing;\n"""
new="""\t\treturn 'clear' === ( $containment['state'] ?? 'unknown' ) && 'active' === ( $continuity['state'] ?? 'unknown' ) && $revalidation_current && ! $reverification_required && ! $reverification_stale && ! $critical_pending && ! $merge_finalizing;\n"""
if old not in s: raise SystemExit('R9 protected return anchor missing')
s=s.replace(old,new,1)

# R9-D03: the boolean authorization helper must suspend delegated authority when the principal is not operational.
old="""\tpublic static function has_delegated_scope( $principal_user_id, $scope ) {\n\t\t$scope = sanitize_key( $scope );\n\t\tforeach ( self::delegated_authorities( $principal_user_id ) as $grant ) {\n"""
new="""\tpublic static function has_delegated_scope( $principal_user_id, $scope ) {\n\t\t$principal_user_id = absint( $principal_user_id );\n\t\t$scope = sanitize_key( $scope );\n\t\tif ( $principal_user_id <= 0 || ! self::protected_actions_allowed( $principal_user_id ) ) { return false; }\n\t\tforeach ( self::delegated_authorities( $principal_user_id ) as $grant ) {\n"""
if old not in s: raise SystemExit('R9 delegation helper anchor missing')
s=s.replace(old,new,1)
source.write_text(s)

h=hard.read_text()
anchor="$filters['smc_file02_authentication_assurance_v1']=fn($b,$u)=>['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()];\n$a=SMC_Advanced_Trust_2026::authentication_assurance(7);t('fresh File02 elevation accepted',$a['owner']==='file02'&&$a['level']===3);unset($filters['smc_file02_authentication_assurance_v1']);\n"
insert=anchor+"update_user_meta(8,'_smc_revalidation_required_at',time()+1);$filters['smc_file02_authentication_assurance_v1']=fn($b,$u)=>['owner'=>'file02','contract_version'=>'1.0.0','level'=>3,'method'=>'passkey','passkey_asserted'=>true,'hardware_backed'=>true,'verified_at'=>time()];$a=SMC_Advanced_Trust_2026::authentication_assurance(8);t('File02 assurance older than local revalidation boundary is rejected',$a['owner']==='file00'&&$a['level']===2);unset($filters['smc_file02_authentication_assurance_v1']);delete_user_meta(8,'_smc_revalidation_required_at');\n"
if anchor not in h: raise SystemExit('R9 File02 test anchor missing')
h=h.replace(anchor,insert,1)
anchor2="update_user_meta(7,'_smc_reverification_required',1);t('reverification marker blocks protected actions',!SMC_Advanced_Trust_2026::protected_actions_allowed(7));delete_user_meta(7,'_smc_reverification_required');\n"
insert2=anchor2+"SMC_Security::$verified=false;update_user_meta(9,'_smc_revalidation_required_at',time());t('direct protected gate enforces fresh revalidation authentication',!SMC_Advanced_Trust_2026::protected_actions_allowed(9));$step=SMC_Advanced_Trust_2026::step_up_requirement(9,'default');t('step-up cannot report satisfied for non-operational membership',empty($step['membership_operational'])&&!$step['satisfied']);delete_user_meta(9,'_smc_revalidation_required_at');SMC_Security::$verified=true;\n"
if anchor2 not in h: raise SystemExit('R9 protected test anchor missing')
h=h.replace(anchor2,insert2,1)
anchor3="$fail_meta_key='_smc_delegated_authority_v1';$d=SMC_Advanced_Trust_2026::grant_delegated_authority(7,1,['membership_support'],time()+1000);t('delegation storage failure rejected',is_wp_error($d));$fail_meta_key='';\n"
insert3=anchor3+"$d=SMC_Advanced_Trust_2026::grant_delegated_authority(15,1,['membership_support'],time()+1000);t('active delegated scope is recognized',is_array($d)&&SMC_Advanced_Trust_2026::has_delegated_scope(15,'membership_support'));update_user_meta(15,'_smc_reverification_required',1);t('delegated scope is suspended with protected membership hold',!SMC_Advanced_Trust_2026::has_delegated_scope(15,'membership_support'));delete_user_meta(15,'_smc_reverification_required');\n"
if anchor3 not in h: raise SystemExit('R9 delegation test anchor missing')
h=h.replace(anchor3,insert3,1)
hard.write_text(h)

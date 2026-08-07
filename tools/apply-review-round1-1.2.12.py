#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
path = ROOT / 'source/sabri-membership-core/includes/class-smc-security.php'
s = path.read_text(encoding='utf-8')

old = """\tprivate static function delete_session_token_envelope( $user_id, $token_hash ) {\n\t\t$key = self::session_token_meta_key( $token_hash );\n\t\tdelete_user_meta( absint( $user_id ), $key );\n\t\treturn ! metadata_exists( 'user', absint( $user_id ), $key );\n\t}\n\n\tpublic static function register_session( $user_id, $token, $expiration ) {\n"""
new = """\tprivate static function delete_session_token_envelope( $user_id, $token_hash ) {\n\t\t$key = self::session_token_meta_key( $token_hash );\n\t\tdelete_user_meta( absint( $user_id ), $key );\n\t\treturn ! metadata_exists( 'user', absint( $user_id ), $key );\n\t}\n\n\tprivate static function clear_revalidation_requirement( $user_id ) {\n\t\t$user_id = absint( $user_id );\n\t\tif ( ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' ) ) {\n\t\t\treturn true;\n\t\t}\n\t\tdelete_user_meta( $user_id, '_smc_revalidation_required_at' );\n\t\treturn ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' );\n\t}\n\n\tpublic static function register_session( $user_id, $token, $expiration ) {\n"""
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('clear_revalidation helper insertion point not found')

old = """\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL AND (last_totp_slice IS NULL OR last_totp_slice<%d)\", $now, $slice, $now, (int) $row['id'], $user_id, $hash, $slice ) );\n\t\t$audit_ok = 1 === $updated && self::audit( 'two_factor_passed', $user_id, array( 'session_id' => (int) $row['id'], 'totp_slice' => (int) $slice ) );\n\t\tif ( 1 !== $updated || ! $audit_ok ) {\n"""
new = """\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL AND (last_totp_slice IS NULL OR last_totp_slice<%d)\", $now, $slice, $now, (int) $row['id'], $user_id, $hash, $slice ) );\n\t\t$revalidation_ok = 1 === $updated && self::clear_revalidation_requirement( $user_id );\n\t\t$audit_ok = $revalidation_ok && self::audit( 'two_factor_passed', $user_id, array( 'session_id' => (int) $row['id'], 'totp_slice' => (int) $slice ) );\n\t\tif ( 1 !== $updated || ! $revalidation_ok || ! $audit_ok ) {\n"""
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('TOTP revalidation patch point not found')

old = """\t\t$code_updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL\", $now, (int) $row['id'] ) );\n\t\t$session_updated = $session_id ? $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL\", $now, $now, (int) $session_id, $user_id, $token_hash ) ) : 0;\n\t\t$audit_ok = 1 === $code_updated && 1 === $session_updated && self::audit( 'recovery_code_used', $user_id, array( 'session_id' => (int) $session_id ) );\n\t\tif ( 1 !== $code_updated || 1 !== $session_updated || ! $audit_ok ) {\n"""
new = """\t\t$code_updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL\", $now, (int) $row['id'] ) );\n\t\t$session_updated = $session_id ? $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL\", $now, $now, (int) $session_id, $user_id, $token_hash ) ) : 0;\n\t\t$revalidation_ok = 1 === $code_updated && 1 === $session_updated && self::clear_revalidation_requirement( $user_id );\n\t\t$audit_ok = $revalidation_ok && self::audit( 'recovery_code_used', $user_id, array( 'session_id' => (int) $session_id ) );\n\t\tif ( 1 !== $code_updated || 1 !== $session_updated || ! $revalidation_ok || ! $audit_ok ) {\n"""
if old in s:
    s = s.replace(old, new, 1)
elif new not in s:
    raise SystemExit('recovery-session revalidation patch point not found')

path.write_text(s, encoding='utf-8', newline='\n')
print('Applied File 00 review round 1 security fixes.')

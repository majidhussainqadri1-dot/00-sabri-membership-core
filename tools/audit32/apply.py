#!/usr/bin/env python3
"""Apply File 00 GitHub code-only audit corrective patch set.

This script is intentionally fail-fast: every replacement must match the exact
3a84c32 baseline once.  It is kept in the repository as a reproducible receipt
for the corrective branch, not as runtime code.
"""
from __future__ import annotations
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "source" / "sabri-membership-core"


def p(rel: str) -> Path:
    return ROOT / rel


def read(rel: str) -> str:
    return p(rel).read_text(encoding="utf-8")


def write(rel: str, text: str) -> None:
    p(rel).write_text(text, encoding="utf-8", newline="\n")


def replace_once(rel: str, old: str, new: str) -> None:
    text = read(rel)
    n = text.count(old)
    if n != 1:
        raise SystemExit(f"{rel}: expected one exact match, found {n}: {old[:100]!r}")
    write(rel, text.replace(old, new, 1))


def regex_once(rel: str, pattern: str, repl: str, flags: int = re.S) -> None:
    text = read(rel)
    out, n = re.subn(pattern, repl, text, count=1, flags=flags)
    if n != 1:
        raise SystemExit(f"{rel}: expected one regex match, found {n}: {pattern[:120]!r}")
    write(rel, out)


def replace_function(rel: str, visibility: str, name: str, next_visibility: str, next_name: str, body: str) -> None:
    pattern = rf"\n\t{visibility} static function {re.escape(name)}\([^\n]*\) \{{.*?\n\t\}}\n\n\t{next_visibility} static function {re.escape(next_name)}"
    repl = "\n" + body.rstrip() + f"\n\n\t{next_visibility} static function {next_name}"
    regex_once(rel, pattern, repl)


# ---------------------------------------------------------------------------
# Release identity — corrective candidate, not a production-complete claim.
# ---------------------------------------------------------------------------
replace_once(
    "source/sabri-membership-core/sabri-membership-core.php",
    " * Version: 1.2.18",
    " * Version: 1.2.19",
)
replace_once(
    "source/sabri-membership-core/sabri-membership-core.php",
    "define( 'SMC_VERSION', '1.2.18' );\ndefine( 'SMC_DB_VERSION', '1.3.0' );\ndefine( 'SMC_CONTRACT_VERSION', '1.2.0' );",
    "define( 'SMC_VERSION', '1.2.19' );\ndefine( 'SMC_DB_VERSION', '1.4.0' );\ndefine( 'SMC_CONTRACT_VERSION', '1.2.1' );",
)
replace_once("package.json", '"version": "1.2.18"', '"version": "1.2.19"')
replace_once(
    "package.json",
    'python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.18.zip',
    'python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.19.zip',
)

# ---------------------------------------------------------------------------
# Installer/schema: immutable approval generation, guardian generations,
# global factor replay state, serialized audit tail and InnoDB enforcement.
# ---------------------------------------------------------------------------
installer = "source/sabri-membership-core/includes/class-smc-installer.php"
replace_once(
    installer,
    "\t\t'smc_auth_sessions',\n\t\t'smc_recovery_codes',",
    "\t\t'smc_auth_sessions',\n\t\t'smc_mfa_factor_state',\n\t\t'smc_recovery_codes',",
)
replace_once(
    installer,
    "\t\t'smc_audit_log',\n\t\t'smc_migrations',",
    "\t\t'smc_audit_log',\n\t\t'smc_audit_tail',\n\t\t'smc_migrations',",
)
replace_once(installer, "$c = $wpdb->get_charset_collate();", "$c = $wpdb->get_charset_collate() . ' ENGINE=InnoDB';")
replace_once(
    installer,
    "\t\t\tuser_id bigint(20) unsigned NOT NULL,\n\t\t\tguardian_name_enc longtext NOT NULL,",
    "\t\t\tuser_id bigint(20) unsigned NOT NULL,\n\t\t\tgeneration bigint(20) unsigned NOT NULL DEFAULT 1,\n\t\t\tis_current tinyint(1) NOT NULL DEFAULT 1,\n\t\t\tguardian_name_enc longtext NOT NULL,",
)
replace_once(
    installer,
    "\t\t\tPRIMARY KEY  (id),\n\t\t\tUNIQUE KEY user_id (user_id),\n\t\t\tKEY guardian_email_hash (guardian_email_hash),\n\t\t\tKEY status (status)\n\t\t) {$c};\";",
    "\t\t\tPRIMARY KEY  (id),\n\t\t\tUNIQUE KEY user_generation (user_id,generation),\n\t\t\tKEY user_current (user_id,is_current),\n\t\t\tKEY guardian_email_hash (guardian_email_hash),\n\t\t\tKEY status (status)\n\t\t) {$c};\";",
)
replace_once(
    installer,
    "\t\t\treviewer_note longtext NULL,\n\t\t\tapplicant_version bigint(20) unsigned NOT NULL DEFAULT 1,\n\t\t\trow_version bigint(20) unsigned NOT NULL DEFAULT 1,",
    "\t\t\treviewer_note longtext NULL,\n\t\t\tapplicant_version bigint(20) unsigned NOT NULL DEFAULT 1,\n\t\t\tapproval_generation char(36) NULL,\n\t\t\tapproval_snapshot_hash char(64) NULL,\n\t\t\trow_version bigint(20) unsigned NOT NULL DEFAULT 1,",
)
replace_once(
    installer,
    "\t\t\treviewer_id bigint(20) unsigned NOT NULL,\n\t\t\tdecision varchar(20) NOT NULL,",
    "\t\t\treviewer_id bigint(20) unsigned NOT NULL,\n\t\t\tapproval_generation char(36) NOT NULL DEFAULT '',\n\t\t\tdecision varchar(20) NOT NULL,",
)
replace_once(
    installer,
    "\t\t\tPRIMARY KEY  (id),\n\t\t\tUNIQUE KEY request_reviewer (request_id,reviewer_id),\n\t\t\tKEY decision (request_id,decision)\n\t\t) {$c};\";",
    "\t\t\tPRIMARY KEY  (id),\n\t\t\tUNIQUE KEY request_generation_reviewer (request_id,approval_generation,reviewer_id),\n\t\t\tKEY decision (request_id,approval_generation,decision)\n\t\t) {$c};\";",
)
replace_once(
    installer,
    "\t\t$sql[] = \"CREATE TABLE {$p}smc_recovery_codes (",
    "\t\t$sql[] = \"CREATE TABLE {$p}smc_mfa_factor_state (\n\t\t\tuser_id bigint(20) unsigned NOT NULL,\n\t\t\tlast_totp_slice bigint(20) NULL,\n\t\t\tupdated_at datetime NOT NULL,\n\t\t\tPRIMARY KEY  (user_id)\n\t\t) {$c};\";\n\n\t\t$sql[] = \"CREATE TABLE {$p}smc_recovery_codes (",
)
replace_once(
    installer,
    "\t\t$sql[] = \"CREATE TABLE {$p}smc_migrations (",
    "\t\t$sql[] = \"CREATE TABLE {$p}smc_audit_tail (\n\t\t\tid tinyint(1) unsigned NOT NULL,\n\t\t\trow_hash char(64) NOT NULL DEFAULT '',\n\t\t\tupdated_at datetime NOT NULL,\n\t\t\tPRIMARY KEY  (id)\n\t\t) {$c};\";\n\n\t\t$sql[] = \"CREATE TABLE {$p}smc_migrations (",
)
replace_once(
    installer,
    "\t\tforeach ( self::$table_suffixes as $suffix ) {\n\t\t\t$table = $wpdb->prefix . $suffix;\n\t\t\t$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );\n\t\t\tif ( $found !== $table ) {\n\t\t\t\tthrow new RuntimeException( 'Required membership table is missing: ' . $suffix );\n\t\t\t}\n\t\t}\n\t}",
    "\t\tforeach ( self::$table_suffixes as $suffix ) {\n\t\t\t$table = $wpdb->prefix . $suffix;\n\t\t\t$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );\n\t\t\tif ( $found !== $table ) {\n\t\t\t\tthrow new RuntimeException( 'Required membership table is missing: ' . $suffix );\n\t\t\t}\n\t\t\t$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );\n\t\t\tif ( 'InnoDB' !== (string) $engine ) {\n\t\t\t\tthrow new RuntimeException( 'File 00 requires InnoDB transactional tables: ' . $suffix );\n\t\t\t}\n\t\t}\n\n\t\t// dbDelta does not reliably remove superseded unique indexes. Remove the\n\t\t// pre-1.4.0 guardian/vote uniqueness only after the replacement indexes exist.\n\t\t$guardian_table = $p . 'smc_guardian_consents';\n\t\t$legacy_guardian_unique = $wpdb->get_var( \"SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='\" . esc_sql( $guardian_table ) . \"' AND INDEX_NAME='user_id' AND NON_UNIQUE=0 LIMIT 1\" );\n\t\tif ( $legacy_guardian_unique ) {\n\t\t\t$wpdb->query( \"ALTER TABLE {$guardian_table} DROP INDEX user_id\" );\n\t\t}\n\t\t$vote_table = $p . 'smc_approval_votes';\n\t\t$legacy_vote_unique = $wpdb->get_var( \"SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='\" . esc_sql( $vote_table ) . \"' AND INDEX_NAME='request_reviewer' AND NON_UNIQUE=0 LIMIT 1\" );\n\t\tif ( $legacy_vote_unique ) {\n\t\t\t$wpdb->query( \"ALTER TABLE {$vote_table} DROP INDEX request_reviewer\" );\n\t\t}\n\t\t$wpdb->query( \"UPDATE {$guardian_table} SET generation=id,is_current=1 WHERE generation<=0\" );\n\t\t$tail_hash = (string) $wpdb->get_var( \"SELECT row_hash FROM {$p}smc_audit_log ORDER BY id DESC LIMIT 1\" );\n\t\t$now = current_time( 'mysql', true );\n\t\t$tail_ok = $wpdb->query( $wpdb->prepare( \"INSERT INTO {$p}smc_audit_tail (id,row_hash,updated_at) VALUES (1,%s,%s) ON DUPLICATE KEY UPDATE id=id\", $tail_hash, $now ) );\n\t\tif ( false === $tail_ok ) {\n\t\t\tthrow new RuntimeException( 'Audit tail serializer could not be initialized.' );\n\t\t}\n\t\t$started = false !== $wpdb->query( 'START TRANSACTION' );\n\t\t$rolled = $started && false !== $wpdb->query( 'ROLLBACK' );\n\t\tif ( ! $started || ! $rolled ) {\n\t\t\tthrow new RuntimeException( 'The database does not provide the required transaction semantics.' );\n\t\t}\n\t}",
)

# ---------------------------------------------------------------------------
# Security: versioned envelope keyring, global TOTP replay guard, durable audit
# serialization and public session-envelope cleanup primitive.
# ---------------------------------------------------------------------------
security = "source/sabri-membership-core/includes/class-smc-security.php"
regex_once(
    security,
    r"\n\tpublic static function key_ready\(\) \{.*?\n\tpublic static function blind_index\( \$value, \$purpose \) \{\n\t\t\$key = self::key\(\);\n\t\treturn is_wp_error\( \$key \) \? \$key : hash_hmac\( 'sha256', sanitize_key\( \$purpose \) \. '\\|' \. mb_strtolower\( trim\( \(string\) \$value \), 'UTF-8' \), \$key \);\n\t\}",
    r'''
	private static function master_material( $raw ) {
		$raw = trim( (string) $raw );
		if ( 0 === strpos( $raw, 'base64:' ) ) {
			$decoded = base64_decode( substr( $raw, 7 ), true );
			return is_string( $decoded ) && 32 === strlen( $decoded ) ? $decoded : false;
		}
		if ( 0 === strpos( $raw, 'hex:' ) && preg_match( '/^[a-f0-9]{64}$/i', substr( $raw, 4 ) ) ) {
			$decoded = hex2bin( substr( $raw, 4 ) );
			return is_string( $decoded ) && 32 === strlen( $decoded ) ? $decoded : false;
		}
		// 1.2.x compatibility. New deployments should use base64:/hex: random 256-bit material.
		return strlen( $raw ) >= 32 ? $raw : false;
	}

	public static function key_ready() {
		return defined( 'SMC_MASTER_KEY' ) && false !== self::master_material( SMC_MASTER_KEY ) && '' !== self::key_id();
	}

	/** Legacy purpose/index/audit key retained until the explicit index/audit migration completes. */
	private static function key() {
		if ( ! defined( 'SMC_MASTER_KEY' ) || false === ( $material = self::master_material( SMC_MASTER_KEY ) ) ) {
			return new WP_Error( 'smc_key_missing', __( 'SMC_MASTER_KEY must contain at least 256 bits of configured key material.', 'sabri-membership-core' ) );
		}
		$legacy_salt = defined( 'SMC_LEGACY_AUTH_SALT' ) && is_string( SMC_LEGACY_AUTH_SALT ) && '' !== SMC_LEGACY_AUTH_SALT
			? SMC_LEGACY_AUTH_SALT
			: wp_salt( 'auth' );
		return hash_hkdf( 'sha256', $material, 32, 'sabri-membership-core:v2', $legacy_salt );
	}

	public static function key_id() {
		if ( ! defined( 'SMC_MASTER_KEY_ID' ) || ! is_string( SMC_MASTER_KEY_ID ) ) {
			return '';
		}
		$key_id = trim( SMC_MASTER_KEY_ID );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ? $key_id : '';
	}

	private static function envelope_keyring() {
		$ring = array();
		if ( defined( 'SMC_MASTER_KEY' ) && false !== ( $material = self::master_material( SMC_MASTER_KEY ) ) && '' !== self::key_id() ) {
			$ring[ self::key_id() ] = array( 'material' => $material, 'legacy_auth_salt' => defined( 'SMC_LEGACY_AUTH_SALT' ) ? (string) SMC_LEGACY_AUTH_SALT : wp_salt( 'auth' ) );
		}
		$extra = apply_filters( 'smc_encryption_keyring_v1', array() );
		foreach ( (array) $extra as $kid => $entry ) {
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', (string) $kid ) || ! is_array( $entry ) ) { continue; }
			$material = self::master_material( $entry['material'] ?? '' );
			if ( false === $material ) { continue; }
			$ring[ (string) $kid ] = array( 'material' => $material, 'legacy_auth_salt' => (string) ( $entry['legacy_auth_salt'] ?? '' ) );
		}
		return $ring;
	}

	private static function envelope_key( $kid, $version, $legacy_stored_kid = '' ) {
		$ring = self::envelope_keyring();
		if ( 3 === (int) $version ) {
			if ( ! isset( $ring[ $kid ] ) ) { return new WP_Error( 'smc_key_unknown', __( 'The encrypted record references an unavailable key generation.', 'sabri-membership-core' ) ); }
			return hash_hkdf( 'sha256', $ring[ $kid ]['material'], 32, 'sabri-membership-core:envelope:v3', '' );
		}
		if ( 2 === (int) $version ) {
			foreach ( $ring as $ring_kid => $entry ) {
				$salt = '' !== $entry['legacy_auth_salt'] ? $entry['legacy_auth_salt'] : wp_salt( 'auth' );
				$key = hash_hkdf( 'sha256', $entry['material'], 32, 'sabri-membership-core:v2', $salt );
				$derived_kid = substr( hash( 'sha256', $key ), 0, 16 );
				if ( hash_equals( (string) $ring_kid, (string) $legacy_stored_kid ) || hash_equals( $derived_kid, (string) $legacy_stored_kid ) ) { return $key; }
			}
		}
		return new WP_Error( 'smc_key_unknown', __( 'The encrypted record references an unavailable key generation.', 'sabri-membership-core' ) );
	}

	private static function canonical_json( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonical_json_value( $item );
			}
		}
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function canonical_json_value( $value ) {
		if ( is_array( $value ) ) {
			$json = self::canonical_json( $value );
			return json_decode( $json, true );
		}
		return $value;
	}

	public static function encrypt( $plaintext, $purpose, $context = array() ) {
		$key_id = self::key_id();
		$key = self::envelope_key( $key_id, 3 );
		if ( is_wp_error( $key ) ) { return $key; }
		$nonce = random_bytes( 12 );
		$tag = '';
		$aad = self::canonical_json( array( 'v' => 3, 'kid' => $key_id, 'purpose' => sanitize_key( $purpose ), 'context' => $context ) );
		$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16 );
		if ( false === $cipher ) { return new WP_Error( 'smc_encrypt', __( 'Sensitive data could not be encrypted.', 'sabri-membership-core' ) ); }
		return self::ENVELOPE . '.' . base64_encode( $aad ) . '.' . base64_encode( $nonce ) . '.' . base64_encode( $tag ) . '.' . base64_encode( $cipher );
	}

	public static function decrypt( $envelope, $purpose, $context = array() ) {
		$parts = explode( '.', (string) $envelope, 5 );
		if ( 5 !== count( $parts ) || self::ENVELOPE !== $parts[0] ) { return new WP_Error( 'smc_envelope', __( 'Encrypted data uses an unsupported envelope.', 'sabri-membership-core' ) ); }
		$aad = base64_decode( $parts[1], true ); $nonce = base64_decode( $parts[2], true ); $tag = base64_decode( $parts[3], true ); $data = base64_decode( $parts[4], true );
		if ( false === $aad || false === $nonce || false === $tag || false === $data ) { return new WP_Error( 'smc_envelope', __( 'Encrypted data is malformed.', 'sabri-membership-core' ) ); }
		$aad_data = json_decode( $aad, true );
		if ( ! is_array( $aad_data ) ) { return new WP_Error( 'smc_context', __( 'Encrypted data has malformed authenticated context.', 'sabri-membership-core' ) ); }
		$version = (int) ( $aad_data['v'] ?? 0 );
		$stored_kid = (string) ( $aad_data['kid'] ?? '' );
		$expected_context = self::canonical_json( $context );
		$stored_context = self::canonical_json( $aad_data['context'] ?? null );
		if ( ! in_array( $version, array( 2, 3 ), true ) || ! hash_equals( sanitize_key( $purpose ), sanitize_key( $aad_data['purpose'] ?? '' ) ) || ! hash_equals( $expected_context, $stored_context ) ) {
			return new WP_Error( 'smc_context', __( 'Encrypted data does not match its authenticated context.', 'sabri-membership-core' ) );
		}
		$key = self::envelope_key( $stored_kid, $version, $stored_kid );
		if ( is_wp_error( $key ) ) { return $key; }
		$plain = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
		return false === $plain ? new WP_Error( 'smc_authentication', __( 'Encrypted data authentication failed.', 'sabri-membership-core' ) ) : $plain;
	}

	public static function blind_index( $value, $purpose ) {
		$key = self::key();
		return is_wp_error( $key ) ? $key : hash_hmac( 'sha256', sanitize_key( $purpose ) . '|' . mb_strtolower( trim( (string) $value ), 'UTF-8' ), $key );
	}'''
)
replace_once(
    security,
    "\tprivate static function delete_session_token_envelope( $user_id, $token_hash ) {\n\t\t$key = self::session_token_meta_key( $token_hash );\n\t\tdelete_user_meta( absint( $user_id ), $key );\n\t\treturn ! metadata_exists( 'user', absint( $user_id ), $key );\n\t}",
    "\tpublic static function delete_session_token_envelope( $user_id, $token_hash ) {\n\t\t$key = self::session_token_meta_key( $token_hash );\n\t\tdelete_user_meta( absint( $user_id ), $key );\n\t\treturn ! metadata_exists( 'user', absint( $user_id ), $key );\n\t}\n\n\tpublic static function sweep_session_token_envelopes( $user_id, $live_hashes = array() ) {\n\t\t$live = array_fill_keys( array_map( 'strtolower', array_filter( (array) $live_hashes ) ), true );\n\t\t$ok = true;\n\t\tforeach ( array_keys( get_user_meta( absint( $user_id ) ) ) as $meta_key ) {\n\t\t\tif ( 0 !== strpos( $meta_key, '_smc_session_token_' ) ) { continue; }\n\t\t\t$short = substr( $meta_key, strlen( '_smc_session_token_' ) );\n\t\t\t$matched = false;\n\t\t\tforeach ( array_keys( $live ) as $hash ) { if ( hash_equals( substr( $hash, 0, 40 ), $short ) ) { $matched = true; break; } }\n\t\t\tif ( ! $matched ) { delete_user_meta( absint( $user_id ), $meta_key ); $ok = $ok && ! metadata_exists( 'user', absint( $user_id ), $meta_key ); }\n\t\t}\n\t\treturn $ok;\n\t}",
)
# Global per-factor TOTP slice lock/CAS.
replace_once(
    security,
    "\t\t$wpdb->query( 'START TRANSACTION' );\n\t\t$row = $wpdb->get_row( $wpdb->prepare( \"SELECT id,last_totp_slice FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE\", $user_id, $hash ), ARRAY_A );\n\t\tif ( ! $row || ( null !== $row['last_totp_slice'] && (int) $row['last_totp_slice'] >= (int) $slice ) ) {\n\t\t\t$wpdb->query( 'ROLLBACK' );\n\t\t\treturn new WP_Error( 'smc_totp_replay', __( 'This verification code was already used for the current session.', 'sabri-membership-core' ) );\n\t\t}\n\t\t$now = current_time( 'mysql', true );\n\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL AND (last_totp_slice IS NULL OR last_totp_slice<%d)\", $now, $slice, $now, (int) $row['id'], $user_id, $hash, $slice ) );\n\t\t$revalidation_ok = 1 === $updated && self::clear_revalidation_requirement( $user_id );",
    "\t\t$wpdb->query( 'START TRANSACTION' );\n\t\t$row = $wpdb->get_row( $wpdb->prepare( \"SELECT id,last_totp_slice FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE\", $user_id, $hash ), ARRAY_A );\n\t\t$factor = $wpdb->get_row( $wpdb->prepare( \"SELECT user_id,last_totp_slice FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d LIMIT 1 FOR UPDATE\", $user_id ), ARRAY_A );\n\t\t$global_last = $factor && null !== $factor['last_totp_slice'] ? (int) $factor['last_totp_slice'] : null;\n\t\tif ( ! $row || ( null !== $global_last && $global_last >= (int) $slice ) ) {\n\t\t\t$wpdb->query( 'ROLLBACK' );\n\t\t\treturn new WP_Error( 'smc_totp_replay', __( 'This verification code was already used for this authenticator factor.', 'sabri-membership-core' ) );\n\t\t}\n\t\t$now = current_time( 'mysql', true );\n\t\t$factor_updated = $wpdb->query( $wpdb->prepare( \"INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=IF(last_totp_slice IS NULL OR last_totp_slice<VALUES(last_totp_slice),VALUES(last_totp_slice),last_totp_slice),updated_at=IF(last_totp_slice IS NULL OR last_totp_slice<VALUES(last_totp_slice),VALUES(updated_at),updated_at)\", $user_id, $slice, $now ) );\n\t\t$updated = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL\", $now, $slice, $now, (int) $row['id'], $user_id, $hash ) );\n\t\t$revalidation_ok = false !== $factor_updated && 1 === $updated && self::clear_revalidation_requirement( $user_id );",
)
# Audit serialization; tail row lock stays held until caller transaction ends.
regex_once(
    security,
    r"\n\tpublic static function audit\( \$action, \$subject_user_id = 0, \$details = array\(\) \) \{.*?\n\t\}\n\}",
    r'''
	public static function audit( $action, $subject_user_id = 0, $details = array() ) {
		global $wpdb;
		$key = self::key();
		if ( is_wp_error( $key ) ) { return false; }
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
		$ok = false;
		try {
			$tail = $wpdb->get_row( "SELECT id,row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1 FOR UPDATE", ARRAY_A );
			if ( ! $tail ) {
				$last = (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 1" );
				$now = current_time( 'mysql', true );
				if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_audit_tail (id,row_hash,updated_at) VALUES (1,%s,%s)", $last, $now ) ) ) { throw new RuntimeException( 'audit_tail_init' ); }
				$tail = $wpdb->get_row( "SELECT id,row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1 FOR UPDATE", ARRAY_A );
			}
			$previous = (string) ( $tail['row_hash'] ?? '' );
			$actual_last = (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 1" );
			if ( ! hash_equals( $actual_last, $previous ) ) { throw new RuntimeException( 'audit_tail_mismatch' ); }
			$created = current_time( 'mysql', true );
			$record = array(
				'actor_id' => get_current_user_id(),
				'subject_hash' => $subject_user_id ? self::subject_hash( $subject_user_id ) : null,
				'action' => sanitize_key( $action ),
				'details' => self::canonical_json( self::minimize_audit_details( $details ) ),
				'previous_hash' => $previous,
				'created_at' => $created,
			);
			$record['row_hash'] = hash_hmac( 'sha256', self::canonical_json( $record ), $key );
			if ( 1 !== $wpdb->insert( $wpdb->prefix . 'smc_audit_log', $record, array( '%d','%s','%s','%s','%s','%s','%s' ) ) ) { throw new RuntimeException( 'audit_insert' ); }
			$audit_id = (int) $wpdb->insert_id;
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_audit_tail SET row_hash=%s,updated_at=%s WHERE id=1 AND row_hash=%s", $record['row_hash'], $created, $previous ) ) ) { throw new RuntimeException( 'audit_tail_update' ); }
			if ( class_exists( 'SMC_Events' ) && ! SMC_Events::from_audit( $record['action'], $subject_user_id, self::minimize_audit_details( $details ), $audit_id ) ) { throw new RuntimeException( 'audit_event' ); }
			$ok = true;
			if ( $owns_transaction && false === $wpdb->query( 'COMMIT' ) ) { $ok = false; }
		} catch ( Throwable $error ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			$ok = false;
		}
		return $ok;
	}

	private static function minimize_audit_details( $details ) {
		$details = is_array( $details ) ? $details : array();
		$deny = array( 'name','legal_name','email','phone','address','note','reviewer_note','conflict_note','reason','purpose' );
		$out = array();
		foreach ( $details as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $deny, true ) ) {
				$out[ $key . '_digest' ] = hash( 'sha256', (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
				continue;
			}
			if ( is_array( $value ) ) { $out[ $key ] = array_slice( $value, 0, 20 ); }
			elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { $out[ $key ] = $value; }
			else { $out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 190 ); }
		}
		return $out;
	}
}'''
)
# Tail verification at the end of full-chain verification.
replace_once(
    security,
    "\t\treturn array( 'valid' => true, 'checked' => $checked, 'failed_id' => 0, 'reason' => '' );\n\t}\n\n\tpublic static function audit",
    "\t\tif ( ! $maximum ) {\n\t\t\t$tail = (string) $wpdb->get_var( \"SELECT row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1\" );\n\t\t\tif ( ! hash_equals( $previous, $tail ) ) { return array( 'valid' => false, 'checked' => $checked, 'failed_id' => $cursor, 'reason' => 'tail_hash_mismatch' ); }\n\t\t}\n\t\treturn array( 'valid' => true, 'checked' => $checked, 'failed_id' => 0, 'reason' => '' );\n\t}\n\n\tpublic static function audit",
)

# ---------------------------------------------------------------------------
# Canonical membership state: institutional AI and post-commit side-effect hold
# are always fail-closed through one predicate.
# ---------------------------------------------------------------------------
functions = "source/sabri-membership-core/includes/functions.php"
replace_once(
    functions,
    "\t$erasure_lock = $user_id ? smc_privacy_erasure_lock( $user_id ) : false;\n\n\tif ( $erasure_lock ) {",
    "\t$erasure_lock = $user_id ? smc_privacy_erasure_lock( $user_id ) : false;\n\t$effects_hold = $user_id ? get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) : false;\n\n\tif ( $effects_hold ) {\n\t\treturn array( 'contract_version' => SMC_CONTRACT_VERSION, 'user_id' => $user_id, 'application_exists' => (bool) $row, 'application_status' => $status, 'status' => 'effects_reconciliation', 'membership_type' => $type, 'institutional_account' => (bool) $institutional, 'account_class' => $account_class, 'approved' => false );\n\t}\n\n\tif ( $erasure_lock ) {",
)
replace_once(
    functions,
    "function smc_is_institutional_ai( $user_id ) {\n\treturn $user_id > 0 && absint( $user_id ) === smc_institutional_ai_user_id();\n}\n",
    "function smc_is_institutional_ai( $user_id ) {\n\treturn $user_id > 0 && absint( $user_id ) === smc_institutional_ai_user_id();\n}\n\nfunction smc_is_institutional_account( $user_id ) {\n\t$user_id = absint( $user_id );\n\tif ( ! $user_id ) { return false; }\n\tif ( smc_is_founder( $user_id ) || smc_is_institutional_ai( $user_id ) ) { return true; }\n\t$user = get_userdata( $user_id );\n\treturn $user && user_can( $user, 'manage_options' );\n}\n",
)

# ---------------------------------------------------------------------------
# Contracts: database role truth can be committed independently of WP role
# side effects; effective profile authorization never reads raw allcaps.
# ---------------------------------------------------------------------------
contracts = "source/sabri-membership-core/includes/class-smc-contracts.php"
replace_once(
    contracts,
    "public static function approve_requested_roles( $user_id, $application_version, $actor_id ) {",
    "public static function approve_requested_roles( $user_id, $application_version, $actor_id, $sync = true ) {",
)
replace_once(
    contracts,
    "\t\treturn self::sync_wordpress_roles( $user_id );\n\t}\n\n\tpublic static function set_all_roles_pending( $user_id, $application_version = 1 ) {",
    "\t\treturn $sync ? self::sync_wordpress_roles( $user_id ) : true;\n\t}\n\n\tpublic static function set_all_roles_pending( $user_id, $application_version = 1, $sync = true ) {",
)
# The second identical return is the set_all_roles_pending end.
text = read(contracts)
needle = "\t\treturn self::sync_wordpress_roles( $user_id );\n\t}\n\n\t/** Backward-compatible single-role mutation"
if text.count(needle) != 1:
    raise SystemExit("contracts: set_all_roles_pending tail not found")
write(contracts, text.replace(needle, "\t\treturn $sync ? self::sync_wordpress_roles( $user_id ) : true;\n\t}\n\n\t/** Backward-compatible single-role mutation", 1))
replace_once(
    contracts,
    "\t\t$viewer = get_userdata( absint( $viewer_user_id ) );\n\t\tif ( absint( $profile_user_id ) === absint( $viewer_user_id ) || ( $viewer && ( ! empty( $viewer->allcaps['smc_review_verification'] ) || ! empty( $viewer->allcaps['manage_options'] ) ) ) ) {\n\t\t\treturn true;\n\t\t}",
    "\t\t$viewer = get_userdata( absint( $viewer_user_id ) );\n\t\t$privileged = $viewer && ( user_can( $viewer, 'smc_review_verification' ) || user_can( $viewer, 'manage_options' ) );\n\t\tif ( absint( $profile_user_id ) === absint( $viewer_user_id ) ) { return true; }\n\t\tif ( $privileged && absint( $viewer_user_id ) === get_current_user_id() && SMC_Security::session_is_verified( absint( $viewer_user_id ) ) && empty( SMC_Authorization::is_hard_blocked( absint( $viewer_user_id ) ) ) ) { return true; }",
)

# ---------------------------------------------------------------------------
# Lifecycle: canonical institutional predicate, jurisdiction age, request/app
# synchronization, central Safe Mode pause and session-envelope cleanup.
# ---------------------------------------------------------------------------
lifecycle = "source/sabri-membership-core/includes/class-smc-lifecycle.php"
replace_once(
    lifecycle,
    "\tpublic static function daily() {\n\t\tself::recheck_ages();",
    "\tpublic static function daily() {\n\t\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\n\t\tself::recheck_ages();",
)
replace_once(
    lifecycle,
    "\tprivate static function is_institutional_user( $user_id ) {\n\t\t$user = get_userdata( absint( $user_id ) );\n\t\treturn $user && ( smc_is_founder( $user_id ) || user_can( $user, 'manage_options' ) );\n\t}",
    "\tprivate static function is_institutional_user( $user_id ) {\n\t\treturn function_exists( 'smc_is_institutional_account' ) && smc_is_institutional_account( absint( $user_id ) );\n\t}",
)
replace_once(
    lifecycle,
    "$minimum = smc_minimum_age_for_gender( $app['gender'] );",
    "$minimum = smc_effective_minimum_age( $app['gender'], $app['residence_country'] ?? '' );",
)
# Synchronize verification request in restrict transaction, then perform WP effects post-commit under a fail-closed hold.
regex_once(
    lifecycle,
    r"\n\tprivate static function restrict\( \$app, \$status, \$reason \) \{.*?\n\t\}\n\n\tprivate static function cleanup_database",
    r'''
	private static function restrict( $app, $status, $reason ) {
		global $wpdb;
		$user_id = absint( $app['user_id'] );
		$now = current_time( 'mysql', true );
		$hold = array( 'operation' => 'restrict', 'target_status' => sanitize_key( $status ), 'reason' => sanitize_key( $reason ), 'started_at' => time() );
		update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );
		if ( get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { return false; }
		$wpdb->query( 'START TRANSACTION' );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_applications WHERE id=%d AND user_id=%d LIMIT 1 FOR UPDATE", (int) $app['id'], $user_id ), ARRAY_A );
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		if ( ! $current || (int) $current['row_version'] !== (int) $app['row_version'] ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_applications SET status=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d", $status, $now, (int) $app['id'], (int) $app['row_version'] ) );
		$request_ok = true;
		if ( $request ) {
			$request_ok = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status=%s,queue_type='expiry',assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,reason_code='security_restriction',reviewer_note=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d", $status, sanitize_text_field( $reason ), $now, (int) $request['id'], (int) $request['row_version'] ) );
		}
		$roles_db_ok = 1 === $updated && $request_ok && SMC_Contracts::set_all_roles_pending( $user_id, (int) $app['row_version'] + 1, false );
		$audit_ok = $roles_db_ok && SMC_Security::audit( 'membership_restricted', $user_id, array( 'reason_code' => sanitize_key( $reason ), 'status' => sanitize_key( $status ) ) );
		if ( ! $roles_db_ok || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); return false; }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return false; }
		$roles_wp_ok = SMC_Contracts::sync_wordpress_roles( $user_id );
		$sessions_ok = $roles_wp_ok && SMC_Security::revoke_all_sessions( $user_id, $reason );
		if ( ! $roles_wp_ok || ! $sessions_ok ) {
			if ( class_exists( 'SMC_Completion' ) ) { SMC_Completion::queue_effects_repair( $user_id, 'restrict', $status, $reason ); }
			return false;
		}
		delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );
		return ! metadata_exists( 'user', $user_id, '_smc_membership_effects_hold_v1' );
	}

	private static function cleanup_database'''
)
# Replace auth-session bulk delete with envelope-aware cleanup.
replace_once(
    lifecycle,
    "\t\t$wpdb->query( \"DELETE FROM {$wpdb->prefix}smc_auth_sessions WHERE (revoked_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()) AND created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)\" );",
    "\t\t$expired_sessions = $wpdb->get_results( \"SELECT id,user_id,token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE (revoked_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()) AND created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT 500\", ARRAY_A );\n\t\tforeach ( (array) $expired_sessions as $session ) {\n\t\t\tif ( SMC_Security::delete_session_token_envelope( (int) $session['user_id'], (string) $session['token_hash'] ) ) { $wpdb->delete( $wpdb->prefix . 'smc_auth_sessions', array( 'id' => (int) $session['id'] ), array( '%d' ) ); }\n\t\t}\n\t\t$session_users = array_values( array_unique( array_map( 'absint', array_column( (array) $expired_sessions, 'user_id' ) ) ) );\n\t\tforeach ( $session_users as $session_user_id ) { $live = $wpdb->get_col( $wpdb->prepare( \"SELECT token_hash FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()\", $session_user_id ) ); SMC_Security::sweep_session_token_envelopes( $session_user_id, $live ); }",
)

# ---------------------------------------------------------------------------
# Admin governance: assignment/document scope, immutable dual approval,
# appeal provenance, jurisdictional age, post-commit WP side effects and stable
# verification event timestamps.
# ---------------------------------------------------------------------------
admin = "source/sabri-membership-core/includes/class-smc-admin.php"
# Document decision full replacement.
replace_function(
    admin, "public", "handle_document", "public", "handle_assignment",
    r'''	public static function handle_document() {
		if ( ! current_user_can( 'smc_review_verification' ) || ! SMC_Security::session_is_verified( get_current_user_id() ) ) { wp_die( esc_html__( 'A current authorized reviewer session is required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['document_id'] ?? 0 ); check_admin_referer( 'smc_review_document_' . $id, 'smc_nonce' );
		$version = absint( $_POST['document_version'] ?? 0 ); $decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ); $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		if ( ! in_array( $decision, array( 'approved','rejected' ), true ) || strlen( $note ) < 8 ) { wp_die( esc_html__( 'Invalid document decision.', 'sabri-membership-core' ), '', array( 'response' => 400 ) ); }
		global $wpdb; $wpdb->query( 'START TRANSACTION' );
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		$request = $doc ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d LIMIT 1 FOR UPDATE", (int) $doc['user_id'] ), ARRAY_A ) : null;
		$allowed_states = array( 'under_review','approval_pending','resubmitted','submitted' );
		if ( ! $doc || (int) $doc['user_id'] === get_current_user_id() || ! $request || (int) $request['assigned_reviewer'] !== get_current_user_id() || 'none' !== $request['conflict_status'] || ! in_array( $request['status'], $allowed_states, true ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'This document is not in your currently assigned no-conflict review.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_identity_documents SET status=%s,reviewed_by=%d,reviewed_at=%s,reviewer_note=%s,updated_at=%s WHERE id=%d AND version=%d AND user_id=%d", $decision, get_current_user_id(), $now, $note, $now, $id, $version, (int) $doc['user_id'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'document_' . $decision, (int) $doc['user_id'], array( 'document_id' => $id, 'version' => $version, 'reason_code' => 'document_' . $decision ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The evidence decision could not be committed with its audit record. Reload the case.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$wpdb->query( 'COMMIT' ); self::redirect_review( (int) $doc['user_id'] );
	}'''
)
# Atomic assignment/conflict.
replace_function(
    admin, "public", "handle_assignment", "public", "handle_conflict",
    r'''	public static function handle_assignment() {
		if ( ! current_user_can( 'smc_review_verification' ) ) { wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['request_id'] ?? 0 ); check_admin_referer( 'smc_assign_review_' . $id, 'smc_nonce' ); global $wpdb; $wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || ( (int) $row['assigned_reviewer'] && (int) $row['assigned_reviewer'] !== get_current_user_id() ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The review is no longer claimable.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET assigned_reviewer=%d,assigned_at=%s,conflict_status='undeclared',conflict_note=NULL,row_version=row_version+1,updated_at=%s WHERE id=%d AND row_version=%d", get_current_user_id(), $now, $now, $id, (int) $row['row_version'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'review_assigned', (int) $row['user_id'], array( 'request_id' => $id, 'reviewer_id' => get_current_user_id() ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The review could not be assigned safely.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$wpdb->query( 'COMMIT' ); self::redirect_review( (int) $row['user_id'] );
	}'''
)
replace_function(
    admin, "public", "handle_conflict", "public", "handle_transition",
    r'''	public static function handle_conflict() {
		if ( ! current_user_can( 'smc_review_verification' ) ) { wp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) ); }
		$id = absint( $_POST['request_id'] ?? 0 ); check_admin_referer( 'smc_declare_conflict_' . $id, 'smc_nonce' );
		$status = sanitize_key( wp_unslash( $_POST['conflict_status'] ?? '' ) ); $note = sanitize_textarea_field( wp_unslash( $_POST['conflict_note'] ?? '' ) );
		if ( ! in_array( $status, array( 'none','conflict' ), true ) || ( 'conflict' === $status && strlen( $note ) < 8 ) ) { wp_die( esc_html__( 'A valid conflict declaration is required.', 'sabri-membership-core' ), '', array( 'response' => 400 ) ); }
		global $wpdb; $wpdb->query( 'START TRANSACTION' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_verification_requests WHERE id=%d LIMIT 1 FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || (int) $row['assigned_reviewer'] !== get_current_user_id() ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Only the assigned reviewer may record this declaration.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		$assigned = 'conflict' === $status ? 0 : get_current_user_id(); $assigned_at = 'conflict' === $status ? null : ( $row['assigned_at'] ?: current_time( 'mysql', true ) ); $now = current_time( 'mysql', true );
		$updated = $wpdb->update( $wpdb->prefix . 'smc_verification_requests', array( 'conflict_status'=>$status,'conflict_note'=>$note,'assigned_reviewer'=>$assigned,'assigned_at'=>$assigned_at,'row_version'=>(int)$row['row_version']+1,'updated_at'=>$now ), array( 'id'=>$id,'row_version'=>(int)$row['row_version'] ) );
		$audit_ok = 1 === $updated && SMC_Security::audit( 'review_conflict_' . $status, (int) $row['user_id'], array( 'request_id'=>$id,'reason_code'=>'conflict_' . $status ) );
		if ( 1 !== $updated || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The conflict declaration could not be committed.', 'sabri-membership-core' ), '', array( 'response'=>409 ) ); }
		$wpdb->query( 'COMMIT' ); self::redirect_review( (int) $row['user_id'] );
	}'''
)
# Appeal may not be converted into ordinary review/approve.
replace_once(admin, "'appeal_review'    => array( 'under_review', 'restore', 'reject' ),", "'appeal_review'    => array( 'restore', 'reject' ),")
replace_once(
    admin,
    "\t\tif ( 'approve' === $decision ) {\n\t\t\tself::approve( $user_id, $request, $version, $reason, $reason_code );\n\t\t}",
    "\t\tif ( 'approve' === $decision ) {\n\t\t\tif ( 'appeal' === sanitize_key( $request['queue_type'] ?? '' ) ) { wp_die( esc_html__( 'Appeal provenance cannot be converted to ordinary approval; use the governed restore decision.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }\n\t\t\tself::approve( $user_id, $request, $version, $reason, $reason_code );\n\t\t}",
)
replace_once(admin, "$minimum_age = $app ? smc_minimum_age_for_gender( $app['gender'] ) : false;", "$minimum_age = $app ? smc_effective_minimum_age( $app['gender'], $app['residence_country'] ?? '' ) : false;")
# Current guardian generation only.
replace_once(
    admin,
    "SELECT status,consent_hash,policy_version,verified_at,withdrawn_at FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d LIMIT 1 FOR UPDATE",
    "SELECT status,consent_hash,policy_version,verified_at,withdrawn_at FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1 FOR UPDATE",
)
# Immutable approval generation block.
regex_once(
    admin,
    r"\n\t\t\$vote = \$wpdb->query\(.*?\n\t\t\$senior_required = \$required_votes > 1;",
    r'''
		$snapshot_hash = hash( 'sha256', $snapshot );
		$generation = ! empty( $request['approval_generation'] ) ? (string) $request['approval_generation'] : wp_generate_uuid4();
		$stored_snapshot_hash = (string) ( $request['approval_snapshot_hash'] ?? '' );
		if ( '' !== $stored_snapshot_hash && ! hash_equals( $stored_snapshot_hash, $snapshot_hash ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'Approval evidence changed after the approval generation was opened. Start a new correction/review generation.', 'sabri-membership-core' ), '', array( 'response' => 409 ) );
		}
		if ( '' === $stored_snapshot_hash ) {
			$generation_saved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET approval_generation=%s,approval_snapshot_hash=%s WHERE id=%d AND row_version=%d AND (approval_generation IS NULL OR approval_generation='')", $generation, $snapshot_hash, (int) $request['id'], (int) $request['row_version'] ) );
			if ( 1 !== $generation_saved ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The approval generation changed concurrently.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }
		}
		$vote = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_approval_votes (request_id,reviewer_id,approval_generation,decision,reason,evidence_snapshot,created_at) VALUES (%d,%d,%s,'approve',%s,%s,%s) ON DUPLICATE KEY UPDATE decision='approve',reason=VALUES(reason),evidence_snapshot=VALUES(evidence_snapshot),created_at=VALUES(created_at)",
			(int) $request['id'], get_current_user_id(), $generation, $reason, $snapshot, current_time( 'mysql', true )
		) );
		if ( false === $vote ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'Approval vote could not be recorded.', 'sabri-membership-core' ), '', array( 'response' => 500 ) ); }
		$required_votes = array_intersect( SMC_Contracts::requested_types( $user_id ), smc_professional_types() ) ? 2 : 1;
		$votes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reviewer_id) FROM {$wpdb->prefix}smc_approval_votes WHERE request_id=%d AND approval_generation=%s AND decision='approve'", (int) $request['id'], $generation ) );
		$senior_required = $required_votes > 1;'''
)
# Pending approval: release assignment, preserve application evidence/version.
regex_once(
    admin,
    r"\n\t\t\t\$ok1 = \$wpdb->query\( \$wpdb->prepare\( \"UPDATE \{\$wpdb->prefix\}smc_verification_requests SET status='approval_pending'.*?\n\t\t\tif \( 1 !== \$ok1 \|\| 1 !== \$ok2 \|\| ! \$event_ok \|\| ! \$audit_ok \) \{",
    r'''
			$ok1 = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_verification_requests SET status='approval_pending',assigned_reviewer=0,assigned_at=NULL,conflict_status='undeclared',conflict_note=NULL,reason_code=%s,reviewer_note=%s,row_version=row_version+1,updated_at=%s WHERE id=%d AND user_id=%d AND row_version=%d AND applicant_version=%d AND approval_generation=%s AND approval_snapshot_hash=%s", $reason_code, $pending_reason . ' ' . $reason, $now, (int) $request['id'], $user_id, $version, (int) $request['applicant_version'], $generation, $snapshot_hash ) );
			$event_ok = 1 === $ok1 && self::append_event( $request, 'approval_pending', $pending_reason, $user_id );
			$audit_ok = $event_ok && SMC_Security::audit( 'membership_approval_pending', $user_id, array( 'request_id'=>(int)$request['id'],'votes'=>$votes,'required_votes'=>$required_votes,'approval_generation'=>$generation,'evidence_snapshot_sha256'=>$snapshot_hash,'reason_code'=>$reason_code ) );
			if ( 1 !== $ok1 || ! $event_ok || ! $audit_ok ) {'''
)
replace_once(admin, "\t\t\tif ( 1 !== $ok1 || ! $event_ok || ! $audit_ok ) {\n\t\t\t\t$wpdb->query( 'ROLLBACK' );", "\t\t\tif ( 1 !== $ok1 || ! $event_ok || ! $audit_ok ) {\n\t\t\t\t$wpdb->query( 'ROLLBACK' );")
# Remove any stale ok2 reference in pending failure block/message left by regex.
text = read(admin)
text = text.replace("1 !== $ok1 || 1 !== $ok2 || ! $event_ok || ! $audit_ok", "1 !== $ok1 || ! $event_ok || ! $audit_ok")
write(admin, text)
# Final app CAS must use actual app status; role DB changes before commit, WP roles/sessions after commit under hold.
replace_once(admin, ", $user_id, $request['status'], (int) $app['row_version'] ) );\n\t\t$ok3 =", ", $user_id, $app['status'], (int) $app['row_version'] ) );\n\t\t$ok3 =")
replace_once(
    admin,
    "\t\t$role_ok = 1 === $ok1 && 1 === $ok2 && false !== $ok3 && SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id() );\n\t\t$sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_approved_requires_fresh_login' );\n\t\t$event_ok = $sessions_ok && self::append_event( $request, 'approved', $reason, $user_id );\n\t\t$audit_ok = $event_ok && SMC_Security::audit( 'membership_approved', $user_id, array( 'request_id' => (int) $request['id'], 'votes' => $votes, 'evidence_snapshot_sha256' => hash( 'sha256', $snapshot ), 'reason_code' => $reason_code, 'role_types' => SMC_Contracts::requested_types( $user_id ) ) );\n\t\tif ( 1 !== $ok1 || 1 !== $ok2 || false === $ok3 || ! $role_ok || ! $sessions_ok || ! $event_ok || ! $audit_ok ) {",
    "\t\t$hold = array( 'operation'=>'approve','started_at'=>time() ); update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );\n\t\t$role_ok = 1 === $ok1 && 1 === $ok2 && false !== $ok3 && get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) === $hold && SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id(), false );\n\t\t$event_ok = $role_ok && self::append_event( $request, 'approved', $reason, $user_id );\n\t\t$audit_ok = $event_ok && SMC_Security::audit( 'membership_approved', $user_id, array( 'request_id'=>(int)$request['id'],'votes'=>$votes,'approval_generation'=>$generation,'evidence_snapshot_sha256'=>$snapshot_hash,'reason_code'=>$reason_code ) );\n\t\tif ( 1 !== $ok1 || 1 !== $ok2 || false === $ok3 || ! $role_ok || ! $event_ok || ! $audit_ok ) {",
)
replace_once(
    admin,
    "\t\t$wpdb->query( 'COMMIT' );\n\t\tclean_user_cache( $user_id );\n\t\tself::notify_decision( $user_id, 'approved', $reason );",
    "\t\t$wpdb->query( 'COMMIT' );\n\t\t$roles_wp_ok = SMC_Contracts::sync_wordpress_roles( $user_id );\n\t\t$sessions_ok = $roles_wp_ok && SMC_Security::revoke_all_sessions( $user_id, 'membership_approved_requires_fresh_login' );\n\t\tif ( ! $roles_wp_ok || ! $sessions_ok ) { if ( class_exists( 'SMC_Completion' ) ) { SMC_Completion::queue_effects_repair( $user_id, 'approve', 'approved', 'postcommit_effects' ); } wp_die( esc_html__( 'Approval is durably recorded but remains fail-closed pending role/session reconciliation.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }\n\t\tdelete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );\n\t\tclean_user_cache( $user_id );\n\t\tself::notify_decision( $user_id, 'approved', $reason );",
)
# commit_transition: database role truth in transaction, WP effects after commit.
replace_once(admin, "SMC_Contracts::set_all_roles_pending( $user_id, (int) $request['applicant_version'] + 1 );", "SMC_Contracts::set_all_roles_pending( $user_id, (int) $request['applicant_version'] + 1, false );")
replace_once(admin, "SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id() );", "SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id(), false );")
# Remove in-transaction session side effect and add hold before transaction.
replace_once(
    admin,
    "\t\t$wpdb->query( 'START TRANSACTION' );\n\t\t$restrict = in_array( $new, array( 'rejected', 'suspended' ), true );",
    "\t\t$hold = array( 'operation'=>'transition','target_status'=>$new,'started_at'=>time() ); update_user_meta( $user_id, '_smc_membership_effects_hold_v1', $hold );\n\t\tif ( get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) !== $hold ) { wp_die( esc_html__( 'Could not establish the fail-closed reconciliation hold.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }\n\t\t$wpdb->query( 'START TRANSACTION' );\n\t\t$restrict = in_array( $new, array( 'rejected', 'suspended' ), true );",
)
regex_once(
    admin,
    r"\n\t\t\$sessions_ok = true;\n\t\tif \( \$restrict \) \{.*?\n\t\t\}\n\t\t\$event_ok = self::append_event",
    r'''
		if ( $restrict ) {
			$role_ok = SMC_Contracts::set_all_roles_pending( $user_id, (int) $request['applicant_version'] + 1, false );
		} elseif ( $restore ) {
			$role_ok = SMC_Contracts::approve_requested_roles( $user_id, (int) $request['applicant_version'] + 1, get_current_user_id(), false );
		} else { $role_ok = true; }
		$event_ok = self::append_event'''
)
text = read(admin).replace("|| ! $role_ok || ! $sessions_ok || ! $event_ok", "|| ! $role_ok || ! $event_ok")
write(admin, text)
replace_once(
    admin,
    "\t\t$wpdb->query( 'COMMIT' );\n\t\tclean_user_cache( $user_id );\n\t}\n\n\tprivate static function append_event",
    "\t\t$wpdb->query( 'COMMIT' );\n\t\t$role_effect = ( $restrict || $restore ) ? SMC_Contracts::sync_wordpress_roles( $user_id ) : true;\n\t\t$session_effect = ( $restrict || $restore ) && $role_effect ? SMC_Security::revoke_all_sessions( $user_id, $restore ? 'membership_restored_requires_fresh_login' : 'membership_' . $new ) : $role_effect;\n\t\tif ( ! $role_effect || ! $session_effect ) { if ( class_exists( 'SMC_Completion' ) ) { SMC_Completion::queue_effects_repair( $user_id, 'transition', $new, 'postcommit_effects' ); } return; }\n\t\tdelete_user_meta( $user_id, '_smc_membership_effects_hold_v1' );\n\t\tclean_user_cache( $user_id );\n\t}\n\n\tprivate static function append_event",
)
# Verification-event hash uses exactly the stored timestamp.
replace_once(
    admin,
    "\t\t$payload = wp_json_encode( array( 'request_id' => (int) $request['id'], 'user_id' => $user_id, 'actor_id' => get_current_user_id(), 'old' => $request['status'], 'new' => $new, 'note' => $reason, 'previous' => $previous, 'time' => current_time( 'mysql', true ) ) );\n\t\t$hash = SMC_Security::blind_index( $payload, 'verification-event' );\n\t\treturn ! is_wp_error( $hash ) && 1 === $wpdb->insert( $wpdb->prefix . 'smc_verification_events', array( 'request_id' => (int) $request['id'], 'user_id' => $user_id, 'actor_id' => get_current_user_id(), 'old_status' => $request['status'], 'new_status' => $new, 'note' => $reason, 'previous_hash' => $previous, 'event_hash' => $hash, 'created_at' => current_time( 'mysql', true ) ) );",
    "\t\t$created_at = current_time( 'mysql', true );\n\t\t$payload = wp_json_encode( array( 'request_id'=>(int)$request['id'],'user_id'=>$user_id,'actor_id'=>get_current_user_id(),'old'=>$request['status'],'new'=>$new,'note'=>$reason,'previous'=>$previous,'time'=>$created_at ) );\n\t\t$hash = SMC_Security::blind_index( $payload, 'verification-event' );\n\t\treturn ! is_wp_error( $hash ) && 1 === $wpdb->insert( $wpdb->prefix . 'smc_verification_events', array( 'request_id'=>(int)$request['id'],'user_id'=>$user_id,'actor_id'=>get_current_user_id(),'old_status'=>$request['status'],'new_status'=>$new,'note'=>$reason,'previous_hash'=>$previous,'event_hash'=>$hash,'created_at'=>$created_at ) );",
)

# ---------------------------------------------------------------------------
# Workflow: rejected users cannot reapply; guardian invitation is durable first;
# 2FA replacement gets an explicit re-auth/current-factor ceremony.
# ---------------------------------------------------------------------------
workflow = "source/sabri-membership-core/includes/class-smc-workflow.php"
replace_once(workflow, "array( 'draft', 'more_information', 'rejected' )", "array( 'draft', 'more_information' )")
# Server-side submit guard against rejected/suspended/appeal bypass.
replace_once(
    workflow,
    "\t\t$app = smc_application( $user_id );\n\t\tif ( ! $app ) {",
    "\t\t$app = smc_application( $user_id );\n\t\tif ( $app && in_array( sanitize_key( $app['status'] ?? '' ), array( 'rejected','suspended','appeal_review' ), true ) ) { self::redirect( 'status', 'appeal_required' ); }\n\t\tif ( ! $app ) {",
)
# Guardian: durable generation first, delivery second. Replace block from send to insert/upssert.
regex_once(
    workflow,
    r"\n\t\t\$sent = apply_filters\( 'smc_send_guardian_invitation'.*?\n\t\tif \( false === \$wpdb->query\( \$sql \) \) \{\n\t\t\tself::redirect\( 'guardian', 'invalid' \);\n\t\t\}\n\t\tself::redirect\( 'guardian', 'guardian_sent' \);",
    r'''
		$wpdb->query( 'START TRANSACTION' );
		$previous = $wpdb->get_row( $wpdb->prepare( "SELECT id,generation FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$generation = $previous ? (int) $previous['generation'] + 1 : 1;
		if ( $previous ) { $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET is_current=0,withdrawn_at=COALESCE(withdrawn_at,%s) WHERE id=%d AND is_current=1", $now, (int) $previous['id'] ) ); }
		$inserted = $wpdb->insert( $wpdb->prefix . 'smc_guardian_consents', array(
			'user_id'=>$user_id,'generation'=>$generation,'is_current'=>1,'guardian_name_enc'=>$name_enc,'guardian_email_enc'=>$email_enc,'guardian_email_hash'=>$email_hash,'guardian_phone_enc'=>$phone_enc,'guardian_phone_hash'=>$phone_hash,'relationship'=>$relationship,'legal_authority_confirmed'=>1,'status'=>'pending','consent_text'=>$consent_text,'consent_hash'=>hash('sha256',$consent_text),'policy_version'=>smc_policy()['version'],'otp_hash'=>wp_hash_password($code),'otp_lookup_hash'=>$lookup,'invitation_token_hash'=>$token_hash,'otp_attempts'=>0,'otp_expires_at'=>gmdate('Y-m-d H:i:s',time()+10*MINUTE_IN_SECONDS),'requested_at'=>$now,'verified_at'=>null,'withdrawn_at'=>null,'ip_hash'=>SMC_Security::blind_index( $_SERVER['REMOTE_ADDR'] ?? '', 'guardian-ip' ),'device_hash'=>SMC_Security::blind_index( $_SERVER['HTTP_USER_AGENT'] ?? '', 'guardian-device' )
		) );
		if ( 1 !== $inserted ) { $wpdb->query( 'ROLLBACK' ); self::redirect( 'guardian', 'invalid' ); }
		$consent_id = (int) $wpdb->insert_id;
		if ( ! SMC_Security::audit( 'guardian_invitation_created', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) ) ) { $wpdb->query( 'ROLLBACK' ); self::redirect( 'guardian', 'invalid' ); }
		$wpdb->query( 'COMMIT' );
		$sent = apply_filters( 'smc_send_guardian_invitation', false, array( 'user_id'=>$user_id,'consent_id'=>$consent_id,'generation'=>$generation,'guardian_email'=>$guardian_email,'guardian_phone'=>$guardian_phone,'token'=>$token,'code'=>$code,'expires_in'=>600 ) );
		if ( true !== $sent ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_guardian_consents SET status='delivery_failed',is_current=0,withdrawn_at=%s WHERE id=%d AND user_id=%d AND status='pending'", current_time('mysql',true), $consent_id, $user_id ) );
			SMC_Security::audit( 'guardian_invitation_delivery_failed', $user_id, array( 'consent_id'=>$consent_id,'generation'=>$generation ) );
			self::redirect( 'guardian', 'provider' );
		}
		self::redirect( 'guardian', 'guardian_sent' );'''
)
# Queries for guardian verification use current generation.
text = read(workflow)
text = text.replace("WHERE user_id=%d LIMIT 1", "WHERE user_id=%d AND is_current=1 ORDER BY generation DESC LIMIT 1")
write(workflow, text)
# 2FA start replacement guard: when already enabled require password + current factor and verified session.
replace_once(
    workflow,
    "\tpublic static function handle_start_2fa() {\n\t\tself::guard_user_action( 'smc_start_2fa' );\n\t\t$user_id = get_current_user_id();\n\t\t$secret = SMC_Security::base32_secret();",
    "\tpublic static function handle_start_2fa() {\n\t\tself::guard_user_action( 'smc_start_2fa' );\n\t\t$user_id = get_current_user_id();\n\t\t$replacing = SMC_Security::two_factor_ready( $user_id );\n\t\tif ( $replacing ) {\n\t\t\t$password = (string) wp_unslash( $_POST['password'] ?? '' ); $current_code = (string) wp_unslash( $_POST['current_code'] ?? '' ); $user = wp_get_current_user();\n\t\t\tif ( ! SMC_Security::session_is_verified( $user_id ) || ! wp_check_password( $password, $user->user_pass, $user_id ) || ( ! SMC_Security::verify_current_factor_without_session_rotation( $user_id, $current_code ) ) ) { self::redirect( 'security', 'invalid' ); }\n\t\t\t$receipt = SMC_Security::create_factor_replacement_receipt( $user_id ); if ( is_wp_error( $receipt ) ) { self::redirect( 'security', 'invalid' ); }\n\t\t}\n\t\t$secret = SMC_Security::base32_secret();",
)
# Security UI: replace start form when enabled/session verified and support pending replacement confirmation.
replace_once(
    workflow,
    "\t\t\t<?php if ( ! $enabled && ! $secret ) : ?>\n\t\t\t\t<form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\"><input type=\"hidden\" name=\"action\" value=\"smc_start_2fa\"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><button class=\"smc-button\"><?php esc_html_e( 'Begin Authenticator Setup', 'sabri-membership-core' ); ?></button></form>\n\t\t\t<?php elseif ( ! $enabled ) : ?>",
    "\t\t\t<?php if ( ! $enabled && ! $secret ) : ?>\n\t\t\t\t<form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\"><input type=\"hidden\" name=\"action\" value=\"smc_start_2fa\"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><button class=\"smc-button\"><?php esc_html_e( 'Begin Authenticator Setup', 'sabri-membership-core' ); ?></button></form>\n\t\t\t<?php elseif ( $secret ) : ?>",
)
replace_once(workflow, "\t\t\t<?php elseif ( ! SMC_Security::session_is_verified( $user_id ) ) : ?>", "\t\t\t<?php elseif ( ! SMC_Security::session_is_verified( $user_id ) ) : ?>")
replace_once(
    workflow,
    "\t\t\t\t<?php self::session_list( $user_id ); ?>\n\t\t\t\t<form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\" class=\"smc-form\"><input type=\"hidden\" name=\"action\" value=\"smc_rotate_recovery\"><?php wp_nonce_field( 'smc_rotate_recovery', 'smc_nonce' ); ?>",
    "\t\t\t\t<?php self::session_list( $user_id ); ?>\n\t\t\t\t<form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\" class=\"smc-form\"><input type=\"hidden\" name=\"action\" value=\"smc_start_2fa\"><?php wp_nonce_field( 'smc_start_2fa', 'smc_nonce' ); ?><h2><?php esc_html_e( 'Replace Authenticator', 'sabri-membership-core' ); ?></h2><label><?php esc_html_e( 'Current password', 'sabri-membership-core' ); ?><input name=\"password\" type=\"password\" autocomplete=\"current-password\" required></label><label><?php esc_html_e( 'Current authenticator or recovery code', 'sabri-membership-core' ); ?><input name=\"current_code\" autocomplete=\"one-time-code\" required></label><button class=\"smc-button\"><?php esc_html_e( 'Start Secure Authenticator Replacement', 'sabri-membership-core' ); ?></button></form>\n\t\t\t\t<form method=\"post\" action=\"<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>\" class=\"smc-form\"><input type=\"hidden\" name=\"action\" value=\"smc_rotate_recovery\"><?php wp_nonce_field( 'smc_rotate_recovery', 'smc_nonce' ); ?>",
)
# Finish 2FA must not overwrite existing factor until new code validated; delegate to atomic security method.
regex_once(
    workflow,
    r"\n\tpublic static function handle_finish_2fa\(\) \{.*?\n\t\}\n\n\tpublic static function handle_challenge_2fa",
    r'''
	public static function handle_finish_2fa() {
		self::guard_user_action( 'smc_finish_2fa' ); $user_id = get_current_user_id(); $code = preg_replace( '/\D/', '', wp_unslash( $_POST['code'] ?? '' ) );
		$pending = get_user_meta( $user_id, '_smc_totp_pending_enc', true ); $expires = absint( get_user_meta( $user_id, '_smc_totp_pending_expires', true ) );
		if ( 6 !== strlen( $code ) || ! $pending || $expires <= time() ) { self::redirect( 'security', 'invalid' ); }
		$secret = SMC_Security::decrypt( $pending, 'totp-pending', array( 'user_id'=>$user_id,'expires'=>$expires ) );
		if ( is_wp_error( $secret ) ) { self::redirect( 'security', 'invalid' ); }
		$replacement = SMC_Security::two_factor_ready( $user_id );
		$result = SMC_Security::commit_factor_enrollment_or_replacement( $user_id, $secret, $code, $replacement, array( __CLASS__, 'store_recovery_receipt' ) );
		if ( is_wp_error( $result ) || false === $result ) { self::redirect( 'security', 'invalid' ); }
		self::delete_user_meta_verified( $user_id, '_smc_totp_pending_enc' ); self::delete_user_meta_verified( $user_id, '_smc_totp_pending_expires' );
		self::redirect( 'security', '2fa_enabled' );
	}

	public static function handle_challenge_2fa'''
)

# Add security helpers before register_session.
replace_once(
    security,
    "\tpublic static function register_session( $user_id, $token, $expiration ) {",
    r'''	public static function verify_current_factor_without_session_rotation( $user_id, $code ) {
		$user_id = absint( $user_id ); $code = trim( (string) $code );
		if ( preg_match( '/^[0-9]{6}$/', $code ) ) {
			$secret = self::two_factor_secret( $user_id ); if ( is_wp_error( $secret ) ) { return false; }
			return false !== self::matching_totp_slice( $secret, $code );
		}
		return self::consume_recovery_code( $user_id, $code );
	}

	public static function create_factor_replacement_receipt( $user_id ) {
		$user_id = absint( $user_id ); $token = wp_get_session_token(); if ( ! $token ) { return new WP_Error( 'smc_factor_replace_session', __( 'A current session is required.', 'sabri-membership-core' ) ); }
		$hash = self::blind_index( $token, 'session-token' ); if ( is_wp_error( $hash ) ) { return $hash; }
		$expires = time() + 5 * MINUTE_IN_SECONDS; $payload = wp_json_encode( array( 'user_id'=>$user_id,'session_hash'=>$hash,'expires'=>$expires ) );
		$enc = self::encrypt( $payload, 'factor-replacement-receipt', array( 'user_id'=>$user_id,'expires'=>$expires ) ); if ( is_wp_error( $enc ) ) { return $enc; }
		update_user_meta( $user_id, '_smc_factor_replace_receipt', array( 'expires'=>$expires,'envelope'=>$enc ) );
		return get_user_meta( $user_id, '_smc_factor_replace_receipt', true ) ? true : new WP_Error( 'smc_factor_replace_store', __( 'Replacement authorization could not be stored.', 'sabri-membership-core' ) );
	}

	private static function factor_replacement_receipt_valid( $user_id ) {
		$receipt = get_user_meta( absint( $user_id ), '_smc_factor_replace_receipt', true ); if ( ! is_array( $receipt ) || absint( $receipt['expires'] ?? 0 ) <= time() ) { return false; }
		$payload = self::decrypt( $receipt['envelope'] ?? '', 'factor-replacement-receipt', array( 'user_id'=>absint($user_id),'expires'=>absint($receipt['expires']) ) ); if ( is_wp_error( $payload ) ) { return false; }
		$data = json_decode( $payload, true ); $token_hash = self::blind_index( wp_get_session_token(), 'session-token' );
		return is_array( $data ) && ! is_wp_error( $token_hash ) && absint( $data['user_id'] ?? 0 ) === absint( $user_id ) && absint( $data['expires'] ?? 0 ) === absint( $receipt['expires'] ) && hash_equals( (string) ($data['session_hash'] ?? ''), (string) $token_hash );
	}

	public static function commit_factor_enrollment_or_replacement( $user_id, $new_secret, $new_code, $replacement, $receipt_callback ) {
		$user_id = absint( $user_id ); $slice = self::matching_totp_slice( (string) $new_secret, (string) $new_code );
		if ( false === $slice || ( $replacement && ! self::factor_replacement_receipt_valid( $user_id ) ) ) { return new WP_Error( 'smc_factor_replace_challenge', __( 'The new authenticator challenge or replacement authorization is invalid.', 'sabri-membership-core' ) ); }
		$old_secret = get_user_meta( $user_id, '_smc_totp_secret_enc', true ); $old_enabled = get_user_meta( $user_id, '_smc_2fa_enabled', true );
		$old_codes = null; global $wpdb; $wpdb->query( 'START TRANSACTION' );
		$encrypted = self::encrypt( (string) $new_secret, 'totp-secret', array( 'user_id'=>$user_id ) ); if ( is_wp_error( $encrypted ) ) { $wpdb->query('ROLLBACK'); return $encrypted; }
		update_user_meta( $user_id, '_smc_totp_secret_enc', $encrypted ); update_user_meta( $user_id, '_smc_2fa_enabled', '1' );
		$codes = self::recovery_codes( $user_id, 8, $receipt_callback );
		if ( is_wp_error( $codes ) ) { if ( $old_secret ) { update_user_meta($user_id,'_smc_totp_secret_enc',$old_secret); } else { delete_user_meta($user_id,'_smc_totp_secret_enc'); } if ($old_enabled) {update_user_meta($user_id,'_smc_2fa_enabled',$old_enabled);} else {delete_user_meta($user_id,'_smc_2fa_enabled');} $wpdb->query('ROLLBACK'); return $codes; }
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=VALUES(last_totp_slice),updated_at=VALUES(updated_at)", $user_id, $slice, current_time('mysql',true) ) );
		delete_user_meta( $user_id, '_smc_factor_replace_receipt' );
		if ( ! self::audit( $replacement ? 'two_factor_replaced' : 'two_factor_enabled', $user_id ) ) { $wpdb->query('ROLLBACK'); return new WP_Error('smc_factor_replace_audit',__( 'Factor change could not be audited.', 'sabri-membership-core' )); }
		$wpdb->query( 'COMMIT' ); self::revoke_all_sessions( $user_id, 'two_factor_changed' ); return true;
	}

	public static function register_session( $user_id, $token, $expiration ) {'''
)

# ---------------------------------------------------------------------------
# Events: delivery state CAS is checked; cron honors Safe Mode.
# ---------------------------------------------------------------------------
events = "source/sabri-membership-core/includes/class-smc-events.php"
replace_once(
    events,
    "\tpublic static function process_outbox( $limit = 25, $only_id = 0 ) {\n\t\tglobal $wpdb;",
    "\tpublic static function process_outbox( $limit = 25, $only_id = 0 ) {\n\t\tif ( ! $only_id && class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return 0; }\n\t\tglobal $wpdb;",
)
replace_once(
    events,
    "\t\t\t\tif ( true === $accepted ) {\n\t\t\t\t\t$wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_event_outbox SET status='delivered',delivered_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status='processing'\", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $row['id'] ) );\n\t\t\t\t\t++$processed;\n\t\t\t\t\tcontinue;\n\t\t\t\t}",
    "\t\t\t\tif ( true === $accepted ) {\n\t\t\t\t\t$acked = $wpdb->query( $wpdb->prepare( \"UPDATE {$wpdb->prefix}smc_event_outbox SET status='delivered',delivered_at=%s,last_error=NULL,updated_at=%s WHERE id=%d AND status='processing'\", current_time( 'mysql', true ), current_time( 'mysql', true ), (int) $row['id'] ) );\n\t\t\t\t\tif ( 1 === $acked ) { ++$processed; continue; }\n\t\t\t\t\t$delivery_error = 'Consumer acknowledged but delivery receipt CAS failed; replay requires consumer idempotency.';\n\t\t\t\t}",
)

# ---------------------------------------------------------------------------
# Completion/operations: effects repair queue, Safe Mode pause, draft expiry
# authenticated in the envelope, and restore proof cannot be a free-text pass.
# ---------------------------------------------------------------------------
completion = "source/sabri-membership-core/includes/class-smc-completion.php"
# Draft authenticated expiry: store issued/expires inside payload.
replace_once(
    completion,
    "$encrypted = SMC_Security::encrypt( $payload, 'application-draft', array( 'user_id' => $user_id, 'policy_version' => smc_policy()['version'] ) );",
    "$issued_at = time(); $expires_at = $issued_at + self::DRAFT_TTL; $sealed_payload = wp_json_encode( array( 'issued_at'=>$issued_at,'expires_at'=>$expires_at,'draft'=>$payload ) );\n\t\t$encrypted = SMC_Security::encrypt( $sealed_payload, 'application-draft', array( 'user_id'=>$user_id,'policy_version'=>smc_policy()['version'],'issued_at'=>$issued_at,'expires_at'=>$expires_at ) );",
)
replace_once(completion, "'expires' => time() + self::DRAFT_TTL,", "'issued_at' => $issued_at,\n\t\t\t'expires' => $expires_at,")
# Load draft decrypt context and unwrap.
replace_once(
    completion,
    "$plain = SMC_Security::decrypt( $receipt['envelope'], 'application-draft', array( 'user_id' => $user_id, 'policy_version' => smc_policy()['version'] ) );",
    "$issued_at = absint( $receipt['issued_at'] ?? 0 ); $expires_at = absint( $receipt['expires'] ?? 0 ); if ( ! $issued_at || $expires_at !== $issued_at + self::DRAFT_TTL ) { return array(); }\n\t\t$plain = SMC_Security::decrypt( $receipt['envelope'], 'application-draft', array( 'user_id'=>$user_id,'policy_version'=>smc_policy()['version'],'issued_at'=>$issued_at,'expires_at'=>$expires_at ) );",
)
replace_once(
    completion,
    "$data = json_decode( $plain, true );\n\t\treturn is_array( $data ) ? $data : array();",
    "$sealed = json_decode( $plain, true );\n\t\tif ( ! is_array( $sealed ) || absint( $sealed['issued_at'] ?? 0 ) !== $issued_at || absint( $sealed['expires_at'] ?? 0 ) !== $expires_at || ! is_array( $sealed['draft'] ?? null ) ) { return array(); }\n\t\treturn $sealed['draft'];",
)
# Queue effects repair helper and process in reconciliation.
replace_once(
    completion,
    "\tpublic static function health_snapshot() {",
    r'''	public static function queue_effects_repair( $user_id, $operation, $target_status, $reason ) {
		global $wpdb; $trace = wp_generate_uuid4(); $now = current_time( 'mysql', true );
		$details = wp_json_encode( array( 'operation'=>sanitize_key($operation),'target_status'=>sanitize_key($target_status),'reason'=>sanitize_key($reason) ) );
		return false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_application_repairs (trace_id,user_id,repair_type,status,details,attempts,next_attempt_at,created_at,updated_at) VALUES (%s,%d,'membership_effects_reconciliation','pending',%s,0,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", $trace, absint($user_id), $details, $now, $now, $now ) );
	}

	private static function reconcile_membership_effects( $user_id ) {
		$user_id = absint( $user_id ); $hold = get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ); if ( ! is_array( $hold ) ) { return true; }
		$role_ok = SMC_Contracts::sync_wordpress_roles( $user_id ); $sessions_ok = $role_ok && SMC_Security::revoke_all_sessions( $user_id, 'effects_reconciliation' );
		if ( ! $role_ok || ! $sessions_ok ) { return false; } delete_user_meta( $user_id, '_smc_membership_effects_hold_v1' ); return ! metadata_exists( 'user', $user_id, '_smc_membership_effects_hold_v1' );
	}

	public static function health_snapshot() {'''
)
replace_once(
    completion,
    "\t\t\tif ( ! $resolved && 'application_document_incomplete' === $row['repair_type'] ) {\n\t\t\t\t$resolved = self::application_documents_complete( (int) $row['user_id'] );\n\t\t\t}",
    "\t\t\tif ( ! $resolved && 'application_document_incomplete' === $row['repair_type'] ) { $resolved = self::application_documents_complete( (int) $row['user_id'] ); }\n\t\t\tif ( ! $resolved && 'membership_effects_reconciliation' === $row['repair_type'] ) { $resolved = self::reconcile_membership_effects( (int) $row['user_id'] ); }\n\t\t\tif ( ! $resolved && 'advanced_trust_transition' === $row['repair_type'] && class_exists( 'SMC_Advanced_Trust_2026' ) ) { $resolved = SMC_Advanced_Trust_2026::repair_transition_hold( (int) $row['user_id'] ); }",
)
# Health failed file jobs visible.
replace_once(
    completion,
    "'file_job_backlog'    => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}smc_file_jobs WHERE status IN ('pending','retry','processing')\" ),",
    "'file_job_backlog'    => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}smc_file_jobs WHERE status IN ('pending','retry','processing')\" ),\n\t\t\t'file_job_failed'     => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}smc_file_jobs WHERE status IN ('failed','dead_letter')\" ),",
)
# Strong restore proof replacement.
regex_once(
    completion,
    r"\n\tpublic static function post_restore_reconcile\(\) \{.*?\n\t\}\n\}",
    r'''
	public static function post_restore_reconcile() {
		self::require_high_risk_authority(); check_admin_referer( 'smc_post_restore_reconcile', 'smc_nonce' );
		$reference = sanitize_text_field( wp_unslash( $_POST['evidence_reference'] ?? '' ) );
		$proof = apply_filters( 'smc_restore_proof_v1', null, $reference );
		$required = array( 'restore_run_id','manifest_verified','isolated_restore','component_digests_match','row_counts_match','private_files_match','decrypt_samples_pass','key_recovery_pass','audit_chain_pass','retention_holds_reconciled','migrations_reconciled' );
		$ok = is_array( $proof ) && strlen( $reference ) >= 8;
		foreach ( $required as $key ) { if ( ! $ok || empty( $proof[ $key ] ) ) { $ok = false; break; } }
		$health = self::health_snapshot(); $ok = $ok && $health['key_ready'] && $health['private_storage'] && $health['audit_valid'] && SMC_DB_VERSION === $health['database_version'] && 0 === (int) $health['file_job_failed'];
		$result = $ok ? 'passed' : 'failed';
		if ( ! SMC_Security::audit( 'post_restore_reconciliation_' . $result, 0, array( 'evidence_reference'=>$reference,'restore_run_id'=>is_array($proof)?sanitize_text_field($proof['restore_run_id']??''):'' ) ) ) { wp_die( esc_html__( 'Restore reconciliation evidence could not be appended to the audit chain.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		$record = array( 'evidence_reference'=>$reference,'restore_run_id'=>is_array($proof)?sanitize_text_field($proof['restore_run_id']??''):'','checked_at'=>current_time('mysql',true),'result'=>$result,'health'=>$health );
		update_option( 'smc_last_restore_test', $record, false );
		if ( get_option( 'smc_last_restore_test', null ) !== $record ) { wp_die( esc_html__( 'Restore reconciliation finished, but its evidence record could not be persisted.', 'sabri-membership-core' ), '', array( 'response'=>503 ) ); }
		if ( ! $ok ) { wp_die( esc_html__( 'Restore proof did not satisfy the isolated-restore acceptance contract.', 'sabri-membership-core' ), '', array( 'response'=>409 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=smc-health-repair' ) ); exit;
	}
}'''
)

# ---------------------------------------------------------------------------
# Advanced trust: transition failures are repairable; break-glass consume rolls
# back on audit failure and stale global requests are pruned; guardian query uses
# immutable current generation.
# ---------------------------------------------------------------------------
adv = "source/sabri-membership-core/includes/class-smc-advanced-trust-2026.php"
replace_once(
    adv,
    "SELECT id FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND status='verified' AND policy_version=%s AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1",
    "SELECT id FROM {$wpdb->prefix}smc_guardian_consents WHERE user_id=%d AND is_current=1 AND status='verified' AND policy_version=%s AND withdrawn_at IS NULL ORDER BY generation DESC,id DESC LIMIT 1",
)
# Containment/continuity failures queue repair by a shared helper injected before begin_transition_hold.
replace_once(
    adv,
    "\tprivate static function begin_transition_hold( $user_id, $kind, $target_state, $actor_id ) {",
    r'''	private static function transition_failure( $user_id, $code ) {
		$hold = get_user_meta( absint($user_id), '_smc_trust_transition_hold_v1', true );
		if ( is_array($hold) ) { $hold['last_error']=sanitize_key($code); $hold['updated_at']=time(); self::write_user_meta_verified(absint($user_id),'_smc_trust_transition_hold_v1',$hold); }
		if ( class_exists('SMC_Completion') ) { SMC_Completion::queue_effects_repair(absint($user_id),'advanced_trust_transition',sanitize_key($hold['target_state']??''),sanitize_key($code)); }
		return new WP_Error( sanitize_key($code), __( 'The transition remains fail-closed and has been queued for safe repair.', 'sabri-membership-core' ) );
	}

	public static function repair_transition_hold( $user_id ) {
		$user_id=absint($user_id); $hold=get_user_meta($user_id,'_smc_trust_transition_hold_v1',true); if(!is_array($hold)){return true;}
		$kind=sanitize_key($hold['kind']??''); $target=sanitize_key($hold['target_state']??'');
		if('containment'===$kind){$state=self::containment_state($user_id); if($target!==sanitize_key($state['state']??'')){return false;}}
		elseif('continuity'===$kind){$state=self::continuity_state($user_id); if($target!==sanitize_key($state['state']??'')){return false;}}
		else{return false;}
		if(!SMC_Security::revoke_all_sessions($user_id,'transition_repair')){return false;}
		if(!self::write_user_meta_verified($user_id,'_smc_revalidation_required_at',time()+1)){return false;}
		if(!SMC_Security::audit('trust_transition_repaired',$user_id,array('kind'=>$kind,'state'=>$target))){return false;}
		return self::clear_transition_hold($user_id);
	}

	private static function begin_transition_hold( $user_id, $kind, $target_state, $actor_id ) {'''
)
# Make obvious early post-hold failures queue repair rather than permanent silent hold.
text = read(adv)
for code in [
    ("return new WP_Error( 'smc_containment_store'", "return self::transition_failure( $user_id, 'smc_containment_store' /*"),
]:
    pass
# Do targeted full-line substitutions to avoid malformed comments.
subs = {
"if ( ! self::write_user_meta_verified( $user_id, self::CONTAINMENT_META, $record ) ) { return new WP_Error( 'smc_containment_store', __( 'Security containment state could not be persisted safely.', 'sabri-membership-core' ) ); }":"if ( ! self::write_user_meta_verified( $user_id, self::CONTAINMENT_META, $record ) ) { return self::transition_failure( $user_id, 'smc_containment_store' ); }",
"if ( ! SMC_Security::revoke_all_sessions( $user_id, 'security_containment_' . $state ) ) { return new WP_Error( 'smc_containment_sessions', __( 'Security containment could not invalidate existing sessions.', 'sabri-membership-core' ) ); }":"if ( ! SMC_Security::revoke_all_sessions( $user_id, 'security_containment_' . $state ) ) { return self::transition_failure( $user_id, 'smc_containment_sessions' ); }",
"if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return new WP_Error( 'smc_containment_revalidation', __( 'Security containment could not require a fresh session challenge.', 'sabri-membership-core' ) ); }":"if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return self::transition_failure( $user_id, 'smc_containment_revalidation' ); }",
"if ( ! SMC_Security::audit( 'security_containment_changed', $user_id, array( 'state' => $state, 'reason' => $record['reason'] ) ) ) { return new WP_Error( 'smc_containment_audit', __( 'Security containment change could not be audited.', 'sabri-membership-core' ) ); }":"if ( ! SMC_Security::audit( 'security_containment_changed', $user_id, array( 'state' => $state, 'reason_code' => sanitize_key( $reason ) ) ) ) { return self::transition_failure( $user_id, 'smc_containment_audit' ); }",
"if ( ! self::write_user_meta_verified( $user_id, self::CONTINUITY_META, $record ) ) { return new WP_Error( 'smc_continuity_store', __( 'Continuity state could not be persisted safely.', 'sabri-membership-core' ) ); }":"if ( ! self::write_user_meta_verified( $user_id, self::CONTINUITY_META, $record ) ) { return self::transition_failure( $user_id, 'smc_continuity_store' ); }",
"if ( ! SMC_Security::revoke_all_sessions( $user_id, 'continuity_state_' . $state ) ) { return new WP_Error( 'smc_continuity_sessions', __( 'Continuity change could not invalidate existing sessions.', 'sabri-membership-core' ) ); }":"if ( ! SMC_Security::revoke_all_sessions( $user_id, 'continuity_state_' . $state ) ) { return self::transition_failure( $user_id, 'smc_continuity_sessions' ); }",
"if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return new WP_Error( 'smc_continuity_revalidation', __( 'Continuity change could not require a fresh challenge.', 'sabri-membership-core' ) ); }":"if ( ! self::write_user_meta_verified( $user_id, '_smc_revalidation_required_at', time() + 1 ) ) { return self::transition_failure( $user_id, 'smc_continuity_revalidation' ); }",
"if ( ! SMC_Security::audit( 'continuity_state_changed', $user_id, array( 'state' => $state, 'reason' => $record['reason'] ) ) ) { return new WP_Error( 'smc_continuity_audit', __( 'Continuity change could not be audited.', 'sabri-membership-core' ) ); }":"if ( ! SMC_Security::audit( 'continuity_state_changed', $user_id, array( 'state' => $state, 'reason_code' => sanitize_key( $reason ) ) ) ) { return self::transition_failure( $user_id, 'smc_continuity_audit' ); }",
}
for old,new in subs.items():
    if text.count(old)!=1: raise SystemExit('advanced trust transition substitution missing: '+old[:80])
    text=text.replace(old,new,1)
write(adv,text)
# Break-glass consume restore on audit failure + prune helper.
replace_once(
    adv,
    "\t\t$request['consumed_at'] = time(); $request['consumed_by'] = $actor_id; $all[ $request_id ] = $request;\n\t\t$stored = self::write_option_verified( self::BREAK_GLASS_OPTION, $all );\n\t\t$audit = $stored && SMC_Security::audit( 'break_glass_consumed', absint( $request['subject_user_id'] ), array( 'request_id' => $request_id ) );\n\t\tself::release_break_glass_lock( $lock );\n\t\tif ( ! $stored || ! $audit ) { return false; }",
    "\t\t$before = $request; $request['consumed_at'] = time(); $request['consumed_by'] = $actor_id; $all[ $request_id ] = $request;\n\t\t$stored = self::write_option_verified( self::BREAK_GLASS_OPTION, self::prune_break_glass_requests( $all ) );\n\t\t$audit = $stored && SMC_Security::audit( 'break_glass_consumed', absint( $request['subject_user_id'] ), array( 'request_id' => $request_id ) );\n\t\tif ( $stored && ! $audit ) { $all[ $request_id ] = $before; self::write_option_verified( self::BREAK_GLASS_OPTION, self::prune_break_glass_requests( $all ) ); }\n\t\tself::release_break_glass_lock( $lock );\n\t\tif ( ! $stored || ! $audit ) { return false; }",
)
replace_once(
    adv,
    "\tprivate static function acquire_break_glass_lock() {",
    r'''	private static function prune_break_glass_requests( $all ) {
		$cutoff = time() - DAY_IN_SECONDS; $kept = array();
		foreach ( (array) $all as $id => $request ) { if ( ! is_array($request) ) { continue; } $expires=absint($request['expires_at']??0); $consumed=absint($request['consumed_at']??0); if ( $expires < $cutoff || ( $consumed && $consumed < $cutoff ) ) { continue; } $kept[$id]=$request; }
		if ( count($kept)>100 ) { uasort($kept,static function($a,$b){return absint($a['opened_at']??0)<=>absint($b['opened_at']??0);}); $kept=array_slice($kept,-100,null,true); }
		return $kept;
	}

	public static function purge_break_glass_subject( $user_id ) {
		$user_id=absint($user_id); $lock=self::acquire_break_glass_lock(); if(false===$lock){return false;} $all=(array)get_option(self::BREAK_GLASS_OPTION,array()); foreach($all as $id=>$r){if(is_array($r)&&absint($r['subject_user_id']??0)===$user_id){unset($all[$id]);}} $ok=self::write_option_verified(self::BREAK_GLASS_OPTION,self::prune_break_glass_requests($all)); self::release_break_glass_lock($lock); return $ok;
	}

	private static function acquire_break_glass_lock() {'''
)

# ---------------------------------------------------------------------------
# Privacy: erasure removes managed WP roles/caps, ancillary holds/break-glass;
# export pages are bounded and active-session label is truthful.
# ---------------------------------------------------------------------------
privacy = "source/sabri-membership-core/includes/class-smc-privacy.php"
# Bound broad get_results queries in export by adding LIMIT where absent for subject datasets.
text = read(privacy)
text = text.replace("ORDER BY id ASC\", $user_id )", "ORDER BY id ASC LIMIT 500\", $user_id )")
# Correct active session export query if present.
text = text.replace("FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d ORDER BY id ASC", "FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY id ASC LIMIT 500")
write(privacy,text)
# Add retention_holds to erasure tables when list appears.
replace_once(
    privacy,
    "'smc_event_outbox', 'smc_event_inbox', 'smc_application_repairs'",
    "'smc_event_outbox', 'smc_event_inbox', 'smc_application_repairs', 'smc_retention_holds'",
)
# Before final erasure completion, remove managed roles/caps and subject break-glass.
replace_once(
    privacy,
    "\t\t$wpdb->query( 'COMMIT' );\n\t\treturn true;",
    "\t\t$wpdb->query( 'COMMIT' );\n\t\t$user = get_userdata( $user_id ); $wp_ok = true;\n\t\tif ( $user ) { foreach ( smc_managed_roles() as $role ) { $user->remove_role( $role ); } foreach ( array( 'smc_review_verification','smc_view_private_documents','smc_finalize_verification','smc_manage_membership','smc_manage_retention_holds','smc_restore_membership','smc_manage_repairs','smc_configure_institutional_ai','smc_ai_generate_educational_content','smc_ai_submit_educational_content' ) as $cap ) { $user->remove_cap( $cap ); } $fresh=get_userdata($user_id); $wp_ok = $fresh && ! array_intersect( (array)$fresh->roles, smc_managed_roles() ); }\n\t\t$break_glass_ok = ! class_exists('SMC_Advanced_Trust_2026') || SMC_Advanced_Trust_2026::purge_break_glass_subject($user_id);\n\t\tif ( ! $wp_ok || ! $break_glass_ok ) { update_user_meta($user_id,'_smc_privacy_erasure_lock',array('locked_at'=>time(),'reason'=>'ancillary_cleanup_pending')); return false; }\n\t\treturn true;",
)

# ---------------------------------------------------------------------------
# Private file orphan job removes its authenticated companion lease.
# ---------------------------------------------------------------------------
replace_once(
    security,
    "\t\t\tif ( 'delete_orphan' === $row['job_type'] ) {\n\t\t\t\t$ok = ! file_exists( $path ) || self::verified_unlink( $path );",
    "\t\t\tif ( 'delete_orphan' === $row['job_type'] ) {\n\t\t\t\t$lease = $path . '.lease';\n\t\t\t\t$ok = ( ! file_exists( $path ) || self::verified_unlink( $path ) ) && ( ! file_exists( $lease ) || self::verified_unlink( $lease ) );",
)

# ---------------------------------------------------------------------------
# STATUS truthfulness: remove defect-zero assertion; point to corrective branch.
# ---------------------------------------------------------------------------
status_text = """# File 00 — Corrective status\n\n- Audited baseline: `3a84c32a6ddad151f2ed09d244fa8aa536a58108` (runtime 1.2.18).\n- Corrective candidate: runtime **1.2.19**, DB schema **1.4.0**, contract **1.2.1**.\n- Scope: closes the 32 source-code findings in `File-00-GitHub-Code-Only-Audit-2026-08-08-Urdu`.\n- Status: **code corrective candidate / production release still blocked until real WordPress+MySQL, provider, browser, restore, security and staging acceptance gates pass**.\n- No “zero defects” or production-complete claim is made from static/contract tests alone.\n\nGenerated/updated by the audit-32 corrective branch on 2026-08-08 PKT.\n"""
write("STATUS.md", status_text)

# Release-note receipt.
release_note = """# File 00 1.2.19 — Code-only audit corrective candidate\n\nBaseline: `3a84c32a6ddad151f2ed09d244fa8aa536a58108`.\n\nThis candidate addresses all 32 findings in the 8 August 2026 GitHub code-only audit: professional dual approval, appeal provenance, 2FA replacement/replay, key lifecycle, audit serialization, lifecycle/request synchronization, privacy erasure/export, restore proof, reviewer scoping, jurisdiction age, guardian succession, reapplication bypass, post-commit WordPress side effects, Safe Mode workers, institutional AI lifecycle, audit data minimization, session-envelope retention, verification-event hash reproducibility, atomic reviewer assignment/conflict, outbox ACK CAS, guardian delivery ordering, repairable trust transitions, break-glass pruning/rollback, InnoDB enforcement, recovery receipt/draft/file-lease cleanup, and release-truth documentation.\n\nProduction acceptance remains separate and requires real WordPress/MySQL concurrency, providers, browser/accessibility, isolated restore/rollback, security review and Hostinger staging evidence.\n"""
write("RELEASE-1.2.19.md", release_note)

print("audit32 corrective patch applied")

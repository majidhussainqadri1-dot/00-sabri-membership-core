<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Security {
	private static $last_audit_error = '';

	const ENVELOPE = 'SMC2';
	const MAX_FILE = 8388608;
	const LEGACY_AUDIT_ANCHOR_OPTION = 'smc_audit_legacy_anchor_v1';

	public static function init() {
		add_action( 'admin_post_smc_private_document', array( __CLASS__, 'serve_document' ) );
		add_action( 'smc_process_file_jobs', array( __CLASS__, 'process_file_jobs' ) );
		add_filter( 'smc_document_scan', array( __CLASS__, 'local_document_scan_fallback' ), 999, 5 );
	}

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

	private static $managed_keyring_cache = null;

	/** Required cryptographic primitives must exist before key readiness can be true. */
	public static function crypto_ready() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'hash_hkdf' ) ) {
			return false;
		}
		if ( function_exists( 'openssl_get_cipher_methods' ) ) {
			$methods = array_map( 'strtolower', (array) openssl_get_cipher_methods() );
			if ( ! in_array( 'aes-256-gcm', $methods, true ) ) { return false; }
		}
		return true;
	}

	private static function managed_keyring_path() {
		return wp_normalize_path( WP_CONTENT_DIR . '/sabri-private-keys/file00-keyring.php' );
	}

	private static function managed_keyring() {
		if ( null !== self::$managed_keyring_cache ) { return self::$managed_keyring_cache; }
		$path = self::managed_keyring_path();
		if ( ! is_file( $path ) || is_link( $path ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
		$record = include $path;
		if ( ! is_array( $record ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
		$material = self::master_material( $record['material'] ?? '' );
		$key_id = trim( (string) ( $record['key_id'] ?? '' ) );
		if ( false === $material || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
		self::$managed_keyring_cache = array( 'material' => $material, 'key_id' => $key_id, 'mode' => 'managed_file' );
		return self::$managed_keyring_cache;
	}

	private static function configured_key_record() {
		// Explicit deployment configuration outranks the managed shared-host fallback.
		// The managed generation is still retained in envelope_keyring() so data
		// encrypted before a controlled migration remains decryptable.
		if ( defined( 'SMC_MASTER_KEY' ) && false !== ( $material = self::master_material( SMC_MASTER_KEY ) ) && defined( 'SMC_MASTER_KEY_ID' ) && is_string( SMC_MASTER_KEY_ID ) ) {
			$key_id = trim( SMC_MASTER_KEY_ID );
			if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ) { return array( 'material' => $material, 'key_id' => $key_id, 'mode' => 'constant' ); }
		}
		$managed = self::managed_keyring();
		return ! empty( $managed ) ? $managed : array();
	}

	/** Provision a durable per-site keyring outside the database when constants are absent. */
	public static function ensure_key_ready() {
		if ( ! self::crypto_ready() ) { return new WP_Error( 'smc_crypto_unavailable', __( 'OpenSSL AES-256-GCM support is required before File 00 can process protected identity data.', 'sabri-membership-core' ) ); }
		if ( ! empty( self::configured_key_record() ) ) { return true; }
		$dir = wp_normalize_path( WP_CONTENT_DIR . '/sabri-private-keys' );
		if ( is_link( $dir ) ) { return new WP_Error( 'smc_keyring_symlink', __( 'The private key directory cannot be a symbolic link.', 'sabri-membership-core' ) ); }
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'smc_keyring_directory', __( 'The private key directory could not be created.', 'sabri-membership-core' ) ); }
		@chmod( $dir, 0700 );
		$dir_mode = @fileperms( $dir );
		if ( false !== $dir_mode && 0700 !== ( $dir_mode & 0777 ) ) { return new WP_Error( 'smc_keyring_permissions', __( 'The private key directory must enforce owner-only 0700 permissions.', 'sabri-membership-core' ) ); }
		if ( ! is_writable( $dir ) ) { return new WP_Error( 'smc_keyring_not_writable', __( 'The private key directory is not writable by WordPress.', 'sabri-membership-core' ) ); }
		$lock_path = $dir . '/file00-keyring.lock';
		$lock = @fopen( $lock_path, 'c' );
		if ( is_resource( $lock ) ) { @chmod( $lock_path, 0600 ); }
		if ( false === $lock || ! @flock( $lock, LOCK_EX ) ) { if ( is_resource( $lock ) ) { fclose( $lock ); } return new WP_Error( 'smc_keyring_lock', __( 'The private keyring could not acquire an exclusive provisioning lock.', 'sabri-membership-core' ) ); }
		self::$managed_keyring_cache = null;
		if ( ! empty( self::configured_key_record() ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return true; }
		$material = random_bytes( 32 );
		$key_id = 'managed-' . gmdate( 'Ymd' ) . '-' . substr( hash( 'sha256', $material ), 0, 12 );
		$encoded = 'base64:' . base64_encode( $material );
		$payload = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn " . var_export( array( 'key_id' => $key_id, 'material' => $encoded ), true ) . ";\n";
		$path = self::managed_keyring_path();
		$temp = $path . '.tmp-' . wp_generate_password( 12, false, false );
		if ( false === file_put_contents( $temp, $payload, LOCK_EX ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_write', __( 'The private key file could not be written.', 'sabri-membership-core' ) ); }
		@chmod( $temp, 0600 );
		if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_commit', __( 'The private key file could not be committed atomically.', 'sabri-membership-core' ) ); }
		@chmod( $path, 0600 );
		$deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		if ( ! file_exists( $dir . '/.htaccess' ) ) { @file_put_contents( $dir . '/.htaccess', $deny, LOCK_EX ); }
		if ( ! file_exists( $dir . '/index.php' ) ) { @file_put_contents( $dir . '/index.php', "<?php\nhttp_response_code( 404 );\nexit;\n", LOCK_EX ); }
		self::$managed_keyring_cache = null;
		$record = self::managed_keyring();
		if ( empty( $record ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_verify', __( 'The newly created private key file could not be verified.', 'sabri-membership-core' ) ); }
		update_option( 'smc_keyring_mode', 'managed_file', false );
		@flock( $lock, LOCK_UN ); fclose( $lock );
		return true;
	}

	public static function key_ready() { return self::crypto_ready() && ! empty( self::configured_key_record() ); }

	/** Legacy purpose/index/audit key retained until the explicit index/audit migration completes. */
	private static function key() {
		// Blind indexes and the existing audit chain must not silently change key
		// when a managed shared-host installation later adopts an explicit SMC3
		// envelope key. Keep the managed generation as the legacy integrity/index
		// key until an explicit reindex/audit migration is performed.
		$record = self::managed_keyring();
		if ( empty( $record ) ) { $record = self::configured_key_record(); }
		if ( empty( $record ) ) { return new WP_Error( 'smc_key_missing', __( 'File 00 encryption key material is not configured yet.', 'sabri-membership-core' ) ); }
		$salt = 'constant' === ( $record['mode'] ?? '' ) ? ( defined( 'SMC_LEGACY_AUTH_SALT' ) && is_string( SMC_LEGACY_AUTH_SALT ) && '' !== SMC_LEGACY_AUTH_SALT ? SMC_LEGACY_AUTH_SALT : wp_salt( 'auth' ) ) : '';
		return hash_hkdf( 'sha256', $record['material'], 32, 'sabri-membership-core:v2', $salt );
	}

	public static function key_id() { $record = self::configured_key_record(); return empty( $record ) ? '' : (string) $record['key_id']; }

	private static function envelope_keyring() {
		$ring = array();
		$current = self::configured_key_record();
		if ( ! empty( $current ) ) { $ring[ $current['key_id'] ] = array( 'material' => $current['material'], 'legacy_auth_salt' => 'constant' === ( $current['mode'] ?? '' ) ? ( defined( 'SMC_LEGACY_AUTH_SALT' ) ? (string) SMC_LEGACY_AUTH_SALT : wp_salt( 'auth' ) ) : '' ); }
		$managed = self::managed_keyring();
		if ( ! empty( $managed ) && ! isset( $ring[ $managed['key_id'] ] ) ) {
			$ring[ $managed['key_id'] ] = array( 'material' => $managed['material'], 'legacy_auth_salt' => '' );
		}
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'smc_encryption_keyring_v1', array() ) : array();
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
		if ( ! self::crypto_ready() ) { return new WP_Error( 'smc_crypto_unavailable', __( 'Required authenticated-encryption support is unavailable.', 'sabri-membership-core' ) ); }
		$key_id = self::key_id();
		if ( '' === $key_id ) { return new WP_Error( 'smc_key_id_missing', __( 'SMC_MASTER_KEY_ID must identify the active non-secret encryption key generation.', 'sabri-membership-core' ) ); }
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
		if ( ! self::crypto_ready() ) { return new WP_Error( 'smc_crypto_unavailable', __( 'Required authenticated-encryption support is unavailable.', 'sabri-membership-core' ) ); }
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
		return is_wp_error( $key ) ? $key : hash_hmac( 'sha256', sanitize_key( $purpose ) . '|' . SMC_Host_Compat::lowercase( trim( (string) $value ) ), $key );
	}

	public static function decrypt_legacy_value( $encoded, $purpose = 'identity' ) {
		if ( ! self::crypto_ready() ) { return new WP_Error( 'smc_crypto_unavailable', __( 'Required authenticated-encryption support is unavailable.', 'sabri-membership-core' ) ); }
		$payload = base64_decode( (string) $encoded, true );
		if ( false === $payload || strlen( $payload ) < 32 || 'SMC1' !== substr( $payload, 0, 4 ) ) {
			return new WP_Error( 'smc_legacy_envelope', __( 'Legacy encrypted data is malformed.', 'sabri-membership-core' ) );
		}
		$legacy_master = defined( 'SMC_MASTER_KEY' ) && SMC_MASTER_KEY ? SMC_MASTER_KEY : AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY;
		$legacy_key = hash_hmac( 'sha256', sanitize_key( $purpose ), $legacy_master, true );
		$plain = openssl_decrypt( substr( $payload, 32 ), 'aes-256-gcm', $legacy_key, OPENSSL_RAW_DATA, substr( $payload, 4, 12 ), substr( $payload, 16, 16 ) );
		return false === $plain ? new WP_Error( 'smc_legacy_authentication', __( 'Legacy encrypted data authentication failed.', 'sabri-membership-core' ) ) : $plain;
	}

	private static function decrypt_legacy_document_bytes( $encoded ) {
		$smc1 = self::decrypt_legacy_value( $encoded, 'private-document' );
		if ( ! is_wp_error( $smc1 ) ) {
			return $smc1;
		}
		if ( strlen( (string) $encoded ) < 29 ) {
			return $smc1;
		}
		$key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . 'sabri-private-documents', true );
		$plain = openssl_decrypt( substr( $encoded, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $encoded, 0, 12 ), substr( $encoded, 12, 16 ) );
		return false === $plain ? $smc1 : $plain;
	}

	public static function migrate_legacy_document( $document ) {
		if ( ! is_array( $document ) || empty( $document['id'] ) || empty( $document['user_id'] ) || empty( $document['stored_name'] ) ) {
			return new WP_Error( 'smc_legacy_document', __( 'Legacy document metadata is incomplete.', 'sabri-membership-core' ) );
		}
		$legacy_dir = wp_normalize_path( WP_CONTENT_DIR . '/sabri-private-documents' );
		$source = trailingslashit( $legacy_dir ) . basename( $document['stored_name'] );
		if ( is_link( $legacy_dir ) || is_link( $source ) ) {
			return new WP_Error( 'smc_legacy_symlink', __( 'Legacy evidence uses an unsafe symbolic link.', 'sabri-membership-core' ) );
		}
		if ( 'passed' === ( $document['scan_status'] ?? '' ) && ! empty( $document['plain_sha256'] ) ) {
			return ! file_exists( $source ) || self::verified_unlink( $source )
				? true
				: new WP_Error( 'smc_legacy_cleanup', __( 'Migrated legacy ciphertext could not be verified as deleted.', 'sabri-membership-core' ) );
		}
		if ( ! is_file( $source ) ) {
			return new WP_Error( 'smc_legacy_missing', __( 'A referenced legacy evidence file is missing.', 'sabri-membership-core' ) );
		}
		$encoded = file_get_contents( $source );
		$plain = false === $encoded ? new WP_Error( 'smc_legacy_read', __( 'Legacy evidence could not be read.', 'sabri-membership-core' ) ) : self::decrypt_legacy_document_bytes( $encoded );
		if ( is_wp_error( $plain ) ) {
			return $plain;
		}
		$temp = wp_tempnam( 'smc-legacy-scan' );
		if ( ! $temp || false === file_put_contents( $temp, $plain, LOCK_EX ) || ! @chmod( $temp, 0600 ) ) {
			return new WP_Error( 'smc_legacy_scan_temp', __( 'Legacy evidence could not be prepared for scanning.', 'sabri-membership-core' ) );
		}
		clearstatcache( true, $temp );
		if ( 0600 !== ( fileperms( $temp ) & 0777 ) ) {
			self::verified_unlink( $temp );
			return new WP_Error( 'smc_legacy_scan_mode', __( 'Legacy scan preparation could not enforce mode 0600.', 'sabri-membership-core' ) );
		}
		$scan = apply_filters( 'smc_document_scan', null, $temp, $document['mime_type'], (int) $document['user_id'], $document['document_key'] );
		if ( ! self::verified_unlink( $temp ) ) {
			return new WP_Error( 'smc_legacy_plaintext_cleanup', __( 'Temporary legacy plaintext could not be verified as deleted.', 'sabri-membership-core' ) );
		}
		if ( true !== $scan ) {
			return new WP_Error( 'smc_legacy_scan', __( 'Legacy evidence did not pass the required scanner.', 'sabri-membership-core' ) );
		}
		$stored = self::store_document_bytes( $plain, $document['mime_type'], $document['original_name'], $document['label'], (int) $document['user_id'], $document['document_key'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! self::verified_unlink( $source ) ) {
			return new WP_Error( 'smc_legacy_cleanup', __( 'Legacy evidence was migrated, but the former ciphertext could not be verified as deleted.', 'sabri-membership-core' ) );
		}
		return true;
	}

	private static function path_is_within( $path, $root ) {
		$path = wp_normalize_path( $path );
		$root = trailingslashit( wp_normalize_path( $root ) );
		return 0 === strpos( trailingslashit( $path ), $root );
	}

	private static function reject_symlink_path( $path ) {
		$path = wp_normalize_path( $path );
		$parts = explode( '/', ltrim( $path, '/' ) );
		$cursor = 0 === strpos( $path, '/' ) ? '/' : '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$cursor = trailingslashit( $cursor ) . $part;
			if ( file_exists( $cursor ) && is_link( $cursor ) ) {
				return new WP_Error( 'smc_symlink', __( 'Private storage cannot contain filesystem symbolic links.', 'sabri-membership-core' ) );
			}
		}
		return true;
	}

	public static function private_dir() {
		$configured = defined( 'SMC_PRIVATE_STORAGE_DIR' ) ? SMC_PRIVATE_STORAGE_DIR : trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . 'sabri-private/smc';
		$dir = wp_normalize_path( trim( (string) $configured ) );
		if ( '' === $dir || false !== strpos( $dir, "\0" ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $dir ) || ! preg_match( '#^(?:[A-Za-z]:/|/)#', $dir ) ) {
			return new WP_Error( 'smc_storage_path', __( 'Private membership storage must use an absolute canonical path without dot segments.', 'sabri-membership-core' ) );
		}
		if ( self::path_is_within( $dir, ABSPATH ) || self::path_is_within( $dir, WP_CONTENT_DIR ) ) {
			return new WP_Error( 'smc_public_storage', __( 'Private membership storage must be outside the public WordPress and wp-content directories.', 'sabri-membership-core' ) );
		}
		$safe = self::reject_symlink_path( $dir );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'smc_storage_create', __( 'Private membership storage could not be created.', 'sabri-membership-core' ) );
		}
		$real = realpath( $dir );
		if ( false === $real ) {
			return new WP_Error( 'smc_storage_realpath', __( 'Private membership storage could not be resolved canonically.', 'sabri-membership-core' ) );
		}
		$dir = wp_normalize_path( $real );
		if ( self::path_is_within( $dir, realpath( ABSPATH ) ?: ABSPATH ) || self::path_is_within( $dir, realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR ) ) {
			return new WP_Error( 'smc_public_storage', __( 'Private membership storage resolves inside a public WordPress directory.', 'sabri-membership-core' ) );
		}
		$safe = self::reject_symlink_path( $dir );
		if ( is_wp_error( $safe ) || is_link( $dir ) || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return is_wp_error( $safe ) ? $safe : new WP_Error( 'smc_storage_invalid', __( 'Private membership storage is unsafe or not writable.', 'sabri-membership-core' ) );
		}
		@chmod( $dir, 0700 );
		clearstatcache( true, $dir );
		if ( 0700 !== ( fileperms( $dir ) & 0777 ) ) {
			return new WP_Error( 'smc_storage_mode', __( 'Private membership storage must enforce mode 0700.', 'sabri-membership-core' ) );
		}
		$marker_payload = "<?php http_response_code(403); exit;\n";
		$marker_mac = self::blind_index( $marker_payload . '|' . $dir, 'storage-marker' );
		if ( is_wp_error( $marker_mac ) ) {
			return $marker_mac;
		}
		$markers = array(
			'index.php'   => $marker_payload,
			'.htaccess'   => "Deny from all\n",
			'web.config'  => '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'.smc-marker' => self::canonical_json( array( 'v' => 2, 'mac' => $marker_mac ) ),
		);
		foreach ( $markers as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( is_link( $path ) ) {
				return new WP_Error( 'smc_marker_symlink', __( 'A private-storage protection marker is a symbolic link.', 'sabri-membership-core' ) );
			}
			if ( ! file_exists( $path ) || ! hash_equals( hash( 'sha256', $contents ), (string) hash_file( 'sha256', $path ) ) ) {
				$written = self::atomic_write( $path, $contents, true );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
			@chmod( $path, 0600 );
			clearstatcache( true, $path );
			if ( 0600 !== ( fileperms( $path ) & 0777 ) ) {
				return new WP_Error( 'smc_marker_mode', __( 'Private-storage markers must enforce mode 0600.', 'sabri-membership-core' ) );
			}
		}
		return $dir;
	}

	private static function atomic_write( $target, $contents, $allow_marker = false ) {
		$dir = dirname( $target );
		if ( is_link( $target ) || is_link( $dir ) || ( ! $allow_marker && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.smcdoc$/', basename( $target ) ) ) ) {
			return new WP_Error( 'smc_atomic_target', __( 'Unsafe private file target.', 'sabri-membership-core' ) );
		}
		$temp = trailingslashit( $dir ) . '.smc-tmp-' . wp_generate_uuid4();
		$handle = @fopen( $temp, 'xb' );
		if ( ! $handle ) {
			return new WP_Error( 'smc_atomic_open', __( 'A secure temporary file could not be created.', 'sabri-membership-core' ) );
		}
		$ok = false;
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new RuntimeException( 'Could not lock the secure temporary file.' );
			}
			$length = strlen( $contents );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $contents, $offset ) );
				if ( false === $written || 0 === $written ) {
					throw new RuntimeException( 'Could not finish writing the secure temporary file.' );
				}
				$offset += $written;
			}
			if ( ! fflush( $handle ) ) {
				throw new RuntimeException( 'Could not flush the secure temporary file.' );
			}
			if ( function_exists( 'fsync' ) && ! fsync( $handle ) ) {
				throw new RuntimeException( 'Could not synchronize the secure temporary file.' );
			}
			if ( ! @chmod( $temp, 0600 ) ) {
				throw new RuntimeException( 'Could not protect the secure temporary file.' );
			}
			flock( $handle, LOCK_UN );
			fclose( $handle );
			$handle = null;
			clearstatcache( true, $temp );
			if ( 0600 !== ( fileperms( $temp ) & 0777 ) || filesize( $temp ) !== $length ) {
				throw new RuntimeException( 'Secure temporary file verification failed.' );
			}
			if ( ! @rename( $temp, $target ) ) {
				throw new RuntimeException( 'Atomic secure-file replacement failed.' );
			}
			clearstatcache( true, $target );
			$ok = is_file( $target ) && ! is_link( $target ) && 0600 === ( fileperms( $target ) & 0777 ) && filesize( $target ) === $length;
		} catch ( Throwable $error ) {
			self::audit( 'atomic_write_failed', 0, array( 'reason' => $error->getMessage() ) );
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( file_exists( $temp ) && ! is_link( $temp ) ) {
				@unlink( $temp );
			}
		}
		return $ok ? true : new WP_Error( 'smc_atomic_write', __( 'The private file could not be written and verified atomically.', 'sabri-membership-core' ) );
	}

	private static function document_lock( $user_id, $document_key, $timeout = 5 ) {
		global $wpdb;
		$name = 'smc_doc_' . substr( hash( 'sha256', absint( $user_id ) . '|' . sanitize_key( $document_key ) ), 0, 40 );
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, absint( $timeout ) ) );
		return 1 === $got ? $name : new WP_Error( 'smc_document_busy', __( 'This document is already being updated. Please try again.', 'sabri-membership-core' ) );
	}

	private static function release_document_lock( $name ) {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	private static function sanitize_image( $path, $mime ) {
		$info = @getimagesize( $path );
		if ( ! is_array( $info ) || $info[0] < 500 || $info[1] < 300 || $info[0] * $info[1] > 40000000 ) {
			return new WP_Error( 'smc_image', __( 'The image is invalid, too small, or exceeds safe dimensions.', 'sabri-membership-core' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$temp = wp_tempnam( 'smc-sanitized-image' );
		if ( ! $temp ) {
			return new WP_Error( 'smc_image_temp', __( 'A secure image-sanitization file could not be created.', 'sabri-membership-core' ) );
		}
		$saved_path = '';
		$bytes = false;
		$error = null;
		$saved = $editor->save( $temp, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			$error = new WP_Error( 'smc_image_sanitize', __( 'The image could not be safely re-encoded.', 'sabri-membership-core' ) );
		} else {
			$saved_path = wp_normalize_path( $saved['path'] );
			$bytes = file_get_contents( $saved_path );
			if ( false === $bytes ) {
				$error = new WP_Error( 'smc_image_read', __( 'The sanitized image could not be read.', 'sabri-membership-core' ) );
			}
		}
		$cleanup_ok = true;
		foreach ( array_unique( array_filter( array( wp_normalize_path( $temp ), $saved_path ) ) ) as $plain_path ) {
			if ( file_exists( $plain_path ) && ! self::verified_unlink( $plain_path ) ) {
				$cleanup_ok = false;
			}
		}
		if ( ! $cleanup_ok ) {
			return new WP_Error( 'smc_image_plaintext_cleanup', __( 'Temporary sanitized plaintext could not be verified as deleted.', 'sabri-membership-core' ) );
		}
		return $error ?: $bytes;
	}

	/** Conservative local evidence scanner used only when no external scanner decided. */
	public static function local_document_scan_fallback( $decision, $path, $mime, $user_id, $document_key ) {
		unset( $user_id, $document_key );
		if ( null !== $decision ) { return $decision; }
		$path = wp_normalize_path( (string) $path );
		$mime = sanitize_mime_type( (string) $mime );
		if ( '' === $path || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) { return false; }
		$size = filesize( $path );
		if ( false === $size || $size < 1024 || $size > self::MAX_FILE ) { return false; }
		$bytes = file_get_contents( $path );
		if ( false === $bytes ) { return false; }
		$lower = strtolower( $bytes );
		foreach ( array( '<?php', '<?=', '<script', 'javascript:', 'data:text/html', 'x5o!p%@ap[4\\pzx54(p^)7cc)7}$eicar-standard-antivirus-test-file!$h+h*' ) as $marker ) { if ( false !== strpos( $lower, strtolower( $marker ) ) ) { return false; } }
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$info = @getimagesize( $path );
			if ( ! is_array( $info ) || empty( $info['mime'] ) || $mime !== sanitize_mime_type( $info['mime'] ) ) { return false; }
			return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
		}
		if ( 'application/pdf' === $mime ) {
			// A substring inspection is not a malware scanner: active PDF objects can
			// be compressed or obfuscated. PDFs therefore remain fail-closed unless
			// an earlier approved scanner adapter returned true.
			return false;
		}
		return false;
	}

	/**
	 * Whether a current, scanner-passed evidence item is already durably stored.
	 * This is used for interrupted-application recovery so a user does not need
	 * to re-upload evidence that the server already accepted.
	 */
	public static function has_current_document( $user_id, $document_key ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$document_key = sanitize_key( $document_key );
		if ( ! $user_id || '' === $document_key ) { return false; }
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stored_name FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d AND document_key=%s AND scan_status='passed' AND status IN ('submitted','approved') AND (expiry_date IS NULL OR expiry_date>=UTC_DATE()) LIMIT 1",
				$user_id,
				$document_key
			),
			ARRAY_A
		);
		if ( ! $row || empty( $row['stored_name'] ) ) { return false; }
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) { return false; }
		$path = trailingslashit( $dir ) . basename( $row['stored_name'] );
		return is_file( $path ) && ! is_link( $path ) && is_readable( $path );
	}

	public static function store_uploaded_document( $field, $label, $user_id, $document_key ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
			return new WP_Error( 'smc_upload_missing', sprintf( __( '%s is required.', 'sabri-membership-core' ), $label ) );
		}
		$file = $_FILES[ $field ];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'smc_upload_failed', sprintf( __( '%s could not be uploaded.', 'sabri-membership-core' ), $label ) );
		}
		if ( (int) $file['size'] < 1024 || (int) $file['size'] > self::MAX_FILE ) {
			return new WP_Error( 'smc_upload_size', __( 'Identity evidence must be between 1 KB and 8 MB.', 'sabri-membership-core' ) );
		}
		$allowed = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' );
		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), $allowed );
		$mime = isset( $check['type'] ) ? $check['type'] : '';
		if ( ! in_array( $mime, array_values( $allowed ), true ) || ( 'identity_selfie' === $document_key && 'application/pdf' === $mime ) ) {
			return new WP_Error( 'smc_upload_type', __( 'Use a valid JPG, PNG, WebP, or permitted PDF identity document.', 'sabri-membership-core' ) );
		}
		$scan = apply_filters( 'smc_document_scan', null, $file['tmp_name'], $mime, absint( $user_id ), sanitize_key( $document_key ) );
		if ( true !== $scan ) {
			return new WP_Error( 'smc_scan_required', __( 'The evidence file was not accepted by the required malware and active-content scanner.', 'sabri-membership-core' ) );
		}
		$bytes = 0 === strpos( $mime, 'image/' ) ? self::sanitize_image( $file['tmp_name'], $mime ) : file_get_contents( $file['tmp_name'] );
		if ( is_wp_error( $bytes ) || false === $bytes ) {
			return is_wp_error( $bytes ) ? $bytes : new WP_Error( 'smc_upload_read', __( 'The evidence file could not be read.', 'sabri-membership-core' ) );
		}
		return self::store_document_bytes( $bytes, $mime, sanitize_file_name( $file['name'] ), $label, $user_id, $document_key );
	}

	public static function store_document_bytes( $bytes, $mime, $original_name, $label, $user_id, $document_key ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$document_key = sanitize_key( $document_key );
		$lock = self::document_lock( $user_id, $document_key );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			self::release_document_lock( $lock );
			return $dir;
		}
		$stored = wp_generate_uuid4() . '.smcdoc';
		$lease_id = wp_generate_uuid4();
		$path = trailingslashit( $dir ) . $stored;
		$lease_path = $path . '.lease';
		$old = null;
		$committed = false;
		try {
			$old = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE user_id=%d AND document_key=%s LIMIT 1",
					$user_id,
					$document_key
				),
				ARRAY_A
			);
			$version = $old ? (int) $old['version'] + 1 : 1;
			$sha = hash( 'sha256', $bytes );
			$context = array( 'user_id' => $user_id, 'document_key' => $document_key, 'version' => $version, 'mime' => $mime, 'sha256' => $sha );
			$encrypted = self::encrypt( $bytes, 'identity-document', $context );
			if ( is_wp_error( $encrypted ) ) {
				throw new RuntimeException( $encrypted->get_error_message() );
			}
			$lease = self::canonical_json( array( 'lease_id' => $lease_id, 'stored_name' => $stored, 'expires' => time() + DAY_IN_SECONDS ) );
			$lease_mac = self::blind_index( $lease, 'prepared-document-lease' );
			if ( is_wp_error( $lease_mac ) ) {
				throw new RuntimeException( $lease_mac->get_error_message() );
			}
			$written = self::atomic_write( $lease_path, $lease . "\n" . $lease_mac, true );
			if ( is_wp_error( $written ) ) {
				throw new RuntimeException( $written->get_error_message() );
			}
			$written = self::atomic_write( $path, $encrypted );
			if ( is_wp_error( $written ) ) {
				throw new RuntimeException( $written->get_error_message() );
			}
			$wpdb->query( 'START TRANSACTION' );
			$now = current_time( 'mysql', true );
			if ( $old ) {
				$ok = $wpdb->update(
					$wpdb->prefix . 'smc_identity_documents',
					array(
						'version'       => $version,
						'label'         => sanitize_text_field( $label ),
						'stored_name'   => $stored,
						'original_name' => sanitize_file_name( $original_name ),
						'mime_type'     => sanitize_mime_type( $mime ),
						'file_size'     => strlen( $bytes ),
						'plain_sha256'  => $sha,
						'scan_status'   => 'passed',
						'status'        => 'submitted',
						'reviewed_by'   => 0,
						'reviewed_at'   => null,
						'reviewer_note' => null,
						'lease_id'      => $lease_id,
						'updated_at'    => $now,
					),
					array( 'id' => (int) $old['id'], 'version' => (int) $old['version'] ),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
					array( '%d', '%d' )
				);
			} else {
				$ok = $wpdb->insert(
					$wpdb->prefix . 'smc_identity_documents',
					array(
						'user_id'       => $user_id,
						'document_key'  => $document_key,
						'version'       => $version,
						'label'         => sanitize_text_field( $label ),
						'stored_name'   => $stored,
						'original_name' => sanitize_file_name( $original_name ),
						'mime_type'     => sanitize_mime_type( $mime ),
						'file_size'     => strlen( $bytes ),
						'plain_sha256'  => $sha,
						'scan_status'   => 'passed',
						'status'        => 'submitted',
						'lease_id'      => $lease_id,
						'created_at'    => $now,
						'updated_at'    => $now,
					),
					array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
			if ( 1 !== $ok ) {
				throw new RuntimeException( 'The document database record could not be committed.' );
			}
			if ( ! self::audit( 'identity_document_stored', $user_id, array( 'document_key' => $document_key, 'version' => $version ) ) ) {
				throw new RuntimeException( 'The document audit evidence could not be committed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The document database transaction could not be committed.' );
			}
			$committed = true;
			if ( ! self::verified_unlink( $lease_path ) ) {
				self::queue_file_job( basename( $lease_path ), 'delete_lease', $lease_id, 'lease_cleanup_after_commit' );
			}
			if ( $old && ! empty( $old['stored_name'] ) && $old['stored_name'] !== $stored ) {
				$old_path = trailingslashit( $dir ) . basename( $old['stored_name'] );
				if ( ! self::verified_unlink( $old_path ) ) {
					self::queue_file_job( basename( $old_path ), 'delete_superseded', (string) $old['lease_id'], 'document_replacement' );
					self::audit( 'identity_document_cleanup_pending', $user_id, array( 'document_key' => $document_key, 'superseded_name_hash' => hash( 'sha256', basename( $old_path ) ) ) );
				}
				self::verified_unlink( $old_path . '.lease' );
			}
			return true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( ! $committed ) {
				if ( ! self::verified_unlink( $path ) ) {
					self::queue_file_job( basename( $path ), 'delete_failed_upload', $lease_id, $error->getMessage() );
				}
				if ( ! self::verified_unlink( $lease_path ) ) {
					self::queue_file_job( basename( $lease_path ), 'delete_lease', $lease_id, $error->getMessage() );
				}
			}
			self::audit( 'identity_document_store_failed', $user_id, array( 'document_key' => $document_key, 'reason' => $error->getMessage() ) );
			return new WP_Error( 'smc_document_store', $error->getMessage() );
		} finally {
			if ( ! self::release_document_lock( $lock ) ) {
				self::audit( 'document_lock_release_failed', $user_id, array( 'document_key' => $document_key ) );
			}
		}
	}

	public static function document_url( $document_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=smc_private_document&document_id=' . absint( $document_id ) ), 'smc_document_' . absint( $document_id ) );
	}

	public static function serve_document() {
		$user_id = get_current_user_id();
		if ( ! current_user_can( 'smc_view_private_documents' ) || ! self::session_is_verified( $user_id ) ) {
			wp_die( esc_html__( 'A current two-factor session and File 00 private-document capability are required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		check_admin_referer( 'smc_document_' . $id );
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE id=%d", $id ), ARRAY_A );
		if ( ! $doc ) {
			wp_die( esc_html__( 'Document not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );
		}
		$governance_scope = current_user_can( 'smc_manage_membership' );
		if ( ! $governance_scope ) {
			$assigned = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}smc_verification_requests WHERE user_id=%d AND assigned_reviewer=%d AND status IN ('submitted','under_review','more_information','resubmitted','approval_pending','appeal_review') ORDER BY id DESC LIMIT 1",
					absint( $doc['user_id'] ),
					$user_id
				)
			);
			if ( ! $assigned ) {
				self::audit( 'private_document_access_denied', (int) $doc['user_id'], array( 'document_id' => $id, 'reason_code' => 'reviewer_not_assigned' ) );
				wp_die( esc_html__( 'This private evidence is outside your assigned review scope.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
			}
		}
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			wp_die( esc_html( $dir->get_error_message() ), '', array( 'response' => 500 ) );
		}
		$path = trailingslashit( $dir ) . basename( $doc['stored_name'] );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			wp_die( esc_html__( 'Encrypted evidence is unavailable.', 'sabri-membership-core' ), '', array( 'response' => 500 ) );
		}
		$encoded = file_get_contents( $path );
		$context = array(
			'user_id'      => (int) $doc['user_id'],
			'document_key' => $doc['document_key'],
			'version'      => (int) $doc['version'],
			'mime'         => $doc['mime_type'],
			'sha256'       => $doc['plain_sha256'],
		);
		$plain = false === $encoded ? new WP_Error( 'smc_read', 'read failed' ) : self::decrypt( $encoded, 'identity-document', $context );
		if ( is_wp_error( $plain ) || ! hash_equals( $doc['plain_sha256'], hash( 'sha256', $plain ) ) ) {
			wp_die( esc_html__( 'Encrypted evidence authentication failed.', 'sabri-membership-core' ), '', array( 'response' => 500 ) );
		}
		if ( ! self::audit( 'private_document_viewed', (int) $doc['user_id'], array( 'document_id' => $id ) ) ) {
			wp_die( esc_html__( 'The access audit could not be recorded, so the document was not released.', 'sabri-membership-core' ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $doc['original_name'] ) . '"' );
		header( 'Content-Length: ' . strlen( $plain ) );
		echo $plain; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function verified_unlink( $path ) {
		if ( is_link( $path ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! is_file( $path ) || ! @unlink( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		return ! file_exists( $path );
	}

	private static function file_job_name_valid( $stored_name, $job_type ) {
		$uuid = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
		$patterns = array(
			'delete_lease'      => '/^' . $uuid . '\\.smcdoc\\.lease$/',
			'delete_quarantine' => '/^' . $uuid . '\\.smcdoc\\.erase-' . $uuid . '$/',
			'delete_superseded' => '/^' . $uuid . '\\.smcdoc$/',
			'delete_failed_upload' => '/^' . $uuid . '\\.smcdoc$/',
			'delete_orphan'     => '/^' . $uuid . '\\.smcdoc$/',
			'privacy_delete'    => '/^' . $uuid . '\\.smcdoc(?:\\.erase-' . $uuid . ')?$/',
		);
		return isset( $patterns[ $job_type ] ) && 1 === preg_match( $patterns[ $job_type ], $stored_name );
	}

	public static function queue_file_job( $stored_name, $job_type, $lease_id, $reason = '' ) {
		global $wpdb;
		$stored_name = basename( (string) $stored_name );
		$job_type = sanitize_key( $job_type );
		if ( ! self::file_job_name_valid( $stored_name, $job_type ) ) {
			self::audit( 'file_job_rejected', 0, array( 'type' => $job_type, 'name_hash' => hash( 'sha256', $stored_name ) ) );
			return false;
		}
		$path_hash = self::blind_index( $stored_name, 'file-job' );
		if ( is_wp_error( $path_hash ) ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_file_jobs
			(stored_name,path_hash,job_type,lease_id,status,attempts,next_attempt_at,last_error,created_at,updated_at)
			VALUES (%s,%s,%s,%s,'pending',0,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE status='pending',next_attempt_at=VALUES(next_attempt_at),last_error=VALUES(last_error),updated_at=VALUES(updated_at)",
			$stored_name,
			$path_hash,
			$job_type,
			sanitize_text_field( $lease_id ),
			$now,
			sanitize_text_field( $reason ),
			$now,
			$now
		);
		return false !== $wpdb->query( $sql );
	}

	public static function process_file_jobs() {
		if ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }
		global $wpdb;
		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_file_jobs WHERE ((status IN ('pending','retry') AND next_attempt_at<=%s) OR (status='processing' AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 MINUTE)) ORDER BY id LIMIT 25",
				$now
			),
			ARRAY_A
		);
		foreach ( $jobs as $job ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_file_jobs SET status='processing',updated_at=%s WHERE id=%d AND ((status IN ('pending','retry') AND next_attempt_at<=%s) OR (status='processing' AND updated_at<UTC_TIMESTAMP() - INTERVAL 30 MINUTE))", $now, (int) $job['id'], $now ) );
			if ( 1 !== $claimed ) {
				continue;
			}
			$name = basename( (string) $job['stored_name'] );
			$expected_hash = self::blind_index( $name, 'file-job' );
			$valid = ! is_wp_error( $expected_hash ) && hash_equals( (string) $expected_hash, (string) $job['path_hash'] ) && self::file_job_name_valid( $name, sanitize_key( $job['job_type'] ) );
			if ( $valid && preg_match( '/\\.smcdoc$/', $name ) ) {
				$referenced = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_identity_documents WHERE stored_name=%s LIMIT 1", $name ) );
				$valid = ! $referenced;
			}
			$path = trailingslashit( $dir ) . $name;
			$ok = $valid && self::path_is_within( $path, $dir ) && self::verified_unlink( $path );
			if ( $ok && 'delete_orphan' === sanitize_key( $job['job_type'] ) ) {
				$lease_path = $path . '.lease';
				$ok = ! file_exists( $lease_path ) || ( self::path_is_within( $lease_path, $dir ) && self::verified_unlink( $lease_path ) );
			}
			$attempts = (int) $job['attempts'] + 1;
			$status = $ok ? 'complete' : ( $attempts >= 10 || ! $valid ? 'failed' : 'retry' );
			$wpdb->update(
				$wpdb->prefix . 'smc_file_jobs',
				array(
					'status'          => $status,
					'attempts'        => $attempts,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + min( DAY_IN_SECONDS, 300 * ( 2 ** min( $attempts, 8 ) ) ) ),
					'last_error'      => $ok ? null : ( $valid ? 'Verified deletion failed.' : 'File-job integrity validation failed.' ),
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $job['id'], 'status' => 'processing' ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( 'failed' === $status ) {
				self::audit( 'file_job_permanently_failed', 0, array( 'job_id' => (int) $job['id'], 'type' => $job['job_type'], 'integrity_valid' => $valid ) );
			}
		}
	}

	public static function base32_secret( $length = 32 ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, 31 ) ];
		}
		return $out;
	}

	private static function base32_decode( $secret ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret = strtoupper( preg_replace( '/[^A-Z2-7]/i', '', (string) $secret ) );
		$bits = '';
		foreach ( str_split( $secret ) as $char ) {
			$position = strpos( $alphabet, $char );
			if ( false === $position ) {
				return '';
			}
			$bits .= str_pad( decbin( $position ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		for ( $i = 0; $i + 8 <= strlen( $bits ); $i += 8 ) {
			$out .= chr( bindec( substr( $bits, $i, 8 ) ) );
		}
		return $out;
	}

	private static function totp( $secret, $slice ) {
		$counter = pack( 'N*', 0 ) . pack( 'N*', $slice );
		$hash = hash_hmac( 'sha1', $counter, self::base32_decode( $secret ), true );
		$offset = ord( substr( $hash, -1 ) ) & 0x0f;
		$value = unpack( 'N', substr( $hash, $offset, 4 ) )[1] & 0x7fffffff;
		return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	private static function matching_totp_slice( $secret, $code ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 6 !== strlen( $code ) || ! $secret ) {
			return false;
		}
		$current = (int) floor( time() / 30 );
		foreach ( array( -1, 0, 1 ) as $window ) {
			$slice = $current + $window;
			if ( hash_equals( self::totp( $secret, $slice ), $code ) ) {
				return $slice;
			}
		}
		return false;
	}

	public static function verify_setup_code( $secret, $code ) {
		return false !== self::matching_totp_slice( $secret, $code );
	}

	public static function set_two_factor_secret( $user_id, $secret ) {
		$encrypted = self::encrypt( $secret, 'totp-secret', array( 'user_id' => absint( $user_id ) ) );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		if ( ! update_user_meta( $user_id, '_smc_totp_secret_enc', $encrypted ) && get_user_meta( $user_id, '_smc_totp_secret_enc', true ) !== $encrypted ) {
			return new WP_Error( 'smc_totp_store', __( 'The two-factor secret could not be stored.', 'sabri-membership-core' ) );
		}
		delete_user_meta( $user_id, '_smc_totp_secret' );
		return true;
	}

	private static function two_factor_secret( $user_id ) {
		$encrypted = get_user_meta( $user_id, '_smc_totp_secret_enc', true );
		if ( ! $encrypted ) {
			return new WP_Error( 'smc_totp_missing', __( 'Two-factor authentication is not configured.', 'sabri-membership-core' ) );
		}
		return self::decrypt( $encrypted, 'totp-secret', array( 'user_id' => absint( $user_id ) ) );
	}

	public static function two_factor_ready( $user_id ) {
		return (bool) get_user_meta( $user_id, '_smc_2fa_enabled', true ) && ! is_wp_error( self::two_factor_secret( $user_id ) );
	}

	private static function transaction_active() {
		global $wpdb;
		$value = $wpdb->get_var( 'SELECT @@session.in_transaction' );
		return false !== $value && null !== $value && 1 === (int) $value;
	}

	private static function session_token_meta_key( $token_hash ) {
		return '_smc_session_token_' . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token_hash ) ), 0, 40 );
	}

	private static function store_session_token_envelope( $user_id, $token_hash, $token ) {
		$key = self::session_token_meta_key( $token_hash );
		$envelope = self::encrypt( $token, 'session-token-envelope', array( 'user_id' => absint( $user_id ), 'token_hash' => (string) $token_hash ) );
		if ( is_wp_error( $envelope ) ) {
			return false;
		}
		update_user_meta( absint( $user_id ), $key, $envelope );
		return hash_equals( (string) $envelope, (string) get_user_meta( absint( $user_id ), $key, true ) );
	}

	private static function session_token_from_hash( $user_id, $token_hash ) {
		$envelope = get_user_meta( absint( $user_id ), self::session_token_meta_key( $token_hash ), true );
		if ( ! is_string( $envelope ) || '' === $envelope ) {
			return new WP_Error( 'smc_session_token_missing', __( 'The exact WordPress session token is unavailable.', 'sabri-membership-core' ) );
		}
		return self::decrypt( $envelope, 'session-token-envelope', array( 'user_id' => absint( $user_id ), 'token_hash' => (string) $token_hash ) );
	}

	public static function delete_session_token_envelope( $user_id, $token_hash ) {
		$key = self::session_token_meta_key( $token_hash );
		delete_user_meta( absint( $user_id ), $key );
		return ! metadata_exists( 'user', absint( $user_id ), $key );
	}

	public static function sweep_session_token_envelopes( $user_id, $live_hashes = array() ) {
		$live = array_fill_keys( array_map( 'strtolower', array_filter( (array) $live_hashes ) ), true );
		$ok = true;
		foreach ( array_keys( get_user_meta( absint( $user_id ) ) ) as $meta_key ) {
			if ( 0 !== strpos( $meta_key, '_smc_session_token_' ) ) { continue; }
			$short = substr( $meta_key, strlen( '_smc_session_token_' ) );
			$matched = false;
			foreach ( array_keys( $live ) as $hash ) { if ( hash_equals( substr( $hash, 0, 40 ), $short ) ) { $matched = true; break; } }
			if ( ! $matched ) { delete_user_meta( absint( $user_id ), $meta_key ); $ok = $ok && ! metadata_exists( 'user', absint( $user_id ), $meta_key ); }
		}
		return $ok;
	}

	private static function clear_revalidation_requirement( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' ) ) {
			return true;
		}
		delete_user_meta( $user_id, '_smc_revalidation_required_at' );
		return ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' );
	}

	public static function verify_current_factor_without_session_rotation( $user_id, $code ) {
		$user_id = absint( $user_id );
		$code = trim( (string) $code );
		if ( ! preg_match( '/^[0-9]{6}$/', $code ) ) {
			return self::consume_recovery_code( $user_id, $code );
		}
		$secret = self::two_factor_secret( $user_id );
		if ( is_wp_error( $secret ) ) {
			return false;
		}
		$slice = self::matching_totp_slice( $secret, $code );
		if ( false === $slice ) {
			return false;
		}
		global $wpdb;
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$factor = $wpdb->get_row( $wpdb->prepare( "SELECT user_id,last_totp_slice FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$last = $factor && null !== $factor['last_totp_slice'] ? (int) $factor['last_totp_slice'] : null;
		if ( null !== $last && $last >= (int) $slice ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return false;
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=VALUES(last_totp_slice),updated_at=VALUES(updated_at)", $user_id, $slice, $now ) );
		$audit_ok = false !== $updated && self::audit( 'current_factor_step_up_verified', $user_id, array( 'totp_slice' => (int) $slice ) );
		if ( false === $updated || ! $audit_ok ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return false;
		}
		if ( $owns_transaction && false === $wpdb->query( 'COMMIT' ) ) {
			return false;
		}
		return true;
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
		$factor_state = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=VALUES(last_totp_slice),updated_at=VALUES(updated_at)", $user_id, $slice, current_time('mysql',true) ) );
		if ( false === $factor_state ) { $wpdb->query('ROLLBACK'); return new WP_Error('smc_factor_state_store',__( 'Authenticator replay state could not be stored.', 'sabri-membership-core' )); }
		delete_user_meta( $user_id, '_smc_factor_replace_receipt' );
		if ( ! self::audit( $replacement ? 'two_factor_replaced' : 'two_factor_enabled', $user_id ) ) { $wpdb->query('ROLLBACK'); clean_user_cache($user_id); return new WP_Error('smc_factor_replace_audit',__( 'Factor change could not be audited.', 'sabri-membership-core' ), array( 'audit_error' => self::last_audit_error(), 'audit_health' => self::audit_health_snapshot() )); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { clean_user_cache($user_id); return new WP_Error('smc_factor_commit',__( 'The authenticator change could not be committed.', 'sabri-membership-core' )); }
		clean_user_cache( $user_id );
		if ( ! self::revoke_all_sessions( $user_id, 'two_factor_changed' ) ) {
			update_user_meta( $user_id, '_smc_revalidation_required_at', time() );
			return new WP_Error( 'smc_factor_session_revoke', __( 'The authenticator changed, but session invalidation needs repair. Protected actions remain blocked until you verify again.', 'sabri-membership-core' ) );
		}
		return true;
	}

	public static function register_session( $user_id, $token, $expiration ) {
		$user_id = absint( $user_id );
		$token = (string) $token;
		if ( ! $user_id || '' === $token ) {
			return false;
		}
		global $wpdb;
		$hash = self::blind_index( $token, 'session-token' );
		if ( is_wp_error( $hash ) ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$ip = self::request_hash( 'ip' );
		$device = self::request_hash( 'device' );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_auth_sessions
			(user_id,token_hash,expires_at,two_factor_at,last_totp_slice,ip_hash,device_hash,revoked_at,created_at,updated_at)
			VALUES (%d,%s,%s,NULL,NULL,%s,%s,NULL,%s,%s)
			ON DUPLICATE KEY UPDATE expires_at=IF(revoked_at IS NULL,VALUES(expires_at),expires_at),ip_hash=IF(revoked_at IS NULL,VALUES(ip_hash),ip_hash),device_hash=IF(revoked_at IS NULL,VALUES(device_hash),device_hash),updated_at=IF(revoked_at IS NULL,VALUES(updated_at),updated_at)",
			$user_id,
			$hash,
			gmdate( 'Y-m-d H:i:s', max( time() + MINUTE_IN_SECONDS, (int) $expiration ) ),
			$ip,
			$device,
			$now,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) {
			return false;
		}
		$active = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL LIMIT 1", $user_id, $hash ) );
		if ( ! $active || ! self::store_session_token_envelope( $user_id, $hash, $token ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL", $now, $now, $user_id, $hash ) );
			self::delete_session_token_envelope( $user_id, $hash );
			return false;
		}
		return true;
	}

	public static function session_verified_at( $user_id ) {
		$token = wp_get_session_token();
		if ( ! $token ) {
			return 0;
		}
		$hash = self::blind_index( $token, 'session-token' );
		if ( is_wp_error( $hash ) ) {
			return 0;
		}
		global $wpdb;
		$base_cutoff = time() - 12 * HOUR_IN_SECONDS;
		$required_after = absint( get_user_meta( absint( $user_id ), '_smc_revalidation_required_at', true ) );
		$mfa_cutoff = gmdate( 'Y-m-d H:i:s', max( $base_cutoff, $required_after ) );
		$activity_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		$verified_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT two_factor_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND two_factor_at>=%s AND updated_at>=%s LIMIT 1",
				absint( $user_id ),
				$hash,
				$mfa_cutoff,
				$activity_cutoff
			)
		);
		if ( ! $verified_at ) {
			return 0;
		}
		$timestamp = strtotime( (string) $verified_at . ' UTC' );
		return $timestamp > 0 ? $timestamp : 0;
	}

	public static function session_is_verified( $user_id ) {
		$token = wp_get_session_token();
		if ( ! $token ) {
			return false;
		}
		$hash = self::blind_index( $token, 'session-token' );
		if ( is_wp_error( $hash ) ) {
			return false;
		}
		global $wpdb;
		$base_cutoff = time() - 12 * HOUR_IN_SECONDS;
		$required_after = absint( get_user_meta( absint( $user_id ), '_smc_revalidation_required_at', true ) );
		$mfa_cutoff = gmdate( 'Y-m-d H:i:s', max( $base_cutoff, $required_after ) );
		$activity_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id,updated_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND two_factor_at>=%s AND updated_at>=%s LIMIT 1",
				absint( $user_id ),
				$hash,
				$mfa_cutoff,
				$activity_cutoff
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return false;
		}
		if ( strtotime( (string) $row['updated_at'] . ' UTC' ) < time() - 5 * MINUTE_IN_SECONDS ) {
			return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET updated_at=%s WHERE id=%d AND revoked_at IS NULL", current_time( 'mysql', true ), (int) $row['id'] ) );
		}
		return true;
	}

	public static function verify_two_factor_challenge( $user_id, $code ) {
		$user_id = absint( $user_id );
		if ( self::rate_limited( 'totp|' . $user_id, 7, 900 ) ) {
			return new WP_Error( 'smc_totp_rate', __( 'Too many verification attempts. Please wait and try again.', 'sabri-membership-core' ) );
		}
		$secret = self::two_factor_secret( $user_id );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		$slice = self::matching_totp_slice( $secret, $code );
		if ( false === $slice ) {
			self::audit( 'two_factor_failed', $user_id );
			return new WP_Error( 'smc_totp_invalid', __( 'The verification code is invalid or expired.', 'sabri-membership-core' ) );
		}
		$token = wp_get_session_token();
		$hash = self::blind_index( $token, 'session-token' );
		if ( ! $token || is_wp_error( $hash ) ) {
			return new WP_Error( 'smc_session', __( 'The login session is unavailable.', 'sabri-membership-core' ) );
		}
		global $wpdb;
		$session_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL LIMIT 1", $user_id, $hash ) );
		if ( ! $session_id && ( ! self::register_session( $user_id, $token, time() + 2 * DAY_IN_SECONDS ) ) ) {
			return new WP_Error( 'smc_session_register', __( 'The login session could not be registered safely.', 'sabri-membership-core' ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,last_totp_slice FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE", $user_id, $hash ), ARRAY_A );
		$factor = $wpdb->get_row( $wpdb->prepare( "SELECT user_id,last_totp_slice FROM {$wpdb->prefix}smc_mfa_factor_state WHERE user_id=%d LIMIT 1 FOR UPDATE", $user_id ), ARRAY_A );
		$global_last = $factor && null !== $factor['last_totp_slice'] ? (int) $factor['last_totp_slice'] : null;
		if ( ! $row || ( null !== $global_last && $global_last >= (int) $slice ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'smc_totp_replay', __( 'This verification code was already used for this authenticator factor.', 'sabri-membership-core' ) );
		}
		$now = current_time( 'mysql', true );
		$factor_updated = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_mfa_factor_state (user_id,last_totp_slice,updated_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE last_totp_slice=IF(last_totp_slice IS NULL OR last_totp_slice<VALUES(last_totp_slice),VALUES(last_totp_slice),last_totp_slice),updated_at=IF(last_totp_slice IS NULL OR last_totp_slice<VALUES(last_totp_slice),VALUES(updated_at),updated_at)", $user_id, $slice, $now ) );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL", $now, $slice, $now, (int) $row['id'], $user_id, $hash ) );
		$revalidation_ok = false !== $factor_updated && 1 === $updated && self::clear_revalidation_requirement( $user_id );
		$audit_ok = $revalidation_ok && self::audit( 'two_factor_passed', $user_id, array( 'session_id' => (int) $row['id'], 'totp_slice' => (int) $slice ) );
		if ( 1 !== $updated || ! $revalidation_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' ); clean_user_cache( $user_id );
			return new WP_Error( 'smc_totp_commit', __( 'The two-factor verification could not be committed atomically.', 'sabri-membership-core' ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { clean_user_cache($user_id); return new WP_Error('smc_totp_commit',__( 'The two-factor verification could not be committed atomically.', 'sabri-membership-core' )); }
		clean_user_cache( $user_id ); return true;
	}

	public static function recovery_codes( $user_id, $count = 8, $receipt_callback = null ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$count = max( 1, min( 20, absint( $count ) ) );
		$plain = array();
		$records = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$code = strtoupper( wp_generate_password( 12, false, false ) );
			$lookup = self::blind_index( $code, 'recovery-code' );
			if ( is_wp_error( $lookup ) ) { return $lookup; }
			$plain[] = $code;
			$records[] = array(
				'user_id'=>$user_id,
				'code_lookup_hash'=>$lookup,
				'code_hash'=>wp_hash_password( $code ),
				'created_at'=>current_time( 'mysql', true ),
			);
		}
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'smc_recovery_transaction', __( 'Recovery-code rotation could not start a database transaction.', 'sabri-membership-core' ) );
		}
		$deleted = $wpdb->delete( $wpdb->prefix . 'smc_recovery_codes', array( 'user_id'=>$user_id ), array('%d') );
		if ( false === $deleted ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return new WP_Error( 'smc_recovery_reset', __( 'Existing recovery codes could not be replaced.', 'sabri-membership-core' ) );
		}
		foreach ( $records as $record ) {
			if ( 1 !== $wpdb->insert( $wpdb->prefix . 'smc_recovery_codes', $record, array('%d','%s','%s','%s') ) ) {
				if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
				clean_user_cache( $user_id );
				return new WP_Error( 'smc_recovery_store', __( 'Recovery codes could not be stored.', 'sabri-membership-core' ) );
			}
		}
		if ( is_callable( $receipt_callback ) && true !== call_user_func( $receipt_callback, $plain ) ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_receipt', __( 'Recovery codes were not replaced because the protected receipt could not be stored.', 'sabri-membership-core' ) );
		}
		if ( ! self::audit( 'recovery_codes_rotated', $user_id ) ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_audit', __( 'Recovery codes were not replaced because required audit evidence could not be recorded.', 'sabri-membership-core' ), array( 'audit_error' => self::last_audit_error(), 'audit_health' => self::audit_health_snapshot() ) );
		}
		if ( $owns_transaction && false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'smc_recovery_commit', __( 'Recovery-code rotation could not be committed.', 'sabri-membership-core' ) );
		}
		clean_user_cache( $user_id );
		return $plain;
	}


	public static function consume_recovery_code_for_session( $user_id, $code ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$code = strtoupper( trim( (string) $code ) );
		$lookup = self::blind_index( $code, 'recovery-code' );
		$token = wp_get_session_token();
		$token_hash = self::blind_index( $token, 'session-token' );
		if ( ! $token || is_wp_error( $lookup ) || is_wp_error( $token_hash ) ) { return false; }
		$existing_session = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1", $user_id, $token_hash ) );
		if ( ! $existing_session && ! self::register_session( $user_id, $token, time() + 2 * DAY_IN_SECONDS ) ) { return false; }
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d AND code_lookup_hash=%s AND consumed_at IS NULL LIMIT 1 FOR UPDATE",
				$user_id,
				$lookup
			),
			ARRAY_A
		);
		if ( ! $row || ! wp_check_password( $code, $row['code_hash'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$now = current_time( 'mysql', true );
		$session_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE",
				$user_id,
				$token_hash
			)
		);
		if ( ! $session_id ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$code_updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL", $now, (int) $row['id'] ) );
		$session_updated = $session_id ? $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL", $now, $now, (int) $session_id, $user_id, $token_hash ) ) : 0;
		$revalidation_ok = 1 === $code_updated && 1 === $session_updated && self::clear_revalidation_requirement( $user_id );
		$audit_ok = $revalidation_ok && self::audit( 'recovery_code_used', $user_id, array( 'session_id' => (int) $session_id ) );
		if ( 1 !== $code_updated || 1 !== $session_updated || ! $revalidation_ok || ! $audit_ok ) { $wpdb->query( 'ROLLBACK' ); clean_user_cache($user_id); return false; }
		if ( false === $wpdb->query( 'COMMIT' ) ) { clean_user_cache($user_id); return false; }
		clean_user_cache( $user_id ); return true;
	}

	public static function consume_recovery_code( $user_id, $code ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$code = strtoupper( trim( (string) $code ) );
		$lookup = self::blind_index( $code, 'recovery-code' );
		if ( is_wp_error( $lookup ) ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_recovery_codes WHERE user_id=%d AND code_lookup_hash=%s AND consumed_at IS NULL LIMIT 1 FOR UPDATE", $user_id, $lookup ), ARRAY_A );
		if ( ! $row || ! wp_check_password( $code, $row['code_hash'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL", current_time( 'mysql', true ), (int) $row['id'] ) );
		$audit_ok = 1 === $updated && self::audit( 'recovery_code_used', $user_id, array( 'standalone' => true ) );
		if ( 1 !== $updated || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { return false; }
		return true;
	}

	private static function reconcile_session_revocation_after_destroy( $user_id, $session_id = 0, $reason = 'reconciliation' ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$session_id = absint( $session_id );
		if ( ! $user_id || self::transaction_active() ) { return false; }
		$wpdb->query( 'START TRANSACTION' );
		$now = current_time( 'mysql', true );
		if ( $session_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,token_hash,revoked_at FROM {$wpdb->prefix}smc_auth_sessions WHERE id=%d AND user_id=%d LIMIT 1 FOR UPDATE", $session_id, $user_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,token_hash,revoked_at FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d FOR UPDATE", $user_id ), ARRAY_A );
		}
		if ( ! is_array( $rows ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$ok = true;
		foreach ( $rows as $row ) {
			if ( empty( $row['revoked_at'] ) ) {
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $now, $now, (int) $row['id'], $user_id ) );
				if ( 1 !== $updated ) { $ok = false; break; }
			}
			if ( ! self::delete_session_token_envelope( $user_id, (string) $row['token_hash'] ) ) { $ok = false; break; }
		}
		$audit_ok = $ok && self::audit( $session_id ? 'membership_session_revocation_reconciled' : 'sessions_revocation_reconciled', $user_id, array( 'session_id'=>$session_id, 'reason'=>sanitize_key( $reason ) ) );
		if ( ! $ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		clean_user_cache( $user_id );
		return true;
	}

	public static function revoke_session_by_id( $user_id, $session_id, $reason = 'user_requested' ) {
		global $wpdb;
		$user_id = absint( $user_id ); $session_id = absint( $session_id );
		if ( ! $user_id || ! $session_id || self::transaction_active() ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_auth_sessions WHERE id=%d AND user_id=%d AND revoked_at IS NULL LIMIT 1", $session_id, $user_id ), ARRAY_A );
		if ( ! $row || ! class_exists( 'WP_Session_Tokens' ) ) { return false; }
		$raw = self::session_token_from_hash( $user_id, $row['token_hash'] );
		if ( is_wp_error( $raw ) ) { return self::revoke_all_sessions( $user_id, 'legacy_exact_token_unavailable' ); }
		$wpdb->query( 'START TRANSACTION' );
		$intent_ok = self::audit( 'membership_session_revocation_requested', $user_id, array( 'session_id'=>$session_id, 'reason'=>sanitize_key($reason) ) );
		if ( ! $intent_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query('ROLLBACK'); return false; }
		WP_Session_Tokens::get_instance( $user_id )->destroy( $raw );
		$wpdb->query( 'START TRANSACTION' );
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $now, $now, $session_id, $user_id ) );
		$envelope_ok = self::delete_session_token_envelope( $user_id, $row['token_hash'] );
		$audit_ok = 1 === $updated && $envelope_ok && self::audit( 'membership_session_revoked', $user_id, array( 'session_id'=>$session_id, 'reason'=>sanitize_key($reason) ) );
		if ( 1 !== $updated || ! $envelope_ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query('ROLLBACK');
			return self::reconcile_session_revocation_after_destroy( $user_id, $session_id, $reason );
		}
		clean_user_cache( $user_id ); return true;
	}

	public static function revoke_session( $user_id, $token ) {
		$hash = self::blind_index( $token, 'session-token' );
		if ( is_wp_error( $hash ) ) {
			return false;
		}
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL LIMIT 1", absint( $user_id ), $hash ) );
		return $id ? self::revoke_session_by_id( $user_id, $id, 'token_revocation' ) : false;
	}

	public static function revoke_all_sessions( $user_id, $reason = '' ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id || self::transaction_active() ) { return false; }
		$reason = sanitize_text_field( $reason );
		$wpdb->query( 'START TRANSACTION' );
		$intent_ok = self::audit( 'sessions_revocation_requested', $user_id, array( 'reason' => $reason ) );
		if ( ! $intent_ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		if ( class_exists( 'WP_Session_Tokens' ) ) { WP_Session_Tokens::get_instance( $user_id )->destroy_all(); }
		$wpdb->query( 'START TRANSACTION' );
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE user_id=%d AND revoked_at IS NULL", $now, $now, $user_id ) );
		$envelopes_ok = true;
		foreach ( array_keys( get_user_meta( $user_id ) ) as $meta_key ) {
			if ( 0 === strpos( $meta_key, '_smc_session_token_' ) && ! delete_user_meta( $user_id, $meta_key ) ) { $envelopes_ok = false; }
		}
		$audit_ok = false !== $updated && $envelopes_ok && self::audit( 'sessions_revoked', $user_id, array( 'reason' => $reason, 'count' => (int) $updated ) );
		if ( false === $updated || ! $envelopes_ok || ! $audit_ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::reconcile_session_revocation_after_destroy( $user_id, 0, $reason );
		}
		clean_user_cache( $user_id ); return true;
	}

	public static function rate_limited( $bucket, $limit = 7, $seconds = 900 ) {
		global $wpdb;
		$hash = self::blind_index( (string) $bucket . '|' . self::request_hash( 'ip' ), 'rate-limit' );
		if ( is_wp_error( $hash ) ) {
			return true;
		}
		$now = current_time( 'mysql', true );
		$reset = gmdate( 'Y-m-d H:i:s', time() + absint( $seconds ) );
		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}smc_rate_limits (bucket_hash,attempt_count,reset_at,updated_at)
			VALUES (%s,1,%s,%s)
			ON DUPLICATE KEY UPDATE
			attempt_count=IF(reset_at<=UTC_TIMESTAMP(),1,LAST_INSERT_ID(attempt_count+1)),
			reset_at=IF(reset_at<=UTC_TIMESTAMP(),VALUES(reset_at),reset_at),
			updated_at=VALUES(updated_at)",
			$hash,
			$reset,
			$now
		);
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return true;
		}
		$count = 1 === $result ? 1 : (int) $wpdb->insert_id;
		if ( $count <= 0 ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempt_count FROM {$wpdb->prefix}smc_rate_limits WHERE bucket_hash=%s", $hash ) );
		}
		return $count > absint( $limit );
	}

	private static function request_hash( $kind ) {
		$value = 'ip' === $kind
			? ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' )
			: ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '' );
		$key = self::key();
		return is_wp_error( $key ) ? hash( 'sha256', $value . wp_salt( 'nonce' ) ) : hash_hmac( 'sha256', $value, $key );
	}

	public static function subject_hash( $user_id ) {
		$key = self::key();
		return is_wp_error( $key ) ? '' : hash_hmac( 'sha256', 'user|' . absint( $user_id ), $key );
	}

	/**
	 * Inspect the physical audit rows without consulting the mutable tail.
	 *
	 * File 00 1.0.1 stored an unchained audit history in the same table name.
	 * dbDelta preserves those rows and the former subject/object columns while
	 * adding empty previous_hash/row_hash fields. Those rows can never honestly
	 * be called cryptographically verified. They can, however, be identified by
	 * their exact legacy schema, snapshotted without mutation, and sealed by a
	 * keyed migration anchor before a new HMAC epoch begins.
	 *
	 * @param int $limit Optional physical-row limit. Recovery callers pass the
	 *                   complete current row count.
	 * @return array<string,mixed>
	 */
	public static function inspect_audit_rows_for_recovery( $limit = 0 ) {
		global $wpdb;
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return array( 'valid' => false, 'checked' => 0, 'verified_rows' => 0, 'legacy_rows' => 0, 'failed_id' => 0, 'reason' => 'key_unavailable', 'last_hash' => '' );
		}

		$table = $wpdb->prefix . 'smc_audit_log';
		$wpdb->last_error = '';
		$schema_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
				$table
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return array( 'valid' => false, 'checked' => 0, 'verified_rows' => 0, 'legacy_rows' => 0, 'failed_id' => 0, 'reason' => 'audit_schema_query_failed', 'last_hash' => '' );
		}
		$schema = array();
		foreach ( (array) $schema_rows as $schema_row ) {
			$name = (string) ( $schema_row['COLUMN_NAME'] ?? '' );
			if ( '' !== $name ) { $schema[ $name ] = $schema_row; }
		}
		$columns = array_keys( $schema );
		$base_columns = array( 'id', 'actor_id', 'action', 'details', 'created_at' );
		if ( array_diff( $base_columns, $columns ) ) {
			return array( 'valid' => false, 'checked' => 0, 'verified_rows' => 0, 'legacy_rows' => 0, 'failed_id' => 0, 'reason' => 'audit_schema_incomplete', 'last_hash' => '' );
		}
		$legacy_columns = array( 'subject_user_id', 'object_type', 'object_id' );
		$hash_schema = ! array_diff( array( 'subject_hash', 'previous_hash', 'row_hash' ), $columns );
		$allowed_bridge_columns = array_merge( $base_columns, $legacy_columns, array( 'subject_hash', 'previous_hash', 'row_hash' ) );
		$unsigned_bigint = static function ( $column ) use ( $schema ) {
			return isset( $schema[ $column ] ) && 'bigint' === strtolower( (string) ( $schema[ $column ]['DATA_TYPE'] ?? '' ) ) && false !== stripos( (string) ( $schema[ $column ]['COLUMN_TYPE'] ?? '' ), 'unsigned' );
		};
		$legacy_schema = ! array_diff( $legacy_columns, $columns )
			&& ! array_diff( $columns, $allowed_bridge_columns )
			&& $unsigned_bigint( 'id' )
			&& $unsigned_bigint( 'actor_id' )
			&& $unsigned_bigint( 'subject_user_id' )
			&& $unsigned_bigint( 'object_id' )
			&& 'varchar' === strtolower( (string) ( $schema['object_type']['DATA_TYPE'] ?? '' ) )
			&& 80 === (int) ( $schema['object_type']['CHARACTER_MAXIMUM_LENGTH'] ?? 0 )
			&& 'varchar' === strtolower( (string) ( $schema['action']['DATA_TYPE'] ?? '' ) )
			&& in_array( (int) ( $schema['action']['CHARACTER_MAXIMUM_LENGTH'] ?? 0 ), array( 80, 120 ), true )
			&& 'longtext' === strtolower( (string) ( $schema['details']['DATA_TYPE'] ?? '' ) )
			&& 'datetime' === strtolower( (string) ( $schema['created_at']['DATA_TYPE'] ?? '' ) )
			&& false !== stripos( (string) ( $schema['id']['EXTRA'] ?? '' ), 'auto_increment' );

		$previous = '';
		$checked = 0;
		$verified = 0;
		$legacy = 0;
		$legacy_cutoff_id = 0;
		$cursor = 0;
		$maximum = absint( $limit );
		$snapshot = hash_init( 'sha256' );
		do {
			$batch_size = $maximum ? min( 500, $maximum - $checked ) : 500;
			if ( $batch_size <= 0 ) {
				break;
			}
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_audit_log WHERE id>%d ORDER BY id ASC LIMIT %d", $cursor, $batch_size ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) {
				return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $cursor, 'reason' => 'audit_rows_query_failed', 'last_hash' => $previous );
			}
			foreach ( (array) $rows as $row ) {
				$row_id = absint( $row['id'] ?? 0 );
				if ( ! $row_id || $row_id <= $cursor ) {
					return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'audit_row_order_invalid', 'last_hash' => $previous );
				}
				$row_previous = $hash_schema ? (string) ( $row['previous_hash'] ?? '' ) : '';
				$row_hash = $hash_schema ? (string) ( $row['row_hash'] ?? '' ) : '';

				if ( '' === $row_previous && '' === $row_hash ) {
					if ( $verified > 0 ) {
						return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'unhashed_row_after_chain_start', 'last_hash' => $previous );
					}
					if ( ! $legacy_schema ) {
						return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => 0, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'unhashed_row_without_legacy_schema', 'last_hash' => '' );
					}
					$legacy_record = array(
						'format'          => 'smc-audit-legacy-v1',
						'id'              => $row_id,
						'actor_id'        => (int) ( $row['actor_id'] ?? 0 ),
						'subject_user_id' => (int) ( $row['subject_user_id'] ?? 0 ),
						'subject_hash'    => array_key_exists( 'subject_hash', $row ) && null !== $row['subject_hash'] ? (string) $row['subject_hash'] : null,
						'action'          => (string) ( $row['action'] ?? '' ),
						'object_type'     => (string) ( $row['object_type'] ?? '' ),
						'object_id'       => (int) ( $row['object_id'] ?? 0 ),
						'details'         => array_key_exists( 'details', $row ) && null !== $row['details'] ? (string) $row['details'] : null,
						'previous_hash'   => '',
						'row_hash'        => '',
						'created_at'      => (string) ( $row['created_at'] ?? '' ),
					);
					hash_update( $snapshot, self::canonical_json( $legacy_record ) . "\n" );
					++$legacy;
					++$checked;
					$legacy_cutoff_id = $row_id;
					$cursor = $row_id;
					continue;
				}

				if ( ! $hash_schema ) {
					return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'audit_hash_schema_missing', 'last_hash' => $previous );
				}
				if ( ! preg_match( '/^[a-f0-9]{64}$/D', $row_hash ) || ( '' !== $row_previous && ! preg_match( '/^[a-f0-9]{64}$/D', $row_previous ) ) ) {
					return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'audit_hash_format_invalid', 'last_hash' => $previous );
				}
				if ( ! hash_equals( $previous, $row_previous ) ) {
					return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'previous_hash_mismatch', 'last_hash' => $previous );
				}
				$record = array(
					'actor_id'      => (int) ( $row['actor_id'] ?? 0 ),
					'subject_hash'  => array_key_exists( 'subject_hash', $row ) && null !== $row['subject_hash'] ? (string) $row['subject_hash'] : null,
					'action'        => (string) ( $row['action'] ?? '' ),
					'details'       => (string) ( $row['details'] ?? '' ),
					'previous_hash' => $row_previous,
					'created_at'    => (string) ( $row['created_at'] ?? '' ),
				);
				$expected = hash_hmac( 'sha256', self::canonical_json( $record ), $key );
				if ( ! hash_equals( $expected, $row_hash ) ) {
					return array( 'valid' => false, 'checked' => $checked, 'verified_rows' => $verified, 'legacy_rows' => $legacy, 'failed_id' => $row_id, 'reason' => 'row_hash_mismatch', 'last_hash' => $previous );
				}
				$previous = $row_hash;
				$cursor = $row_id;
				++$checked;
				++$verified;
			}
		} while ( count( (array) $rows ) === $batch_size && ( ! $maximum || $checked < $maximum ) );

		return array(
			'valid'                => true,
			'checked'              => $checked,
			'verified_rows'        => $verified,
			'legacy_rows'          => $legacy,
			'legacy_source_schema' => $legacy_schema && ! $hash_schema ? 'smc-audit-legacy-v1-no-hmac-columns' : '',
			'legacy_cutoff_id'     => $legacy_cutoff_id,
			'legacy_snapshot_hash' => $legacy ? hash_final( $snapshot ) : '',
			'failed_id'            => 0,
			'reason'               => '',
			'last_id'              => $cursor,
			'last_hash'            => $previous,
		);
	}

	private static function legacy_audit_anchor_payload( $inspection, $created_at ) {
		return array(
			'version'                    => 1,
			'assurance'                  => 'legacy_snapshot_only',
			'source_schema'              => 'smc-audit-legacy-v1-no-hmac-columns',
			'legacy_cutoff_id'           => absint( $inspection['legacy_cutoff_id'] ?? 0 ),
			'legacy_row_count'           => absint( $inspection['legacy_rows'] ?? 0 ),
			'legacy_snapshot_hash'       => (string) ( $inspection['legacy_snapshot_hash'] ?? '' ),
			'chain_initial_previous_hash'=> '',
			'created_at'                 => (string) $created_at,
		);
	}

	/** Establish the immutable keyed boundary for an identified 1.0.1 prefix. */
	public static function establish_legacy_audit_anchor( $inspection ) {
		if ( ! is_array( $inspection ) || empty( $inspection['valid'] ) || empty( $inspection['legacy_rows'] ) ) {
			return new WP_Error( 'smc_audit_legacy_anchor_input', __( 'The legacy audit snapshot is not eligible for anchoring.', 'sabri-membership-core' ) );
		}
		$existing = get_option( self::LEGACY_AUDIT_ANCHOR_OPTION, array() );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$verified = self::verify_legacy_audit_anchor( $inspection, $existing );
			return ! empty( $verified['valid'] ) ? $existing : new WP_Error( 'smc_audit_legacy_anchor_conflict', __( 'The stored legacy audit anchor does not match the surviving audit history.', 'sabri-membership-core' ) );
		}
		if ( 'smc-audit-legacy-v1-no-hmac-columns' !== (string) ( $inspection['legacy_source_schema'] ?? '' ) ) {
			return new WP_Error( 'smc_audit_legacy_anchor_source', __( 'A new legacy audit anchor can be established only from the original recognized pre-HMAC schema.', 'sabri-membership-core' ) );
		}
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return new WP_Error( 'smc_audit_legacy_anchor_key', __( 'The audit integrity key is unavailable.', 'sabri-membership-core' ) );
		}
		$payload = self::legacy_audit_anchor_payload( $inspection, current_time( 'mysql', true ) );
		$anchor = $payload;
		$anchor['signature'] = hash_hmac( 'sha256', 'smc:audit-legacy-anchor:v1|' . self::canonical_json( $payload ), $key );
		if ( ! add_option( self::LEGACY_AUDIT_ANCHOR_OPTION, $anchor, '', 'no' ) ) {
			$existing = get_option( self::LEGACY_AUDIT_ANCHOR_OPTION, array() );
			$verified = self::verify_legacy_audit_anchor( $inspection, $existing );
			if ( empty( $verified['valid'] ) ) {
				return new WP_Error( 'smc_audit_legacy_anchor_persist', __( 'File 00 could not persist an exact legacy audit anchor.', 'sabri-membership-core' ) );
			}
			return $existing;
		}
		$stored = get_option( self::LEGACY_AUDIT_ANCHOR_OPTION, array() );
		$verified = self::verify_legacy_audit_anchor( $inspection, $stored );
		return ! empty( $verified['valid'] ) ? $stored : new WP_Error( 'smc_audit_legacy_anchor_verify', __( 'The persisted legacy audit anchor could not be verified.', 'sabri-membership-core' ) );
	}

	/** Verify an anchor without treating the pre-HMAC rows as original HMAC evidence. */
	public static function verify_legacy_audit_anchor( $inspection, $anchor = null ) {
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return array( 'valid' => false, 'reason' => 'key_unavailable' );
		}
		if ( null === $anchor ) {
			$anchor = get_option( self::LEGACY_AUDIT_ANCHOR_OPTION, array() );
		}
		if ( ! is_array( $inspection ) || empty( $inspection['valid'] ) || empty( $inspection['legacy_rows'] ) || ! is_array( $anchor ) ) {
			return array( 'valid' => false, 'reason' => 'legacy_anchor_missing' );
		}
		$created_at = (string) ( $anchor['created_at'] ?? '' );
		$payload = self::legacy_audit_anchor_payload( $inspection, $created_at );
		$signature = (string) ( $anchor['signature'] ?? '' );
		foreach ( $payload as $field => $value ) {
			if ( ! array_key_exists( $field, $anchor ) || $anchor[ $field ] !== $value ) {
				return array( 'valid' => false, 'reason' => 'legacy_anchor_snapshot_mismatch' );
			}
		}
		if ( '' === $created_at || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return array( 'valid' => false, 'reason' => 'legacy_anchor_format_invalid' );
		}
		$expected = hash_hmac( 'sha256', 'smc:audit-legacy-anchor:v1|' . self::canonical_json( $payload ), $key );
		return hash_equals( $expected, $signature )
			? array( 'valid' => true, 'reason' => '' )
			: array( 'valid' => false, 'reason' => 'legacy_anchor_signature_mismatch' );
	}

	public static function verify_audit_chain( $limit = 0 ) {
		global $wpdb;
		$maximum = absint( $limit );
		$inspection = self::inspect_audit_rows_for_recovery( $maximum );
		if ( ! is_array( $inspection ) || empty( $inspection['valid'] ) ) {
			return is_array( $inspection ) ? $inspection : array( 'valid' => false, 'checked' => 0, 'failed_id' => 0, 'reason' => 'audit_inspection_failed' );
		}
		if ( ! empty( $inspection['legacy_rows'] ) ) {
			$anchor = self::verify_legacy_audit_anchor( $inspection );
			if ( empty( $anchor['valid'] ) ) {
				$inspection['valid'] = false;
				$inspection['reason'] = (string) ( $anchor['reason'] ?? 'legacy_anchor_invalid' );
				return $inspection;
			}
		}
		if ( ! $maximum ) {
			$tail_table = $wpdb->prefix . 'smc_audit_tail';
			$tail_exists = $tail_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tail_table ) ) );
			if ( ! $tail_exists ) {
				$inspection['valid'] = false;
				$inspection['reason'] = 'audit_tail_missing';
				return $inspection;
			}
			$tail = $wpdb->get_row( "SELECT row_hash FROM {$tail_table} WHERE id=1 LIMIT 1", ARRAY_A );
			if ( ! is_array( $tail ) ) {
				$inspection['valid'] = false;
				$inspection['reason'] = 'audit_tail_row_missing';
				return $inspection;
			}
			if ( ! hash_equals( (string) ( $inspection['last_hash'] ?? '' ), (string) ( $tail['row_hash'] ?? '' ) ) ) {
				$inspection['valid'] = false;
				$inspection['reason'] = 'tail_hash_mismatch';
				$inspection['failed_id'] = absint( $inspection['last_id'] ?? 0 );
				return $inspection;
			}
		}
		return $inspection;
	}

	public static function last_audit_error() {
		return (string) self::$last_audit_error;
	}


	/**
	 * Read-only, non-secret audit readiness snapshot for authenticated diagnostics.
	 * It never repairs rows, moves the tail, or exposes hashes/key material.
	 */
	public static function audit_health_snapshot() {
		global $wpdb;
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return array( 'key_ready' => false, 'row_count' => 0, 'chain_valid' => false, 'chain_reason' => 'key_unavailable', 'failed_id' => 0, 'tail_state' => 'unknown', 'legacy_state' => 'unknown' );
		}
		$audit_table = $wpdb->prefix . 'smc_audit_log';
		$tail_table = $wpdb->prefix . 'smc_audit_tail';
		$audit_exists = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
		$tail_exists = $tail_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tail_table ) ) );
		if ( ! $audit_exists ) {
			return array( 'key_ready' => true, 'row_count' => 0, 'chain_valid' => false, 'chain_reason' => 'audit_table_missing', 'failed_id' => 0, 'tail_state' => $tail_exists ? 'present' : 'missing', 'legacy_state' => 'unknown' );
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
		$inspection = self::inspect_audit_rows_for_recovery( max( 1, $count ) );
		$legacy_state = empty( $inspection['legacy_rows'] ) ? 'none' : 'unanchored';
		if ( ! empty( $inspection['legacy_rows'] ) ) {
			$anchor = self::verify_legacy_audit_anchor( $inspection );
			$legacy_state = ! empty( $anchor['valid'] ) ? 'anchored' : 'invalid';
			if ( ! empty( $inspection['valid'] ) && empty( $anchor['valid'] ) ) {
				$inspection['valid'] = false;
				$inspection['reason'] = (string) ( $anchor['reason'] ?? 'legacy_anchor_invalid' );
			}
		}
		if ( ! $tail_exists ) {
			$reason = empty( $inspection['valid'] ) ? (string) ( $inspection['reason'] ?? 'audit_inspection_failed' ) : 'audit_tail_missing';
			return array( 'key_ready' => true, 'row_count' => $count, 'chain_valid' => false, 'chain_reason' => sanitize_key( $reason ), 'failed_id' => absint( $inspection['failed_id'] ?? 0 ), 'tail_state' => 'missing', 'legacy_state' => $legacy_state );
		}
		$validation = self::verify_audit_chain();
		$last = (string) ( $inspection['last_hash'] ?? '' );
		$tail = $wpdb->get_row( "SELECT row_hash FROM {$tail_table} WHERE id=1 LIMIT 1", ARRAY_A );
		$tail_state = ! $tail ? 'missing' : ( hash_equals( $last, (string) ( $tail['row_hash'] ?? '' ) ) ? 'match' : 'mismatch' );
		return array(
			'key_ready'    => true,
			'row_count'    => $count,
			'chain_valid'  => is_array( $validation ) && ! empty( $validation['valid'] ) && (int) ( $validation['checked'] ?? -1 ) === $count,
			'chain_reason' => sanitize_key( (string) ( $validation['reason'] ?? '' ) ),
			'failed_id'    => absint( $validation['failed_id'] ?? 0 ),
			'tail_state'   => $tail_state,
			'legacy_state' => $legacy_state,
		);
	}

	/**
	 * Reconcile only the mutable serializer pointer, never the audit rows.
	 *
	 * A historic/live upgrade can leave smc_audit_tail stale even when every
	 * append-only audit row is still cryptographically valid. Before moving the
	 * tail pointer we validate the complete row chain independently of the tail.
	 * If any row/hash/link is invalid the repair is refused and callers remain
	 * fail-closed. The caller already holds the singleton tail row FOR UPDATE.
	 */
	private static function reconcile_audit_tail_if_rows_valid( $actual_last, $stored_tail ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log" );
		/* A non-zero limit intentionally validates rows without consulting tail. */
		$validation = self::verify_audit_chain( max( 1, $count ) );
		if ( ! is_array( $validation ) || empty( $validation['valid'] ) || (int) ( $validation['checked'] ?? -1 ) !== $count ) {
			$reason = sanitize_key( (string) ( $validation['reason'] ?? 'unknown' ) );
			$failed = absint( $validation['failed_id'] ?? 0 );
			self::$last_audit_error = 'audit_row_chain_invalid_' . ( $reason ?: 'unknown' ) . ( $failed ? '_id_' . $failed : '' );
			return false;
		}
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smc_audit_tail SET row_hash=%s,updated_at=%s WHERE id=1 AND row_hash=%s",
				(string) $actual_last,
				current_time( 'mysql', true ),
				(string) $stored_tail
			)
		);
		if ( 1 === $updated ) {
			return true;
		}
		$now_tail = (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1" );
		if ( hash_equals( (string) $actual_last, $now_tail ) ) {
			return true;
		}
		self::$last_audit_error = false === $updated ? 'audit_tail_repair_db' : 'audit_tail_repair_cas';
		return false;
	}

	public static function audit( $action, $subject_user_id = 0, $details = array() ) {
		global $wpdb;
		self::$last_audit_error = '';
		$schema_state = class_exists( 'SMC_Installer' ) ? SMC_Installer::ensure_audit_infrastructure() : new WP_Error( 'smc_audit_installer_unavailable', 'Audit installer unavailable.' );
		if ( is_wp_error( $schema_state ) ) { self::$last_audit_error = sanitize_key( $schema_state->get_error_code() ); return false; }
		if ( ! empty( $schema_state['bootstrapped'] ) ) {
			$details = is_array( $details ) ? $details : array();
			$details['audit_infrastructure_bootstrapped'] = true;
		}
		$key = self::key();
		if ( is_wp_error( $key ) ) { self::$last_audit_error = 'audit_key_unavailable'; return false; }
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) { self::$last_audit_error = 'audit_transaction_start'; return false; }
		$ok = false;
		try {
			$tail = $wpdb->get_row( "SELECT id,row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1 FOR UPDATE", ARRAY_A );
			$actual_last = (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 1" );
			$tail_reconciled = false;
			if ( ! $tail ) {
				/* Never manufacture a serializer pointer over an invalid row chain. */
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}smc_audit_log" );
				$validation = self::verify_audit_chain( max( 1, $count ) );
				if ( ! is_array( $validation ) || empty( $validation['valid'] ) || (int) ( $validation['checked'] ?? -1 ) !== $count ) {
					throw new RuntimeException( 'audit_row_chain_invalid' );
				}
				$now = current_time( 'mysql', true );
				if ( 1 !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}smc_audit_tail (id,row_hash,updated_at) VALUES (1,%s,%s)", $actual_last, $now ) ) ) { throw new RuntimeException( 'audit_tail_init' ); }
				$tail = $wpdb->get_row( "SELECT id,row_hash FROM {$wpdb->prefix}smc_audit_tail WHERE id=1 FOR UPDATE", ARRAY_A );
				$tail_reconciled = true;
			}
			$previous = (string) ( $tail['row_hash'] ?? '' );
			if ( ! hash_equals( $actual_last, $previous ) ) {
				if ( ! self::reconcile_audit_tail_if_rows_valid( $actual_last, $previous ) ) {
					throw new RuntimeException( self::$last_audit_error ?: 'audit_tail_mismatch' );
				}
				$previous = $actual_last;
				$tail_reconciled = true;
			}
			if ( $tail_reconciled ) {
				$details = is_array( $details ) ? $details : array();
				$details['audit_tail_reconciled'] = true;
			}
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
			if ( $owns_transaction && false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				self::$last_audit_error = 'audit_commit';
				$ok = false;
			}
		} catch ( Throwable $error ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			if ( '' === self::$last_audit_error ) {
				self::$last_audit_error = sanitize_key( $error->getMessage() );
			}
			$ok = false;
		}
		return $ok;
	}

	private static function minimize_audit_details( $details ) {
		$details = is_array( $details ) ? $details : array();
		$out = array();
		$count = 0;
		foreach ( $details as $key => $value ) {
			if ( $count++ >= 40 ) { break; }
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) { continue; }
			if ( self::audit_key_is_sensitive( $key ) ) {
				$out[ $key . '_digest' ] = hash( 'sha256', (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
				continue;
			}
			if ( is_array( $value ) ) {
				$nested = array();
				$nested_count = 0;
				foreach ( array_slice( $value, 0, 20, true ) as $nested_key => $nested_value ) {
					if ( $nested_count++ >= 20 ) { break; }
					$clean_nested_key = is_int( $nested_key ) ? $nested_key : sanitize_key( (string) $nested_key );
					if ( ! is_int( $clean_nested_key ) && self::audit_key_is_sensitive( $clean_nested_key ) ) {
						$nested[ $clean_nested_key . '_digest' ] = hash( 'sha256', (string) ( is_scalar( $nested_value ) ? $nested_value : wp_json_encode( $nested_value ) ) );
					} elseif ( is_bool( $nested_value ) || is_int( $nested_value ) || is_float( $nested_value ) ) {
						$nested[ $clean_nested_key ] = $nested_value;
					} elseif ( is_scalar( $nested_value ) || null === $nested_value ) {
						$nested[ $clean_nested_key ] = substr( sanitize_text_field( (string) $nested_value ), 0, 190 );
					} else {
						$nested[ $clean_nested_key ] = hash( 'sha256', (string) wp_json_encode( $nested_value ) );
					}
				}
				$out[ $key ] = $nested;
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( null === $value ) {
				$out[ $key ] = null;
			} else {
				$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 190 );
			}
		}
		return $out;
	}

	private static function audit_key_is_sensitive( $key ) {
		$key = sanitize_key( (string) $key );
		if ( preg_match( '/_(?:hash|digest)$/', $key ) ) { return false; }
		foreach ( array( 'name','email','phone','address','note','reason','purpose','contact','target','token','secret','code','passport','national_id','identity_number','document_number','raw_ip','user_agent' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) { return true; }
		}
		return false;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

final class SMC_Security {
	const ENVELOPE = 'SMC2';
	const MAX_FILE = 8388608;

	public static function init() {
		add_action( 'admin_post_smc_private_document', array( __CLASS__, 'serve_document' ) );
		add_action( 'smc_process_file_jobs', array( __CLASS__, 'process_file_jobs' ) );
	}

	public static function key_ready() {
		return defined( 'SMC_MASTER_KEY' ) && is_string( SMC_MASTER_KEY ) && strlen( SMC_MASTER_KEY ) >= 32;
	}

	private static function key() {
		if ( ! self::key_ready() ) {
			return new WP_Error( 'smc_key_missing', __( 'SMC_MASTER_KEY must be configured with at least 32 random characters before sensitive membership data can be processed.', 'sabri-membership-core' ) );
		}
		return hash_hkdf( 'sha256', SMC_MASTER_KEY, 32, 'sabri-membership-core:v2', wp_salt( 'auth' ) );
	}

	public static function key_id() {
		$key = self::key();
		return is_wp_error( $key ) ? '' : substr( hash( 'sha256', $key ), 0, 16 );
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
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$nonce = random_bytes( 12 );
		$tag   = '';
		$aad   = self::canonical_json(
			array(
				'v'       => 2,
				'kid'     => self::key_id(),
				'purpose' => sanitize_key( $purpose ),
				'context' => $context,
			)
		);
		$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16 );
		if ( false === $cipher ) {
			return new WP_Error( 'smc_encrypt', __( 'Sensitive data could not be encrypted.', 'sabri-membership-core' ) );
		}
		return self::ENVELOPE . '.' . base64_encode( $aad ) . '.' . base64_encode( $nonce ) . '.' . base64_encode( $tag ) . '.' . base64_encode( $cipher );
	}

	public static function decrypt( $envelope, $purpose, $context = array() ) {
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$parts = explode( '.', (string) $envelope, 5 );
		if ( 5 !== count( $parts ) || self::ENVELOPE !== $parts[0] ) {
			return new WP_Error( 'smc_envelope', __( 'Encrypted data uses an unsupported envelope.', 'sabri-membership-core' ) );
		}
		$aad   = base64_decode( $parts[1], true );
		$nonce = base64_decode( $parts[2], true );
		$tag   = base64_decode( $parts[3], true );
		$data  = base64_decode( $parts[4], true );
		if ( false === $aad || false === $nonce || false === $tag || false === $data ) {
			return new WP_Error( 'smc_envelope', __( 'Encrypted data is malformed.', 'sabri-membership-core' ) );
		}
		$expected = self::canonical_json(
			array(
				'v'       => 2,
				'kid'     => self::key_id(),
				'purpose' => sanitize_key( $purpose ),
				'context' => $context,
			)
		);
		if ( ! hash_equals( $expected, $aad ) ) {
			return new WP_Error( 'smc_context', __( 'Encrypted data does not match its authenticated context.', 'sabri-membership-core' ) );
		}
		$plain = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
		return false === $plain ? new WP_Error( 'smc_authentication', __( 'Encrypted data authentication failed.', 'sabri-membership-core' ) ) : $plain;
	}

	public static function blind_index( $value, $purpose ) {
		$key = self::key();
		return is_wp_error( $key ) ? $key : hash_hmac( 'sha256', sanitize_key( $purpose ) . '|' . mb_strtolower( trim( (string) $value ), 'UTF-8' ), $key );
	}

	public static function decrypt_legacy_value( $encoded, $purpose = 'identity' ) {
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
			$wpdb->query( 'COMMIT' );
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
		if ( ( ! current_user_can( 'smc_view_private_documents' ) && ! current_user_can( 'manage_options' ) ) || ! self::session_is_verified( $user_id ) ) {
			wp_die( esc_html__( 'A current two-factor session and private-document capability are required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
		check_admin_referer( 'smc_document_' . $id );
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_identity_documents WHERE id=%d", $id ), ARRAY_A );
		if ( ! $doc ) {
			wp_die( esc_html__( 'Document not found.', 'sabri-membership-core' ), '', array( 'response' => 404 ) );
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

	private static function delete_session_token_envelope( $user_id, $token_hash ) {
		$key = self::session_token_meta_key( $token_hash );
		delete_user_meta( absint( $user_id ), $key );
		return ! metadata_exists( 'user', absint( $user_id ), $key );
	}

	private static function clear_revalidation_requirement( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' ) ) {
			return true;
		}
		delete_user_meta( $user_id, '_smc_revalidation_required_at' );
		return ! metadata_exists( 'user', $user_id, '_smc_revalidation_required_at' );
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
		if ( ! $row || ( null !== $row['last_totp_slice'] && (int) $row['last_totp_slice'] >= (int) $slice ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'smc_totp_replay', __( 'This verification code was already used for the current session.', 'sabri-membership-core' ) );
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,last_totp_slice=%d,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL AND (last_totp_slice IS NULL OR last_totp_slice<%d)", $now, $slice, $now, (int) $row['id'], $user_id, $hash, $slice ) );
		$revalidation_ok = 1 === $updated && self::clear_revalidation_requirement( $user_id );
		$audit_ok = $revalidation_ok && self::audit( 'two_factor_passed', $user_id, array( 'session_id' => (int) $row['id'], 'totp_slice' => (int) $slice ) );
		if ( 1 !== $updated || ! $revalidation_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'smc_totp_commit', __( 'The two-factor verification could not be committed atomically.', 'sabri-membership-core' ) );
		}
		$wpdb->query( 'COMMIT' );
		return true;
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
			if ( is_wp_error( $lookup ) ) {
				return $lookup;
			}
			$plain[] = $code;
			$records[] = array(
				'user_id'          => $user_id,
				'code_lookup_hash' => $lookup,
				'code_hash'        => wp_hash_password( $code ),
				'created_at'       => current_time( 'mysql', true ),
			);
		}
		$wpdb->query( 'START TRANSACTION' );
		$deleted = $wpdb->delete( $wpdb->prefix . 'smc_recovery_codes', array( 'user_id' => $user_id ), array( '%d' ) );
		if ( false === $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'smc_recovery_reset', __( 'Existing recovery codes could not be replaced.', 'sabri-membership-core' ) );
		}
		foreach ( $records as $record ) {
			if ( 1 !== $wpdb->insert(
				$wpdb->prefix . 'smc_recovery_codes',
				$record,
				array( '%d', '%s', '%s', '%s' )
			) ) {
				$wpdb->query( 'ROLLBACK' );
				clean_user_cache( $user_id );
				return new WP_Error( 'smc_recovery_store', __( 'Recovery codes could not be stored.', 'sabri-membership-core' ) );
			}
		}
		if ( is_callable( $receipt_callback ) && true !== call_user_func( $receipt_callback, $plain ) ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_receipt', __( 'Recovery codes were not replaced because the one-time receipt could not be stored.', 'sabri-membership-core' ) );
		}
		if ( ! self::audit( 'recovery_codes_rotated', $user_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_audit', __( 'Recovery codes were not replaced because the required audit evidence could not be recorded.', 'sabri-membership-core' ) );
		}
		$wpdb->query( 'COMMIT' );
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
		if ( ! $token || is_wp_error( $lookup ) || is_wp_error( $token_hash ) ) {
			return false;
		}
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
		if ( ! $session_id ) {
			$registered = self::register_session( $user_id, $token, time() + 2 * DAY_IN_SECONDS );
			$session_id = $registered ? $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}smc_auth_sessions WHERE user_id=%d AND token_hash=%s AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE",
					$user_id,
					$token_hash
				)
			) : 0;
		}
		$code_updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_recovery_codes SET consumed_at=%s WHERE id=%d AND consumed_at IS NULL", $now, (int) $row['id'] ) );
		$session_updated = $session_id ? $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET two_factor_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND token_hash=%s AND revoked_at IS NULL", $now, $now, (int) $session_id, $user_id, $token_hash ) ) : 0;
		$revalidation_ok = 1 === $code_updated && 1 === $session_updated && self::clear_revalidation_requirement( $user_id );
		$audit_ok = $revalidation_ok && self::audit( 'recovery_code_used', $user_id, array( 'session_id' => (int) $session_id ) );
		if ( 1 !== $code_updated || 1 !== $session_updated || ! $revalidation_ok || ! $audit_ok ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
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
		$wpdb->query( 'COMMIT' );
		return true;
	}

	public static function revoke_session_by_id( $user_id, $session_id, $reason = 'user_requested' ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$session_id = absint( $session_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_auth_sessions WHERE id=%d AND user_id=%d AND revoked_at IS NULL LIMIT 1", $session_id, $user_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		$raw = self::session_token_from_hash( $user_id, $row['token_hash'] );
		if ( is_wp_error( $raw ) ) {
			return self::revoke_all_sessions( $user_id, 'legacy_exact_token_unavailable' );
		}
		if ( ! class_exists( 'WP_Session_Tokens' ) ) {
			return false;
		}
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		WP_Session_Tokens::get_instance( $user_id )->destroy( $raw );
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND revoked_at IS NULL", $now, $now, $session_id, $user_id ) );
		$envelope_ok = self::delete_session_token_envelope( $user_id, $row['token_hash'] );
		$audit_ok = 1 === $updated && $envelope_ok && self::audit( 'membership_session_revoked', $user_id, array( 'session_id' => $session_id, 'reason' => sanitize_key( $reason ) ) );
		if ( 1 !== $updated || ! $envelope_ok || ! $audit_ok ) {
			if ( $owns_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			clean_user_cache( $user_id );
			return false;
		}
		if ( $owns_transaction ) {
			$wpdb->query( 'COMMIT' );
		}
		clean_user_cache( $user_id );
		return true;
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
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}smc_auth_sessions SET revoked_at=%s,updated_at=%s WHERE user_id=%d AND revoked_at IS NULL", $now, $now, $user_id ) );
		$envelopes_ok = true;
		foreach ( array_keys( get_user_meta( $user_id ) ) as $meta_key ) {
			if ( 0 === strpos( $meta_key, '_smc_session_token_' ) && ! delete_user_meta( $user_id, $meta_key ) ) {
				$envelopes_ok = false;
			}
		}
		$audit_ok = false !== $updated && $envelopes_ok && self::audit( 'sessions_revoked', $user_id, array( 'reason' => sanitize_text_field( $reason ), 'count' => (int) $updated ) );
		if ( false === $updated || ! $envelopes_ok || ! $audit_ok ) {
			if ( $owns_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			clean_user_cache( $user_id );
			return false;
		}
		if ( $owns_transaction ) {
			$wpdb->query( 'COMMIT' );
		}
		clean_user_cache( $user_id );
		return true;
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

	public static function verify_audit_chain( $limit = 0 ) {
		global $wpdb;
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return array( 'valid' => false, 'checked' => 0, 'failed_id' => 0, 'reason' => 'key_unavailable' );
		}
		$previous = '';
		$checked = 0;
		$cursor = 0;
		$maximum = absint( $limit );
		do {
			$batch_size = $maximum ? min( 500, $maximum - $checked ) : 500;
			if ( $batch_size <= 0 ) {
				break;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smc_audit_log WHERE id>%d ORDER BY id ASC LIMIT %d", $cursor, $batch_size ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				if ( ! hash_equals( $previous, (string) $row['previous_hash'] ) ) {
					return array( 'valid' => false, 'checked' => $checked, 'failed_id' => (int) $row['id'], 'reason' => 'previous_hash_mismatch' );
				}
				$record = array(
					'actor_id'      => (int) $row['actor_id'],
					'subject_hash'  => null === $row['subject_hash'] ? null : (string) $row['subject_hash'],
					'action'        => (string) $row['action'],
					'details'       => (string) $row['details'],
					'previous_hash' => (string) $row['previous_hash'],
					'created_at'    => (string) $row['created_at'],
				);
				$expected = hash_hmac( 'sha256', self::canonical_json( $record ), $key );
				if ( ! hash_equals( $expected, (string) $row['row_hash'] ) ) {
					return array( 'valid' => false, 'checked' => $checked, 'failed_id' => (int) $row['id'], 'reason' => 'row_hash_mismatch' );
				}
				$previous = (string) $row['row_hash'];
				$cursor = (int) $row['id'];
				++$checked;
			}
		} while ( count( (array) $rows ) === $batch_size && ( ! $maximum || $checked < $maximum ) );
		return array( 'valid' => true, 'checked' => $checked, 'failed_id' => 0, 'reason' => '' );
	}

	public static function audit( $action, $subject_user_id = 0, $details = array() ) {
		global $wpdb;
		$key = self::key();
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$lock_name = 'smc_audit_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 32 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock_name ) ) ) {
			return false;
		}
		try {
			$previous = (string) $wpdb->get_var( "SELECT row_hash FROM {$wpdb->prefix}smc_audit_log ORDER BY id DESC LIMIT 1" );
			$created = current_time( 'mysql', true );
			$record = array(
				'actor_id'     => get_current_user_id(),
				'subject_hash' => $subject_user_id ? self::subject_hash( $subject_user_id ) : null,
				'action'       => sanitize_key( $action ),
				'details'      => self::canonical_json( $details ),
				'previous_hash'=> $previous,
				'created_at'   => $created,
			);
			$record['row_hash'] = hash_hmac( 'sha256', self::canonical_json( $record ), $key );
			$inserted = 1 === $wpdb->insert(
				$wpdb->prefix . 'smc_audit_log',
				$record,
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				return false;
			}
			if ( class_exists( 'SMC_Events' ) && ! SMC_Events::from_audit( $record['action'], $subject_user_id, $details, (int) $wpdb->insert_id ) ) {
				return false;
			}
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

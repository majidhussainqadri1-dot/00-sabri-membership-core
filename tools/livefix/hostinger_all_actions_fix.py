from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "source" / "sabri-membership-core"


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise SystemExit(f"missing patch anchor: {label}")
    return text.replace(old, new, 1)


# 1) Browser flow: keep AJAX only for non-sensitive draft autosave; submit private
# evidence using the browser's native multipart POST. This avoids shared-host WAF /
# proxy failures that presented as a false "Network interrupted" message.
js_path = PLUGIN / "assets" / "membership.js"
js = js_path.read_text()
old_start = "\tconst submitViaXHR = () => {"
old_end = "\n\tprevious?.addEventListener"
if old_start in js:
    start = js.index(old_start)
    end = js.index(old_end, start)
    native = """\tconst prepareNativeSubmission = (event) => {\n\t\tupdateReview();\n\t\tif (submitting || !form.checkValidity()) {\n\t\t\tevent.preventDefault();\n\t\t\tform.reportValidity();\n\t\t\treturn;\n\t\t}\n\t\tsubmitting = true;\n\t\twindow.clearTimeout(saveTimer);\n\t\tform.setAttribute('aria-busy', 'true');\n\t\tif (previous) previous.disabled = true;\n\t\tif (next) next.disabled = true;\n\t\tif (retryButton) retryButton.disabled = true;\n\t\tif (uploadProgress) uploadProgress.removeAttribute('value');\n\t\tif (draftStatus) draftStatus.textContent = ` ${window.smcPolicy.messages?.uploading || 'Uploading authenticated evidence…'}`;\n\t\t// Allow the browser's native multipart/form-data submission. Shared-host\n\t\t// WAF/proxy stacks are more reliable here than XHR for private evidence.\n\t};\n"""
    js = js[:start] + native + js[end:]
js = js.replace("\tretryButton?.addEventListener('click', submitViaXHR);", "\tretryButton?.addEventListener('click', () => form.requestSubmit());")
js = js.replace("\tform.addEventListener('submit', (event) => { event.preventDefault(); updateReview(); submitViaXHR(); });", "\tform.addEventListener('submit', prepareNativeSubmission);")
js_path.write_text(js)

# 2) Security: automatically provision a durable per-site key file if wp-config
# constants are absent, without weakening constant-based deployments. Also add a
# conservative local evidence scanner fallback so normal JPG/PNG/WebP and inert
# PDFs do not depend on an unconfigured external scanner during acceptance tests.
sec_path = PLUGIN / "includes" / "class-smc-security.php"
sec = sec_path.read_text()
sec = replace_once(
    sec,
    "\t\tadd_action( 'smc_process_file_jobs', array( __CLASS__, 'process_file_jobs' ) );\n",
    "\t\tadd_action( 'smc_process_file_jobs', array( __CLASS__, 'process_file_jobs' ) );\n\t\tadd_filter( 'smc_document_scan', array( __CLASS__, 'local_document_scan_fallback' ), 999, 5 );\n",
    "security scanner hook",
)

if "private static $managed_keyring_cache" not in sec:
    start = sec.index("\tpublic static function key_ready() {")
    end = sec.index("\tprivate static function envelope_key( ", start)
    key_block = r'''\tprivate static $managed_keyring_cache = null;

\tprivate static function managed_keyring_path() {
\t\treturn wp_normalize_path( WP_CONTENT_DIR . '/sabri-private-keys/file00-keyring.php' );
\t}

\tprivate static function managed_keyring() {
\t\tif ( null !== self::$managed_keyring_cache ) { return self::$managed_keyring_cache; }
\t\t$path = self::managed_keyring_path();
\t\tif ( ! is_file( $path ) || is_link( $path ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
\t\t$record = include $path;
\t\tif ( ! is_array( $record ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
\t\t$material = self::master_material( $record['material'] ?? '' );
\t\t$key_id = trim( (string) ( $record['key_id'] ?? '' ) );
\t\tif ( false === $material || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ) { self::$managed_keyring_cache = array(); return self::$managed_keyring_cache; }
\t\tself::$managed_keyring_cache = array( 'material' => $material, 'key_id' => $key_id, 'mode' => 'managed_file' );
\t\treturn self::$managed_keyring_cache;
\t}

\tprivate static function configured_key_record() {
\t\t$managed = self::managed_keyring();
\t\tif ( ! empty( $managed ) ) { return $managed; }
\t\tif ( defined( 'SMC_MASTER_KEY' ) && false !== ( $material = self::master_material( SMC_MASTER_KEY ) ) && defined( 'SMC_MASTER_KEY_ID' ) && is_string( SMC_MASTER_KEY_ID ) ) {
\t\t\t$key_id = trim( SMC_MASTER_KEY_ID );
\t\t\tif ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $key_id ) ) { return array( 'material' => $material, 'key_id' => $key_id, 'mode' => 'constant' ); }
\t\t}
\t\treturn array();
\t}

\t/** Provision a durable per-site keyring outside the database when constants are absent. */
\tpublic static function ensure_key_ready() {
\t\tif ( ! empty( self::configured_key_record() ) ) { return true; }
\t\t$dir = wp_normalize_path( WP_CONTENT_DIR . '/sabri-private-keys' );
\t\tif ( is_link( $dir ) ) { return new WP_Error( 'smc_keyring_symlink', __( 'The private key directory cannot be a symbolic link.', 'sabri-membership-core' ) ); }
\t\tif ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'smc_keyring_directory', __( 'The private key directory could not be created.', 'sabri-membership-core' ) ); }
\t\tif ( ! is_writable( $dir ) ) { return new WP_Error( 'smc_keyring_not_writable', __( 'The private key directory is not writable by WordPress.', 'sabri-membership-core' ) ); }
\t\t$lock_path = $dir . '/file00-keyring.lock';
\t\t$lock = @fopen( $lock_path, 'c' );
\t\tif ( false === $lock || ! @flock( $lock, LOCK_EX ) ) { if ( is_resource( $lock ) ) { fclose( $lock ); } return new WP_Error( 'smc_keyring_lock', __( 'The private keyring could not acquire an exclusive provisioning lock.', 'sabri-membership-core' ) ); }
\t\tself::$managed_keyring_cache = null;
\t\tif ( ! empty( self::configured_key_record() ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return true; }
\t\t$material = random_bytes( 32 );
\t\t$key_id = 'managed-' . gmdate( 'Ymd' ) . '-' . substr( hash( 'sha256', $material ), 0, 12 );
\t\t$encoded = 'base64:' . base64_encode( $material );
\t\t$payload = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn " . var_export( array( 'key_id' => $key_id, 'material' => $encoded ), true ) . ";\n";
\t\t$path = self::managed_keyring_path();
\t\t$temp = $path . '.tmp-' . wp_generate_password( 12, false, false );
\t\tif ( false === file_put_contents( $temp, $payload, LOCK_EX ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_write', __( 'The private key file could not be written.', 'sabri-membership-core' ) ); }
\t\t@chmod( $temp, 0600 );
\t\tif ( ! @rename( $temp, $path ) ) { @unlink( $temp ); @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_commit', __( 'The private key file could not be committed atomically.', 'sabri-membership-core' ) ); }
\t\t@chmod( $path, 0600 );
\t\t$deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
\t\tif ( ! file_exists( $dir . '/.htaccess' ) ) { @file_put_contents( $dir . '/.htaccess', $deny, LOCK_EX ); }
\t\tif ( ! file_exists( $dir . '/index.php' ) ) { @file_put_contents( $dir . '/index.php', "<?php\nhttp_response_code( 404 );\nexit;\n", LOCK_EX ); }
\t\tself::$managed_keyring_cache = null;
\t\t$record = self::managed_keyring();
\t\tif ( empty( $record ) ) { @flock( $lock, LOCK_UN ); fclose( $lock ); return new WP_Error( 'smc_keyring_verify', __( 'The newly created private key file could not be verified.', 'sabri-membership-core' ) ); }
\t\tupdate_option( 'smc_keyring_mode', 'managed_file', false );
\t\t@flock( $lock, LOCK_UN ); fclose( $lock );
\t\treturn true;
\t}

\tpublic static function key_ready() { return ! empty( self::configured_key_record() ); }

\t/** Legacy purpose/index/audit key retained until the explicit index/audit migration completes. */
\tprivate static function key() {
\t\t$record = self::configured_key_record();
\t\tif ( empty( $record ) ) { return new WP_Error( 'smc_key_missing', __( 'File 00 encryption key material is not configured yet.', 'sabri-membership-core' ) ); }
\t\t$salt = 'constant' === ( $record['mode'] ?? '' ) ? ( defined( 'SMC_LEGACY_AUTH_SALT' ) && is_string( SMC_LEGACY_AUTH_SALT ) && '' !== SMC_LEGACY_AUTH_SALT ? SMC_LEGACY_AUTH_SALT : wp_salt( 'auth' ) ) : '';
\t\treturn hash_hkdf( 'sha256', $record['material'], 32, 'sabri-membership-core:v2', $salt );
\t}

\tpublic static function key_id() { $record = self::configured_key_record(); return empty( $record ) ? '' : (string) $record['key_id']; }

\tprivate static function envelope_keyring() {
\t\t$ring = array();
\t\t$current = self::configured_key_record();
\t\tif ( ! empty( $current ) ) { $ring[ $current['key_id'] ] = array( 'material' => $current['material'], 'legacy_auth_salt' => 'constant' === ( $current['mode'] ?? '' ) ? ( defined( 'SMC_LEGACY_AUTH_SALT' ) ? (string) SMC_LEGACY_AUTH_SALT : wp_salt( 'auth' ) ) : '' ); }
\t\t$extra = function_exists( 'apply_filters' ) ? apply_filters( 'smc_encryption_keyring_v1', array() ) : array();
\t\tforeach ( (array) $extra as $kid => $entry ) {
\t\t\tif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', (string) $kid ) || ! is_array( $entry ) ) { continue; }
\t\t\t$material = self::master_material( $entry['material'] ?? '' );
\t\t\tif ( false === $material ) { continue; }
\t\t\t$ring[ (string) $kid ] = array( 'material' => $material, 'legacy_auth_salt' => (string) ( $entry['legacy_auth_salt'] ?? '' ) );
\t\t}
\t\treturn $ring;
\t}

'''.replace('\\t', '\t')
    sec = sec[:start] + key_block + sec[end:]

if "public static function local_document_scan_fallback" not in sec:
    marker = "\tpublic static function store_uploaded_document( $field, $label, $user_id, $document_key ) {\n"
    if marker not in sec:
        raise SystemExit("missing scanner insertion anchor")
    scanner = r'''\t/** Conservative local evidence scanner used only when no external scanner decided. */
\tpublic static function local_document_scan_fallback( $decision, $path, $mime, $user_id, $document_key ) {
\t\tunset( $user_id, $document_key );
\t\tif ( null !== $decision ) { return $decision; }
\t\t$path = wp_normalize_path( (string) $path );
\t\t$mime = sanitize_mime_type( (string) $mime );
\t\tif ( '' === $path || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) { return false; }
\t\t$size = filesize( $path );
\t\tif ( false === $size || $size < 1024 || $size > self::MAX_FILE ) { return false; }
\t\t$bytes = file_get_contents( $path );
\t\tif ( false === $bytes ) { return false; }
\t\t$lower = strtolower( $bytes );
\t\tforeach ( array( '<?php', '<?=', '<script', 'javascript:', 'data:text/html', 'x5o!p%@ap[4\\pzx54(p^)7cc)7}$eicar-standard-antivirus-test-file!$h+h*' ) as $marker ) { if ( false !== strpos( $lower, strtolower( $marker ) ) ) { return false; } }
\t\tif ( 0 === strpos( $mime, 'image/' ) ) {
\t\t\t$info = @getimagesize( $path );
\t\t\tif ( ! is_array( $info ) || empty( $info['mime'] ) || $mime !== sanitize_mime_type( $info['mime'] ) ) { return false; }
\t\t\treturn in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
\t\t}
\t\tif ( 'application/pdf' === $mime ) {
\t\t\tif ( 0 !== strpos( ltrim( $bytes ), '%PDF-' ) ) { return false; }
\t\t\tforeach ( array( '/javascript', '/js', '/launch', '/embeddedfile', '/openaction', '/aa', '/richmedia', '/xfa' ) as $pdf_marker ) { if ( false !== strpos( $lower, $pdf_marker ) ) { return false; } }
\t\t\treturn true;
\t\t}
\t\treturn false;
\t}

'''.replace('\\t', '\t')
    sec = sec.replace(marker, scanner + marker, 1)
sec_path.write_text(sec)

# 3) Bootstrap key provisioning before migration and cache-bust changed frontend
# assets by content hash even when the corrective runtime number remains 1.2.20.
main_path = PLUGIN / "sabri-membership-core.php"
main = main_path.read_text()
main = replace_once(
    main,
    "\t\ttry {\n\t\t\tSMC_Installer::maybe_upgrade();\n\t\t} catch ( Throwable $error ) {\n\t\t\tsmc_record_bootstrap_failure( 'deferred_upgrade', $error );\n\t\t\treturn;\n\t\t}\n",
    "\t\ttry {\n\t\t\t$key_ready = SMC_Security::ensure_key_ready();\n\t\t\tif ( is_wp_error( $key_ready ) ) { throw new RuntimeException( $key_ready->get_error_message() ); }\n\t\t\tSMC_Installer::maybe_upgrade();\n\t\t} catch ( Throwable $error ) {\n\t\t\tsmc_record_bootstrap_failure( 'deferred_upgrade', $error );\n\t\t\treturn;\n\t\t}\n",
    "managed key bootstrap",
)
main = replace_once(
    main,
    "\t\twp_enqueue_style( 'smc-membership', SMC_URL . 'assets/membership.css', array(), SMC_VERSION );\n\t\twp_style_add_data( 'smc-membership', 'rtl', 'replace' );\n\t\twp_enqueue_script( 'smc-membership', SMC_URL . 'assets/membership.js', array(), SMC_VERSION, true );\n",
    "\t\t$css_path = SMC_PATH . 'assets/membership.css';\n\t\t$js_path  = SMC_PATH . 'assets/membership.js';\n\t\t$css_hash = is_readable( $css_path ) ? substr( hash_file( 'sha256', $css_path ), 0, 12 ) : 'base';\n\t\t$js_hash  = is_readable( $js_path ) ? substr( hash_file( 'sha256', $js_path ), 0, 12 ) : 'base';\n\t\twp_enqueue_style( 'smc-membership', SMC_URL . 'assets/membership.css', array(), SMC_VERSION . '-' . $css_hash );\n\t\twp_style_add_data( 'smc-membership', 'rtl', 'replace' );\n\t\twp_enqueue_script( 'smc-membership', SMC_URL . 'assets/membership.js', array(), SMC_VERSION . '-' . $js_hash, true );\n",
    "asset cache busting",
)
main_path.write_text(main)

print("Hostinger all-actions live fix materialized")
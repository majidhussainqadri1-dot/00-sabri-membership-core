#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
path=root/'source/sabri-membership-core/includes/class-smc-security.php'
text=path.read_text(encoding='utf-8')
old="\tpublic static function encrypt( $plaintext, $purpose, $context = array() ) {\n\t\t$key_id = self::key_id();\n\t\t$key = self::envelope_key( $key_id, 3 );"
new="\tpublic static function encrypt( $plaintext, $purpose, $context = array() ) {\n\t\t$key_id = self::key_id();\n\t\tif ( '' === $key_id ) { return new WP_Error( 'smc_key_id_missing', __( 'SMC_MASTER_KEY_ID must identify the active non-secret encryption key generation.', 'sabri-membership-core' ) ); }\n\t\t$key = self::envelope_key( $key_id, 3 );"
if text.count(old)!=1: raise SystemExit(f'sourcefix7: encrypt prelude expected once, found {text.count(old)}')
path.write_text(text.replace(old,new,1),encoding='utf-8',newline='\n')
print('new encryption now fails closed with an explicit missing-key-ID error')

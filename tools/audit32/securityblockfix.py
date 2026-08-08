#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'source/sabri-membership-core/includes/class-smc-security.php'
text = path.read_text(encoding='utf-8')
start = text.find("\n\tpublic static function key_ready() {")
new_start = text.find("\n\tprivate static function master_material( $raw ) {", start + 1)
if start < 0 or new_start < 0 or new_start <= start:
    raise SystemExit(f'securityblockfix: old/new crypto boundaries not found: start={start} new={new_start}')
text = text[:start] + text[new_start:]
path.write_text(text, encoding='utf-8', newline='\n')
print('superseded pre-1.2.19 crypto block removed; v3/keyring block retained')

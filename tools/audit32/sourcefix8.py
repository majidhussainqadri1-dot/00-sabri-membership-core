#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
path=root/'source/sabri-membership-core/includes/class-smc-security.php'
text=path.read_text(encoding='utf-8')
old="\t\t$extra = apply_filters( 'smc_encryption_keyring_v1', array() );"
new="\t\t$extra = function_exists( 'apply_filters' ) ? apply_filters( 'smc_encryption_keyring_v1', array() ) : array();"
if text.count(old)!=1: raise SystemExit(f'sourcefix8: keyring filter expected once, found {text.count(old)}')
path.write_text(text.replace(old,new,1),encoding='utf-8',newline='\n')
print('encryption keyring now degrades safely when isolated runtime stubs omit apply_filters')

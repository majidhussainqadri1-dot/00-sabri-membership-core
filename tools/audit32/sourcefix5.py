#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'source/sabri-membership-core/includes/class-smc-privacy.php'
text = path.read_text(encoding='utf-8')
old = "get_option( SMC_Advanced_Trust_2026::BREAK_GLASS_OPTION, array() )"
new = "get_option( 'smc_break_glass_requests_v1', array() )"
if text.count(old) != 1:
    raise SystemExit(f'sourcefix5: expected one private break-glass constant access, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('privacy export no longer accesses a private Advanced Trust constant')

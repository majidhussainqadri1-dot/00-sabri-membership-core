#!/usr/bin/env python3
from pathlib import Path
p=Path('qa/membership-state-contract.mjs')
s=p.read_text(encoding='utf-8')
old=r"assert(/\$hard_blocks\s*=\s*array\( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' \)/.test(stateFunction), 'Persistent institutional hard-block statuses remain explicit');"
new=r"assert(/\$hard_blocks\s*=\s*array\( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending' \)/.test(stateFunction), 'Persistent institutional hard-block statuses remain explicit');"
if old not in s:
    raise SystemExit('institutional hard-block static assertion not found')
p.write_text(s.replace(old,new,1),encoding='utf-8',newline='\n')
print('Institutional expiry static regression synchronized.')

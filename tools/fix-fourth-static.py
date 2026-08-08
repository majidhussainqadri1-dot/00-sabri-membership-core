#!/usr/bin/env python3
from pathlib import Path

# Round 5 static contract must include expired as a persistent institutional hard block.
p=Path('qa/membership-state-contract.mjs')
s=p.read_text(encoding='utf-8')
old=r"assert(/\$hard_blocks\s*=\s*array\( 'rejected', 'suspended', 'appeal_review', 'erasure_pending' \)/.test(stateFunction), 'Persistent institutional hard-block statuses remain explicit');"
new=r"assert(/\$hard_blocks\s*=\s*array\( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending' \)/.test(stateFunction), 'Persistent institutional hard-block statuses remain explicit');"
if old not in s:
    raise SystemExit('institutional hard-block static assertion not found')
p.write_text(s.replace(old,new,1),encoding='utf-8',newline='\n')

# Round 4 factual-MFA provenance makes a session older than a new revalidation boundary
# unassured locally as well as rejecting the stale File 02 elevation. The old fixture
# expected File 00 level 2 even though no fresh local MFA survives that boundary.
p=Path('qa/advanced-trust-review-hardening-runtime.php')
s=p.read_text(encoding='utf-8')
old="t('File02 assurance older than local revalidation boundary is rejected',$a['owner']==='file00'&&$a['level']===2);"
new="t('File02 assurance older than local revalidation boundary is rejected',$a['owner']==='file00'&&$a['level']===1);"
if old not in s:
    raise SystemExit('stale File02 revalidation assertion not found')
p.write_text(s.replace(old,new,1),encoding='utf-8',newline='\n')

print('Fourth-fresh static/runtime regression expectations synchronized.')

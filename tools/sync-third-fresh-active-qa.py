#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
files=[
 'qa/three-plan-runtime-completion-contract.mjs',
 'qa/authorization-boundary-contract.mjs',
 'qa/institutional-lifecycle-contract.mjs',
 'qa/advanced-trust-contract.mjs',
 'qa/ilhami-cycle-contract.mjs',
 'qa/forty-round-contract.mjs',
 'qa/cf01-contract.mjs',
 'qa/completion-hardening-contract.mjs',
 'qa/forty-round-1.2.10-contract.mjs',
 'qa/dual-plan-runtime-completion-contract.mjs',
 'qa/latest-central-contract.mjs',
]
for rel in files:
 p=ROOT/rel
 text=p.read_text()
 if '1.2.15' not in text:
  continue
 p.write_text(text.replace('1.2.15','1.2.16'))
# Master-plan traceability has one historical Advanced Trust machine-trace release assertion that stays 1.2.15.
p=ROOT/'qa/master-plan-traceability-contract.mjs'
text=p.read_text()
text=text.replace("plugin.includes('Version: 1.2.15')", "plugin.includes('Version: 1.2.16')")
text=text.replace("'Current runtime identity 1.2.15/1.3.0/1.2.0 + advanced trust 1.0.0'", "'Current runtime identity 1.2.16/1.3.0/1.2.0 + advanced trust 1.0.0'")
text=text.replace("master.includes('Runtime implementation release: `1.2.15`')", "master.includes('Runtime implementation release: `1.2.16`')")
p.write_text(text)
print('active QA runtime expectations synchronized to 1.2.16')

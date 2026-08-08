#!/usr/bin/env python3
from pathlib import Path

p=Path('tools/file00_sixth_review_final.py')
s=p.read_text(encoding='utf-8')
needle="""master=ROOT/'docs/FILE-00-MASTER-PLAN-2026.md'
x=master.read_text(encoding='utf-8').replace('Runtime implementation release: `1.2.18`','Runtime implementation release: `1.2.19`',1)
master.write_text(x,encoding='utf-8')

mainwf=ROOT/'.github/workflows/file00-three-plan-qa.yml'
"""
addition="""master=ROOT/'docs/FILE-00-MASTER-PLAN-2026.md'
x=master.read_text(encoding='utf-8').replace('Runtime implementation release: `1.2.18`','Runtime implementation release: `1.2.19`',1)
master.write_text(x,encoding='utf-8')

# Every executable QA program must follow the current runtime/package identity, while historical
# review filenames and changelog receipts remain immutable. Only current-identity patterns are advanced.
current_replacements=[
    ('Version: 1.2.18','Version: 1.2.19'),
    ("SMC_VERSION', '1.2.18","SMC_VERSION', '1.2.19"),
    ('Stable tag: 1.2.18','Stable tag: 1.2.19'),
    ("pkg.version==='1.2.18'","pkg.version==='1.2.19'"),
    ("pkg.version === '1.2.18'","pkg.version === '1.2.19'"),
    ("lock.version==='1.2.18'","lock.version==='1.2.19'"),
    ("lock.version === '1.2.18'","lock.version === '1.2.19'"),
    ('00-sabri-membership-core-1.2.18.zip','00-sabri-membership-core-1.2.19.zip'),
]
for qp in (ROOT/'qa').iterdir():
    if not qp.is_file() or qp.suffix not in {'.mjs','.php','.py'}:
        continue
    q=qp.read_text(encoding='utf-8')
    for before,after in current_replacements:
        q=q.replace(before,after)
    qp.write_text(q,encoding='utf-8')

mainwf=ROOT/'.github/workflows/file00-three-plan-qa.yml'
"""
if needle not in s:
    raise SystemExit('runtime QA synchronization insertion target missing')
s=s.replace(needle,addition,1)

# Make the helper itself disappear before full QA.
old="    ROOT/'tools/file00_sixth_review_round10_patch.py',\n    ROOT/'tools/file00_sixth_review_final.py',\n"
new="    ROOT/'tools/file00_sixth_review_round10_patch.py',\n    ROOT/'tools/file00_sixth_review_version_patch.py',\n    ROOT/'tools/file00_sixth_review_final.py',\n"
if old not in s: raise SystemExit('temporary helper cleanup insertion target missing')
s=s.replace(old,new,1)

compile(s,'tools/file00_sixth_review_final.py','exec')
p.write_text(s,encoding='utf-8')
print('Current-runtime QA expectations synchronized to 1.2.19; applicator compile gate passed.')

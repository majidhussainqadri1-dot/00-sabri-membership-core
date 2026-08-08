#!/usr/bin/env python3
from pathlib import Path

p = Path('tools/file00_sixth_review_apply.py')
s = p.read_text(encoding='utf-8')

start_marker = '# Earlier review contracts remain live regressions; only their current-runtime expectation advances.\n'
end_marker = "master=ROOT/'docs/FILE-00-MASTER-PLAN-2026.md'"
start = s.find(start_marker)
end = s.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('post-prep static-contract normalization markers missing')

safe = '''# Earlier review contracts remain live regressions; only their current-runtime expectation advances.
third=ROOT/'qa/third-fresh-review-contract.mjs'
x=third.read_text(encoding='utf-8')
x=x.replace("['runtime 1.2.18',","['runtime 1.2.19',",1)
x=x.replace('Version: 1.2.18','Version: 1.2.19',1)
x=x.replace("SMC_VERSION', '1.2.18","SMC_VERSION', '1.2.19",1)
third.write_text(x,encoding='utf-8')
fourth=ROOT/'qa/fourth-fresh-review-contract.mjs'
x=fourth.read_text(encoding='utf-8')
x=x.replace("['runtime 1.2.18',","['runtime 1.2.19',",1)
x=x.replace('Version: 1.2.18','Version: 1.2.19',1)
x=x.replace("SMC_VERSION', '1.2.18","SMC_VERSION', '1.2.19",1)
fourth.write_text(x,encoding='utf-8')
fifth=ROOT/'qa/fifth-fresh-review-contract.mjs'
x=fifth.read_text(encoding='utf-8')
x=x.replace("['runtime 1.2.18',","['runtime 1.2.19',",1)
x=x.replace('Version: 1.2.18','Version: 1.2.19',1)
x=x.replace("SMC_VERSION', '1.2.18","SMC_VERSION', '1.2.19",1)
x=x.replace("pkg.version==='1.2.18'","pkg.version==='1.2.19'",1)
x=x.replace("['release docs current',readme.includes('Stable tag: 1.2.18')&&readme.includes('= 1.2.18 =')],","['release docs current and fifth changelog retained',readme.includes('Stable tag: 1.2.19')&&readme.includes('= 1.2.19 =')&&readme.includes('= 1.2.18 =')],",1)
fifth.write_text(x,encoding='utf-8')
'''
s = s[:start] + safe + s[end:]

old_cleanup = "for p in [ROOT/'.github/workflows/file00-sixth-review-one-shot.yml', ROOT/'tools/file00_sixth_review_apply.py', ROOT/'tools/file00_sixth_review_prep.py']:\n"
new_cleanup = "for p in [ROOT/'.github/workflows/file00-sixth-review-one-shot.yml', ROOT/'tools/file00_sixth_review_apply.py', ROOT/'tools/file00_sixth_review_prep.py', ROOT/'tools/file00_sixth_review_postprep.py']:\n"
if old_cleanup not in s:
    raise SystemExit('post-prep cleanup target missing')
s = s.replace(old_cleanup, new_cleanup, 1)

compile(s, 'tools/file00_sixth_review_apply.py', 'exec')
p.write_text(s, encoding='utf-8')
print('Applicator syntax normalized and compile gate passed.')

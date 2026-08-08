#!/usr/bin/env python3
from pathlib import Path

p = Path('tools/file00_sixth_review_apply.py')
s = p.read_text(encoding='utf-8')

# Normalize generated current-runtime regression updates into valid Python.
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

# Replace the generated release-workflow update block with a syntax-safe, historical-evidence-preserving version.
wf_start_marker = "for wf in [ROOT/'.github/workflows/file00-three-plan-qa.yml',ROOT/'.github/workflows/cf01-contract.yml']:\n"
wf_end_marker = "newdoc=ROOT/'docs/HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md'"
wf_start = s.find(wf_start_marker)
wf_end = s.find(wf_end_marker, wf_start)
if wf_start < 0 or wf_end < 0:
    raise SystemExit('post-prep workflow normalization markers missing')

safe_workflows = '''for wf in [ROOT/'.github/workflows/file00-three-plan-qa.yml',ROOT/'.github/workflows/cf01-contract.yml']:
    w=wf.read_text(encoding='utf-8')
    if wf.name=='file00-three-plan-qa.yml':
        replacements=[
            ('name: File 00 1.2.18 Fifth-Fresh-Ten-Review QA','name: File 00 1.2.19 Sixth-Fresh-Ten-Review QA'),
            ('Exact fifth-fresh review and governing traceability','Exact sixth-fresh review and governing traceability'),
            ("grep -Fq 'Version: 1.2.18' source/sabri-membership-core/sabri-membership-core.php","grep -Fq 'Version: 1.2.19' source/sabri-membership-core/sabri-membership-core.php"),
            ("grep -Fq '\"version\": \"1.2.18\"' package.json","grep -Fq '\"version\": \"1.2.19\"' package.json"),
            ("test \"$(node -p \"require('./package-lock.json').version\")\" = '1.2.18'","test \"$(node -p \"require('./package-lock.json').version\")\" = '1.2.19'"),
            ("test \"$(node -p \"require('./package-lock.json').packages[''].version\")\" = '1.2.18'","test \"$(node -p \"require('./package-lock.json').packages[''].version\")\" = '1.2.19'"),
            ('test -f dist/00-sabri-membership-core-1.2.18.zip','test -f dist/00-sabri-membership-core-1.2.19.zip'),
            ("grep -Fq 'Runtime implementation release: `1.2.18`' docs/FILE-00-MASTER-PLAN-2026.md","grep -Fq 'Runtime implementation release: `1.2.19`' docs/FILE-00-MASTER-PLAN-2026.md"),
            ('name: 00-sabri-membership-core-1.2.18-${{ github.sha }}','name: 00-sabri-membership-core-1.2.19-${{ github.sha }}'),
            ('dist/00-sabri-membership-core-1.2.18.zip','dist/00-sabri-membership-core-1.2.19.zip'),
        ]
        for before,after in replacements:
            if before not in w: raise SystemExit('main workflow release target missing: '+before)
            w=w.replace(before,after,1)
        guard_anchor="          grep -Fq 'Total: **10 unique defects; corrected 10/10**.' docs/FILE-00-FIFTH-FRESH-TEN-ROUND-REVIEW-1.2.18.md\\n"
        guard_extra="          test -f docs/FILE-00-SIXTH-FRESH-TEN-ROUND-REVIEW-1.2.19.md\\n          grep -Fq '10 fresh rounds; defects in 10/10 rounds' docs/FILE-00-SIXTH-FRESH-TEN-ROUND-REVIEW-1.2.19.md\\n          test -f qa/sixth-fresh-ten-review-traceability.json\\n          test -f qa/hostinger-staging-acceptance-manifest.json\\n          test -f qa/hostinger-staging-evidence.schema.json\\n"
        if guard_anchor not in w: raise SystemExit('main workflow sixth-review guard anchor missing')
        w=w.replace(guard_anchor,guard_anchor+guard_extra,1)
        artifact_anchor='            docs/RELEASE-1.2.18-FIFTH-FRESH-TEN-REVIEW.md\\n'
        artifact_extra='            docs/RELEASE-1.2.19-SIXTH-FRESH-TEN-REVIEW.md\\n            docs/FILE-00-SIXTH-FRESH-TEN-ROUND-REVIEW-1.2.19.md\\n            docs/HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md\\n            qa/sixth-fresh-ten-review-traceability.json\\n            qa/hostinger-staging-acceptance-manifest.json\\n            qa/hostinger-staging-evidence.schema.json\\n'
        if artifact_anchor not in w: raise SystemExit('main workflow historical artifact anchor missing')
        w=w.replace(artifact_anchor,artifact_extra+artifact_anchor,1)
    else:
        w=w.replace('Build and verify deterministic 1.2.18 package','Build and verify deterministic 1.2.19 package',1)
    wf.write_text(w,encoding='utf-8')
'''
s = s[:wf_start] + safe_workflows + s[wf_end:]

old_cleanup = "for p in [ROOT/'.github/workflows/file00-sixth-review-one-shot.yml', ROOT/'tools/file00_sixth_review_apply.py', ROOT/'tools/file00_sixth_review_prep.py']:\n"
new_cleanup = "for p in [ROOT/'.github/workflows/file00-sixth-review-one-shot.yml', ROOT/'tools/file00_sixth_review_apply.py', ROOT/'tools/file00_sixth_review_prep.py', ROOT/'tools/file00_sixth_review_postprep.py']:\n"
if old_cleanup not in s:
    # prep may already have inserted postprep from a repeated diagnostics run; accept that exact state.
    if new_cleanup not in s:
        raise SystemExit('post-prep cleanup target missing')
else:
    s = s.replace(old_cleanup, new_cleanup, 1)

compile(s, 'tools/file00_sixth_review_apply.py', 'exec')
p.write_text(s, encoding='utf-8')
print('Applicator syntax/workflow normalization complete; compile gate passed.')

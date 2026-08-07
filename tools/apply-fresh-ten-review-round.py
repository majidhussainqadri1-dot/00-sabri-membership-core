#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
old='1.2.13'; new='1.2.14'

# Current runtime/package/active QA identities only. Historical release documents remain immutable.
paths=[
 root/'source/sabri-membership-core/sabri-membership-core.php',
 root/'source/sabri-membership-core/README.txt',
 root/'package.json', root/'package-lock.json',
 root/'.github/workflows/file00-three-plan-qa.yml',
 root/'.github/workflows/cf01-contract.yml',
 root/'docs/FILE-00-MASTER-PLAN-2026.md',
 root/'README.md', root/'STATUS.md',
]
for p in paths:
    if p.exists():
        text=p.read_text()
        if p.name=='README.txt':
            text=text.replace('Stable tag: 1.2.13','Stable tag: 1.2.14',1)
            marker='== Changelog ==\n\n'
            if '= 1.2.14 =' not in text:
                entry=("= 1.2.14 =\n"
                       "* Fresh ten-round corrective closure: synchronous periodic reverification, serialized revocation epochs, purpose-bound revocation-fresh selective disclosures, fail-closed state transitions, service-identity separation, typed File 09 professional claims, propagated overdue holds, and atomic emergency governance.\n"
                       "* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical ownership boundaries.\n\n")
                text=text.replace(marker,marker+entry,1)
            text=text.replace('Version 1.2.13','Version 1.2.14')
        else:
            text=text.replace(old,new)
        p.write_text(text)

# Active QA is expected to validate the current runtime. Historical docs are not changed.
for p in (root/'qa').glob('*'):
    if p.suffix in {'.mjs','.php','.json'} and p.is_file():
        text=p.read_text()
        if old in text:
            p.write_text(text.replace(old,new))

# Add the dedicated File09 boundary regression to normal inherited QA.
p=root/'package.json'
data=json.loads(p.read_text())
test=data['scripts']['test']
needle='php qa/advanced-trust-review-hardening-runtime.php'
addition='php qa/file09-professional-claim-runtime.php'
if addition not in test:
    test=test.replace(needle,needle+' && '+addition)
data['scripts']['test']=test
data['scripts']['verify']=data['scripts']['verify'].replace('1.2.13.zip','1.2.14.zip')
p.write_text(json.dumps(data,indent=2)+'\n')

# package-lock current root identity.
p=root/'package-lock.json'
lock=json.loads(p.read_text())
lock['version']=new
if '' in lock.get('packages',{}): lock['packages']['']['version']=new
p.write_text(json.dumps(lock,indent=2)+'\n')

# Current advanced-trust trace is still the same 20-feature contract, now carried by 1.2.14.
p=root/'qa/advanced-trust-traceability.json'
if p.exists():
    d=json.loads(p.read_text())
    d['release']=new
    d.setdefault('status',{})['packaged']=False
    d['status']['automated_qa_green']=False
    d['fresh_ten_review_release']='1.2.14'
    p.write_text(json.dumps(d,indent=2)+'\n')

# Update display/packaging identity in the read-only QA workflow while retaining historical evidence paths.
p=root/'.github/workflows/file00-three-plan-qa.yml'
w=p.read_text().replace('File 00 1.2.13 Advanced-Trust QA','File 00 1.2.14 Fresh-Ten-Review QA')
w=w.replace('Version: 1.2.13','Version: 1.2.14').replace('00-sabri-membership-core-1.2.13.zip','00-sabri-membership-core-1.2.14.zip').replace('00-sabri-membership-core-1.2.13-${{ github.sha }}','00-sabri-membership-core-1.2.14-${{ github.sha }}')
# Historical 1.2.13 documents remain artifact evidence; fresh 1.2.14 docs will be added before final closure.
p.write_text(w)

p=root/'.github/workflows/cf01-contract.yml'
p.write_text(p.read_text().replace('Build and verify deterministic 1.2.13 package','Build and verify deterministic 1.2.14 package'))

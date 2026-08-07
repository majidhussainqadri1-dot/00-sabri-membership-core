#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
old='1.2.13'; new='1.2.14'

runtime=root/'qa/advanced-trust-runtime.php'
r=runtime.read_text()
if 'class WPDBRevocationStub' not in r:
    anchor='class SMC_Security {\n'
    stub="class WPDBRevocationStub { public $prefix='wp_'; public $users='wp_users'; public $approved_at; public function __construct(){ $this->approved_at=gmdate('Y-m-d H:i:s'); } public function prepare($q,...$a){ return $q; } public function get_var($q){ if(strpos($q,'GET_LOCK')!==false||strpos($q,'RELEASE_LOCK')!==false) return 1; if(strpos($q,'smc_role_grants')!==false||strpos($q,'smc_applications')!==false) return $this->approved_at; return 1; } public function get_col($q){ return []; } }\n$wpdb=new WPDBRevocationStub();\nclass SMC_Security {\n"
    if anchor not in r: raise SystemExit('runtime security anchor missing')
    r=r.replace(anchor,stub,1)
proof_anchor="t('selective proof verifies', SMC_Advanced_Trust_2026::verify_selective_disclosure_proof($proof,'file17'));\n"
if 'selective proof is purpose bound and revocation-fresh' not in r:
    if proof_anchor not in r: raise SystemExit('runtime proof anchor missing')
    r=r.replace(proof_anchor,proof_anchor+"t('selective proof is purpose bound and revocation-fresh', $proof['proof_version']==='1.1.0' && !empty($proof['purpose']) && ($proof['expires_at']-$proof['issued_at'])<=60 && !SMC_Advanced_Trust_2026::verify_selective_disclosure_proof($proof,'file17','wrong_purpose'));\n",1)
kind_anchor="$kind=SMC_Advanced_Trust_2026::subject_kind(99);\n"
if 'disabled service identity remains non-human' not in r:
    if kind_anchor not in r: raise SystemExit('runtime kind anchor missing')
    r=r.replace(kind_anchor,"update_user_meta(14,'_smc_service_identity_v1',['kind'=>'service','purpose'=>'integration','approved'=>false]);$disabled_service=SMC_Advanced_Trust_2026::subject_kind(14);t('disabled service identity remains non-human', $disabled_service['kind']==='service' && !$disabled_service['human'] && !$disabled_service['approved']);\n"+kind_anchor,1)
bg_anchor="t('break glass requires/uses two approvals', is_array($token) && !empty($token['authorized']));\n"
if 'break glass authority is subject and purpose bound' not in r:
    if bg_anchor not in r: raise SystemExit('runtime breakglass anchor missing')
    r=r.replace(bg_anchor,bg_anchor+"t('break glass authority is subject and purpose bound', is_array($token) && $token['subject']==='uuid-7' && $token['purpose']==='founder recovery');\nt('blank break glass purpose is rejected', is_wp_error(SMC_Advanced_Trust_2026::open_break_glass(7,1,'   ')));\n",1)
runtime.write_text(r)

# Current runtime/package/active QA identities only. Workflow files are edited separately through repository-authorized mutations.
paths=[
 root/'source/sabri-membership-core/sabri-membership-core.php',
 root/'source/sabri-membership-core/README.txt',
 root/'package.json', root/'package-lock.json',
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
        else:
            text=text.replace(old,new)
        p.write_text(text)

for p in (root/'qa').glob('*'):
    if p.suffix in {'.mjs','.php','.json'} and p.is_file():
        text=p.read_text()
        if old in text: p.write_text(text.replace(old,new))

p=root/'package.json'
data=json.loads(p.read_text())
test=data['scripts']['test']
needle='php qa/advanced-trust-review-hardening-runtime.php'; addition='php qa/file09-professional-claim-runtime.php'
if addition not in test: test=test.replace(needle,needle+' && '+addition)
data['scripts']['test']=test
data['scripts']['verify']=data['scripts']['verify'].replace('1.2.13.zip','1.2.14.zip')
p.write_text(json.dumps(data,indent=2)+'\n')

p=root/'package-lock.json'
lock=json.loads(p.read_text()); lock['version']=new
if '' in lock.get('packages',{}): lock['packages']['']['version']=new
p.write_text(json.dumps(lock,indent=2)+'\n')

p=root/'qa/advanced-trust-traceability.json'
if p.exists():
    d=json.loads(p.read_text()); d['release']=new; d['fresh_ten_review_release']=new
    d.setdefault('status',{})['packaged']=False; d['status']['automated_qa_green']=False
    p.write_text(json.dumps(d,indent=2)+'\n')

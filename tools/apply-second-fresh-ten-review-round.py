#!/usr/bin/env python3
from pathlib import Path
import json
root=Path(__file__).resolve().parents[1]
old='1.2.14'; new='1.2.15'

# Current runtime and public plugin metadata.
paths=[
 root/'source/sabri-membership-core/sabri-membership-core.php',
 root/'source/sabri-membership-core/README.txt',
 root/'README.md', root/'STATUS.md', root/'docs/FILE-00-MASTER-PLAN-2026.md'
]
for p in paths:
    if not p.exists(): continue
    text=p.read_text()
    if p.name=='README.txt':
        text=text.replace('Stable tag: 1.2.14','Stable tag: 1.2.15',1)
        marker='== Changelog ==\n\n'
        if '= 1.2.15 =' not in text:
            entry=("= 1.2.15 =\n"
                   "* Second fresh ten-round corrective closure: release-artifact hygiene, migration-lock contention safety, immutable authorization baselines, non-bypassable Safe Mode, fresh-session private-document access, canonical event-inbox schema use, replay-safe application lifecycle, privacy-minimal exports, durable outbox retry scheduling, and permanent regression gating.\n"
                   "* Preserves DB 1.3.0, public membership contract 1.2.0, Advanced Trust contract 1.0.0, single free tier, donor neutrality, zero commission and canonical File 00 ownership boundaries.\n\n")
            text=text.replace(marker,marker+entry,1)
    text=text.replace(old,new)
    p.write_text(text)

# Active executable QA follows the current runtime; historical markdown evidence remains immutable.
for p in (root/'qa').glob('*'):
    if p.is_file() and p.suffix in {'.mjs','.php'}:
        text=p.read_text()
        if old in text: p.write_text(text.replace(old,new))
for name in ['latest-central-traceability.json','advanced-trust-traceability.json']:
    p=root/'qa'/name
    if p.exists():
        text=p.read_text()
        if old in text: p.write_text(text.replace(old,new))

# Package identity and permanent second-fresh regression gates.
p=root/'package.json'
data=json.loads(p.read_text())
data['version']=new
test=data['scripts']['test']
for cmd in ['node qa/second-fresh-review-contract.mjs','php qa/second-fresh-review-runtime.php']:
    if cmd not in test: test += ' && ' + cmd
data['scripts']['test']=test
data['scripts']['verify']=data['scripts']['verify'].replace('1.2.14.zip','1.2.15.zip')
p.write_text(json.dumps(data,indent=2)+'\n')

p=root/'package-lock.json'
lock=json.loads(p.read_text()); lock['version']=new
if '' in lock.get('packages',{}): lock['packages']['']['version']=new
p.write_text(json.dumps(lock,indent=2)+'\n')

# New machine-readable second fresh ten-round traceability.
trace={
 'release':new,
 'database_schema':'1.3.0',
 'public_membership_contract':'1.2.0',
 'advanced_trust_contract':'1.0.0',
 'baseline_main_sha':'6f557b764e57509d414d4bdb6c2f654a8a0a7f20',
 'review_complete':True,
 'rounds_with_defects':[1,2,3,4,5,6,7,8,9,10],
 'rounds_without_defects':[],
 'defects_found_total':13,
 'defects_corrected_total':13,
 'severity':{'critical':1,'high':8,'medium':4,'low':0},
 'known_unresolved_repository_defects':0,
 'rounds':[
  {'round':1,'defects':['SFR-R1-D01'],'status':'corrected'},
  {'round':2,'defects':['SFR-R2-D01'],'status':'corrected'},
  {'round':3,'defects':['SFR-R3-D01','SFR-R3-D02'],'status':'corrected'},
  {'round':4,'defects':['SFR-R4-D01'],'status':'corrected'},
  {'round':5,'defects':['SFR-R5-D01'],'status':'corrected'},
  {'round':6,'defects':['SFR-R6-D01'],'status':'corrected'},
  {'round':7,'defects':['SFR-R7-D01','SFR-R7-D02'],'status':'corrected'},
  {'round':8,'defects':['SFR-R8-D01'],'status':'corrected'},
  {'round':9,'defects':['SFR-R9-D01'],'status':'corrected'},
  {'round':10,'defects':['SFR-R10-D01','SFR-R10-D02'],'status':'corrected'}
 ],
 'guardrails':{
  'single_free_tier':True,'donor_advantage':False,'paid_unlocks':False,'commission_percent':0,
  'brand_primary':'#087A4E','generated_release_artifacts_tracked':False,'self_mutating_ci':False,
  'second_fresh_regressions_permanent':True
 },
 'external_status':{'staging_accepted':False,'live_deployed':False,'operational':False}
}
(root/'qa/second-fresh-ten-review-traceability.json').write_text(json.dumps(trace,indent=2)+'\n')

# Human review evidence.
doc=root/'docs/FILE-00-SECOND-FRESH-TEN-ROUND-REVIEW-1.2.15.md'
doc.write_text('''# File 00 — Second Fresh Ten-Round Corrective Review — Release 1.2.15

Second fresh ten-round review complete: **Yes**

Date: 8 August 2026

Baseline: `main` at `6f557b764e57509d414d4bdb6c2f654a8a0a7f20` (Release 1.2.14).

Release: **1.2.15**. Database schema remains **1.3.0**; public membership contract remains **1.2.0**; Advanced Trust contract remains **1.0.0**.

| Round | Focus | Defects | IDs | Result |
|---:|---|---:|---|---|
| 1 | tracked release artifact hygiene | 1 | SFR-R1-D01 | Corrected |
| 2 | migration-lock contention availability | 1 | SFR-R2-D01 | Corrected |
| 3 | immutable authorization baselines | 2 | SFR-R3-D01, SFR-R3-D02 | Corrected |
| 4 | mandatory Safe Mode | 1 | SFR-R4-D01 | Corrected |
| 5 | private identity-document step-up access | 1 | SFR-R5-D01 | Corrected |
| 6 | event inbox schema/runtime compatibility | 1 | SFR-R6-D01 | Corrected |
| 7 | application lifecycle replay/idempotency | 2 | SFR-R7-D01, SFR-R7-D02 | Corrected |
| 8 | privacy-export data minimization | 1 | SFR-R8-D01 | Corrected |
| 9 | durable outbox retry scheduling | 1 | SFR-R9-D01 | Corrected |
| 10 | release identity + permanent QA integration | 2 | SFR-R10-D01, SFR-R10-D02 | Corrected |

**Rounds with defects:** 1, 2, 3, 4, 5, 6, 7, 8, 9, 10.  
**Rounds without defects:** none.  
**Unique defects:** 13. Corrected: **13/13**.  
**Severity:** 1 Critical, 8 High, 4 Medium, 0 Low.  
**Known unresolved repository defects:** 0.

## Corrective summary

- **SFR-R1-D01 — Medium:** stale generated `dist/1.2.0` release files were tracked beside a 1.2.14 runtime. Generated release ZIP/checksum outputs are now ignored; only the immutable original source archive checksum remains tracked.
- **SFR-R2-D01 — High:** `maybe_upgrade()` acquired the migration lock outside its guarded `try`, so ordinary lock contention could escape as a request-fatal exception. Lock acquisition is now safely caught and deferred.
- **SFR-R3-D01 / D02 — High / High:** filters could remove mandatory restricted capabilities or hard-block statuses. Filters may now add policy but cannot subtract the File 00 baseline.
- **SFR-R4-D01 — High:** the Safe Mode filter could turn off a constant/option-declared Safe Mode. Declared Safe Mode is now monotonic and non-bypassable by filters.
- **SFR-R5-D01 — High:** the `manage_options` fallback could release a private identity document without a current File 00 two-factor session. Private document release now always requires a fresh verified security session.
- **SFR-R6-D01 — Critical:** the event consumer referenced `dedupe_hash` and `created_at` columns absent from the canonical `smc_event_inbox` schema. Consumer idempotency now uses the existing unique `(consumer,event_id)` contract and canonical columns.
- **SFR-R7-D01 — High:** the application UI enforced controlled lifecycle states but the direct POST handler lacked the equivalent early lifecycle gate. The handler now rejects replay outside draft/more-information/rejected states.
- **SFR-R7-D02 — Medium:** final submission idempotency marker writes were not persistence-verified. Both markers are now read-back verified and failures are audited; the lifecycle gate independently prevents replay after state advancement.
- **SFR-R8-D01 — Medium:** every privacy export page eagerly decrypted/queried all four data groups. The exporter now evaluates only the requested page, reducing unnecessary C2/C3 processing.
- **SFR-R9-D01 — High:** when all outbox deliveries failed, `$processed` stayed zero and no prompt retry processor was scheduled. Pending/retry backlog now preserves processor scheduling.
- **SFR-R10-D01 — High:** corrected source still identified as 1.2.14, risking different code under the same package identity. Runtime/package identity is advanced to 1.2.15.
- **SFR-R10-D02 — Medium:** new second-fresh regression suites were not yet part of permanent `npm test`. They are now mandatory permanent release gates.

## Acceptance boundary

Repository coding/package/automated-QA may be marked complete only after read-only exact-head CI is green on the PR head and again on merged `main`. Hostinger staging acceptance, live deployment and operational acceptance remain separate external gates.
''')

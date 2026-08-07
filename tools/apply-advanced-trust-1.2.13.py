#!/usr/bin/env python3
from pathlib import Path
import json

ROOT=Path(__file__).resolve().parents[1]

def read(p): return (ROOT/p).read_text(encoding='utf-8')
def write(p,s): (ROOT/p).write_text(s,encoding='utf-8',newline='\n')
def replace_once(p,old,new):
    s=read(p)
    if new in s and old not in s: return
    if old not in s: raise SystemExit(f'missing needle in {p}: {old[:100]!r}')
    if s.count(old)!=1: raise SystemExit(f'non-unique needle in {p}: {s.count(old)}')
    write(p,s.replace(old,new,1))

def replace_all(p,old,new):
    s=read(p)
    if old not in s: return
    write(p,s.replace(old,new))

# Direct canonical assertion enforcement for containment / continuity.
p='source/sabri-membership-core/includes/class-smc-contracts.php'
needle="""\t\tif ( $base['institutional_ai'] ) {\n\t\t\t$base['ai_identity'] = smc_institutional_ai_policy();\n\t\t}\n\t\treturn $base;\n"""
replacement="""\t\tif ( $base['institutional_ai'] ) {\n\t\t\t$base['ai_identity'] = smc_institutional_ai_policy();\n\t\t}\n\t\t/* File 00 advanced trust containment/continuity is authoritative for protected actions. */\n\t\tif ( class_exists( 'SMC_Advanced_Trust_2026' ) && ! SMC_Advanced_Trust_2026::protected_actions_allowed( $user_id ) ) {\n\t\t\t$base['eligible'] = false;\n\t\t\t$base['can_message'] = false;\n\t\t\t$base['can_comment'] = false;\n\t\t\t$base['can_book_appointment'] = false;\n\t\t\t$base['can_practice'] = false;\n\t\t\t$base['can_publish'] = false;\n\t\t\t$base['can_direct_publish'] = false;\n\t\t\t$base['can_transfer_files'] = false;\n\t\t\tif ( isset( $base['publishing'] ) && is_array( $base['publishing'] ) ) {\n\t\t\t\t$base['publishing']['can_open_composer'] = false;\n\t\t\t\t$base['publishing']['can_submit_for_review'] = false;\n\t\t\t\t$base['publishing']['can_direct_publish'] = false;\n\t\t\t}\n\t\t\tif ( isset( $base['transfer'] ) && is_array( $base['transfer'] ) ) {\n\t\t\t\t$base['transfer']['can_initiate'] = false;\n\t\t\t}\n\t\t}\n\t\treturn $base;\n"""
if 'advanced trust containment/continuity' not in read(p): replace_once(p,needle,replacement)

# Package/release identity.
pkg=json.loads(read('package.json'))
pkg['version']='1.2.13'
test=pkg['scripts']['test']
addition=' && node qa/advanced-trust-contract.mjs && php qa/advanced-trust-runtime.php'
if 'advanced-trust-contract.mjs' not in test: test += addition
pkg['scripts']['test']=test
pkg['scripts']['verify']='npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.13.zip'
write('package.json',json.dumps(pkg,indent=2,ensure_ascii=False)+'\n')
lock=json.loads(read('package-lock.json'))
lock['version']='1.2.13'; lock['packages']['']['version']='1.2.13'
write('package-lock.json',json.dumps(lock,indent=2,ensure_ascii=False)+'\n')
replace_once('tools/build.py','VERSION = "1.2.12"','VERSION = "1.2.13"')

# Active/current release tests: change only runtime identity assertions, not immutable evidence docs.
current_tests=[
 'qa/membership-state-contract.mjs','qa/institutional-lifecycle-contract.mjs','qa/authorization-boundary-contract.mjs',
 'qa/completion-hardening-contract.mjs','qa/ilhami-cycle-contract.mjs','qa/cf01-contract.mjs',
 'qa/forty-round-contract.mjs','qa/forty-round-1.2.10-contract.mjs','qa/dual-plan-runtime-completion-contract.mjs',
 'qa/three-plan-runtime-completion-contract.mjs'
]
for p in current_tests: replace_all(p,'1.2.12','1.2.13')
# latest-central retains its 1.2.12 evidence document but runtime check advances.
p='qa/latest-central-contract.mjs'; s=read(p)
s=s.replace("['runtime 1.2.12', plugin.includes(\"define( 'SMC_VERSION', '1.2.12' );\")]","['runtime 1.2.13', plugin.includes(\"define( 'SMC_VERSION', '1.2.13' );\")]")
write(p,s)

# Master trace now recognizes the new current release while retaining historical latest-central evidence.
p='qa/master-plan-traceability-contract.mjs'; s=read(p)
s=s.replace("assert(plugin.includes('Version: 1.2.12') && plugin.includes(\"define( 'SMC_DB_VERSION', '1.3.0' )\") && plugin.includes(\"define( 'SMC_CONTRACT_VERSION', '1.2.0' )\"), 'Current runtime identity 1.2.12/1.3.0/1.2.0');",
"assert(plugin.includes('Version: 1.2.13') && plugin.includes(\"define( 'SMC_DB_VERSION', '1.3.0' )\") && plugin.includes(\"define( 'SMC_CONTRACT_VERSION', '1.2.0' )\") && plugin.includes(\"define( 'SMC_ADVANCED_TRUST_CONTRACT_VERSION', '1.0.0' )\"), 'Current runtime identity 1.2.13/1.3.0/1.2.0 + advanced trust 1.0.0');")
s=s.replace("assert(master.includes('Runtime implementation release: `1.2.12`'), 'Master index current runtime');","assert(master.includes('Runtime implementation release: `1.2.13`'), 'Master index current runtime');")
anchor="assert(current.staging_accepted === false && current.live_deployed === false && current.operational === false, 'Current latest-central external gates remain pending');\n"
extra="const advanced = JSON.parse(read('qa/advanced-trust-traceability.json'));\nassert(advanced.release === '1.2.13' && advanced.advanced_trust_contract === '1.0.0' && advanced.requirements?.length === 20, 'Advanced trust 1.2.13 machine trace identity');\nassert(advanced.requirements.every((r)=>r.coded===true && r.tested===true), 'All F00-EXT-001..020 mapped coded/tested');\nassert(advanced.status.staging_accepted === false && advanced.status.live_deployed === false && advanced.status.operational === false, 'Advanced trust external gates remain pending');\n"
if extra not in s: s=s.replace(anchor,anchor+extra)
write(p,s)

# Master index current identity/evidence.
p='docs/FILE-00-MASTER-PLAN-2026.md'; s=read(p)
s=s.replace('- Runtime implementation release: `1.2.12`','- Runtime implementation release: `1.2.13`')
if '- Advanced trust contract: `1.0.0`' not in s: s=s.replace('- File 26 membership projection contract: `1.0.0`','- File 26 membership projection contract: `1.0.0`\n- Advanced trust contract: `1.0.0`')
if '- `docs/FILE-00-ADVANCED-TRUST-EXTENSIONS-1.2.13.md`' not in s: s=s.replace('## Current evidence\n','## Current evidence\n\n- `docs/FILE-00-ADVANCED-TRUST-EXTENSIONS-1.2.13.md`\n- `docs/RELEASE-1.2.13-ADVANCED-TRUST.md`\n- `qa/advanced-trust-traceability.json`\n- `qa/advanced-trust-contract.mjs`\n- `qa/advanced-trust-runtime.php`\n')
write(p,s)

# Workflows remain read-only; advance release identity and explicitly execute/package advanced trust evidence.
p='.github/workflows/file00-three-plan-qa.yml'; s=read(p)
s=s.replace('File 00 1.2.12 Latest-Central QA','File 00 1.2.13 Advanced-Trust QA')
s=s.replace("grep -Fq 'Version: 1.2.12'", "grep -Fq 'Version: 1.2.13'")
s=s.replace('dist/00-sabri-membership-core-1.2.12.zip','dist/00-sabri-membership-core-1.2.13.zip')
s=s.replace('00-sabri-membership-core-1.2.12-${{ github.sha }}','00-sabri-membership-core-1.2.13-${{ github.sha }}')
marker='          php qa/latest-central-runtime.php\n'
if 'node qa/advanced-trust-contract.mjs' not in s: s=s.replace(marker,marker+'          node qa/advanced-trust-contract.mjs\n          php qa/advanced-trust-runtime.php\n')
artifact='            qa/latest-central-traceability.json\n'
if 'qa/advanced-trust-traceability.json' not in s: s=s.replace(artifact,artifact+'            docs/RELEASE-1.2.13-ADVANCED-TRUST.md\n            docs/FILE-00-ADVANCED-TRUST-EXTENSIONS-1.2.13.md\n            docs/FILE-00-ADVANCED-TRUST-TEN-ROUND-REVIEW-1.2.13.md\n            qa/advanced-trust-traceability.json\n')
write(p,s)
p='.github/workflows/cf01-contract.yml'; s=read(p).replace('1.2.12','1.2.13'); write(p,s)
print('advanced trust release patch applied')

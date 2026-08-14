#!/usr/bin/env python3
from pathlib import Path

# Runtime identity only; schema/public contract versions are intentionally unchanged.
p=Path('source/sabri-membership-core/sabri-membership-core.php'); s=p.read_text()
if s.count('1.2.43') < 2: raise SystemExit('File00 runtime 1.2.43 preimage missing')
p.write_text(s.replace('Version: 1.2.43','Version: 1.2.44',1).replace("define( 'SMC_VERSION', '1.2.43' );","define( 'SMC_VERSION', '1.2.44' );",1))

# WordPress readme: new current release while retaining 1.2.43 history.
p=Path('source/sabri-membership-core/README.txt'); s=p.read_text()
if 'Stable tag: 1.2.43' not in s or '\n= 1.2.43 =\n' not in s: raise SystemExit('WordPress readme 1.2.43 preimage missing')
s=s.replace('Stable tag: 1.2.43','Stable tag: 1.2.44',1)
entry="""= 1.2.44 =
* Aligns the active `smc.authentication-account` 1.1.0 provider directly to File 00's canonical account taxonomy instead of a duplicated provider-only vocabulary.
* Canonical values are `member`, `patient`, `student`, `doctor`, `teacher`, `researcher`, `pharmacy`, `clinic`, and `publisher`; obsolete provider-only `clinic_staff` and `institution_representative` aliases are rejected.
* Exact-pins the corrected File 02 1.2.4 source candidate for cross-repository compatibility proof. Runtime 1.2.44; DB schema remains 1.4.5; public membership contract remains 1.2.3; authentication-account contract remains 1.1.0. Repository success is not live resolution.

"""
s=s.replace('= 1.2.43 =\n',entry+'= 1.2.43 =\n',1)
p.write_text(s)

# Package identity.
p=Path('package.json'); s=p.read_text()
if '"version": "1.2.43"' not in s: raise SystemExit('package.json version preimage missing')
p.write_text(s.replace('"version": "1.2.43"','"version": "1.2.44"',1).replace('00-sabri-membership-core-1.2.43.zip','00-sabri-membership-core-1.2.44.zip'))
p=Path('package-lock.json'); s=p.read_text()
if '"version": "1.2.43"' not in s: raise SystemExit('package-lock version preimage missing')
p.write_text(s.replace('"version": "1.2.43"','"version": "1.2.44"'))

# Active QA and permanent workflows describe current runtime/package truth.
for base in (Path('qa'), Path('.github/workflows')):
    for p in base.rglob('*'):
        if p.is_file() and p.suffix in {'.mjs','.php','.yml','.yaml'}:
            s=p.read_text()
            if '1.2.43' in s: p.write_text(s.replace('1.2.43','1.2.44'))

# Cross-repository exact pin: corrected File 02 1.2.4 candidate.
p=Path('.github/workflows/file02-account-contract-current.yml'); s=p.read_text()
old_ref='0f011b1876e217b7ee46f92903e5315538c1025e'; new_ref='950f4bd3f63e08304e3afafa501196919223ab20'
if old_ref not in s: raise SystemExit('File02 old exact pin missing')
s=s.replace(old_ref,new_ref).replace("FILE02_VERSION: '1.2.1'","FILE02_VERSION: '1.2.4'").replace('Exact-head File 02 1.2.1 account-contract compatibility','Exact-head File 02 1.2.4 account-contract compatibility')
p.write_text(s)

p=Path('qa/file02-exact-consumer-compat.php'); s=p.read_text()
if 'Version: 1.2.1' not in s: raise SystemExit('File02 consumer QA identity preimage missing')
s=s.replace('Version: 1.2.1','Version: 1.2.4').replace('Exact merged File 02 1.2.1 compatibility boundary passed.','Exact File 02 1.2.4 compatibility boundary passed.')
s=s.replace("$bootstrap_path = rtrim( $file02, '/\\\\' ) . '/sabri-authentication.php';", "$bootstrap_path = rtrim( $file02, '/\\\\' ) . '/sabri-authentication.php';\n$registration_path = rtrim( $file02, '/\\\\' ) . '/includes/class-sa-registration.php';")
s=s.replace("if ( ! is_file( $consumer_path ) || ! is_file( $bootstrap_path ) )", "if ( ! is_file( $consumer_path ) || ! is_file( $bootstrap_path ) || ! is_file( $registration_path ) )")
s=s.replace("$bootstrap = file_get_contents( $bootstrap_path );", "$bootstrap = file_get_contents( $bootstrap_path );\n$registration = file_get_contents( $registration_path );")
anchor="cross_assert( false !== strpos( $main, \"define( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION', '1.1.0' );\" ), 'File 00 provider version constant missing' );"
addition=anchor+"\nforeach ( array( 'member', 'patient', 'student', 'doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher' ) as $type ) { cross_assert( false !== strpos( $registration, \"'{$type}'\" ), \"File 02 canonical account choice missing: {$type}\" ); }\nforeach ( array( 'clinic_staff', 'institution_representative' ) as $legacy ) { cross_assert( false === strpos( $registration, \"'{$legacy}'\" ), \"File 02 provider-only alias remains: {$legacy}\" ); }\ncross_assert( false !== strpos( $v11, 'array_keys( smc_account_types() )' ), 'File 00 provider is not bound to canonical taxonomy' );"
if anchor not in s: raise SystemExit('File02 exact compatibility assertion anchor missing')
p.write_text(s.replace(anchor,addition,1))

# Master implementation index: advance current identity and add new current correction section; preserve 1.2.43 incident provenance.
p=Path('docs/FILE-00-MASTER-PLAN-2026.md'); s=p.read_text()
if '- Runtime implementation release: `1.2.43`' not in s: raise SystemExit('master current runtime preimage missing')
s=s.replace('- Runtime implementation release: `1.2.43`','- Runtime implementation release: `1.2.44`',1)
current_anchor='## Current evidence\n'
section="""## Canonical account-taxonomy provider parity correction — 1.2.44

Fresh repository review of File 00's exact canonical taxonomy and active `smc.authentication-account` 1.1.0 provider proved a duplicated-vocabulary drift: File 00 canonically defines `member`, `patient`, `student`, `doctor`, `teacher`, `researcher`, `pharmacy`, `clinic`, and `publisher`, while the provider had separately hard-coded a smaller set plus `clinic_staff` and `institution_representative`. Release `1.2.44` removes that duplication and makes the public provider validate directly against `array_keys( smc_account_types() )`; no lossy alias remap is introduced.

The DB schema remains `1.4.5`, the public membership contract remains `1.2.3`, and `smc.authentication-account` remains contract `1.1.0` because the correction restores provider conformance to File 00's existing canonical taxonomy rather than changing the contract envelope. The current cross-repository gate exact-pins File 02 `1.2.4` at `950f4bd3f63e08304e3afafa501196919223ab20` and proves both provider shape and canonical account-choice parity. Repository/CI success does not establish deployment; staging/live require separate exact deployed-build, DB/migration and workflow evidence.

"""
if current_anchor not in s: raise SystemExit('master current evidence anchor missing')
s=s.replace(current_anchor,section+current_anchor,1)
s=s.replace('- `RELEASE-1.2.43.md`','- `RELEASE-1.2.44.md`\n- `RELEASE-1.2.43.md`',1)
p.write_text(s)

# Root README current truth.
Path('README.md').write_text("""# File 00 — Sabri Membership Core

Current repository corrective candidate: runtime `1.2.44`, public membership contract `1.2.3`, database schema `1.4.5`, `smc.authentication-account` `1.1.0`.

The 1.2.44 candidate binds the active authentication-account provider directly to File 00's canonical account taxonomy and exact-pins the corrected File 02 1.2.4 source candidate for cross-repository compatibility testing. It preserves Founder-approved File 00 MFA retirement and introduces no new DB schema or public membership-contract shape.

```bash
npm ci --ignore-scripts
npm run verify
```

Repository tests/package/CI are source evidence only. Staging, deployed and operational states require separate exact evidence; GitHub success must not be treated as live resolution.
""")

# New immutable release evidence; do not rewrite RELEASE-1.2.43.md.
Path('RELEASE-1.2.44.md').write_text("""# File 00 — Sabri Membership Core 1.2.44

## Purpose

Correct the proven File 00 canonical-account-taxonomy / `smc.authentication-account` provider-vocabulary drift without a lossy File 02 remap.

## Exact source lineage

- Pre-release-correction root-cause head: `249b5dc40f1936cc805709bed146e602ec67732a`.
- Frozen review evidence: `REVIEW-1.2.44-TAXONOMY-FROZEN.md`.
- Paired File 02 candidate: `1.2.4` at exact SHA `950f4bd3f63e08304e3afafa501196919223ab20`.

## Corrected contract behavior

- `SMC_Authentication_Contract_V11::validate_extra_fields()` derives its allowlist from `array_keys( smc_account_types() )`.
- Accepted canonical account values: `member`, `patient`, `student`, `doctor`, `teacher`, `researcher`, `pharmacy`, `clinic`, `publisher`.
- Provider-only aliases `clinic_staff` and `institution_representative` are rejected; File 02 performs no lossy remap.

## Identity

- Runtime: `1.2.44`.
- DB schema: `1.4.5` unchanged.
- Public membership contract: `1.2.3` unchanged.
- `smc.authentication-account`: `1.1.0` unchanged.

## Completion boundary

This release record is repository/source evidence. It does not claim staging acceptance, live deployment, DB migration completion or operational resolution. Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔
""")

# Permanent release regression.
Path('qa/file00-1244-taxonomy-release.mjs').write_text("""import fs from 'node:fs'; import assert from 'node:assert/strict';
const main=fs.readFileSync('source/sabri-membership-core/sabri-membership-core.php','utf8');
const provider=fs.readFileSync('source/sabri-membership-core/includes/class-smc-authentication-contract-v11.php','utf8');
const functions=fs.readFileSync('source/sabri-membership-core/includes/functions.php','utf8');
const readme=fs.readFileSync('source/sabri-membership-core/README.txt','utf8');
const wf=fs.readFileSync('.github/workflows/file02-account-contract-current.yml','utf8');
assert(main.includes('Version: 1.2.44') && main.includes("define( 'SMC_VERSION', '1.2.44' )"));
assert(main.includes("define( 'SMC_DB_VERSION', '1.4.5' )") && main.includes("define( 'SMC_CONTRACT_VERSION', '1.2.3' )") && main.includes("define( 'SMC_AUTHENTICATION_CONTRACT_V11_VERSION', '1.1.0' )"));
assert(provider.includes('$allowed_types   = array_keys( smc_account_types() );'));
for (const t of ['member','patient','student','doctor','teacher','researcher','pharmacy','clinic','publisher']) assert(functions.includes(`'${t}'`),`canonical type missing: ${t}`);
assert(!provider.includes("'clinic_staff'") && !provider.includes("'institution_representative'"));
assert(readme.includes('Stable tag: 1.2.44') && readme.includes('= 1.2.44 =') && readme.includes('= 1.2.43 ='));
assert(wf.includes('950f4bd3f63e08304e3afafa501196919223ab20') && wf.includes("FILE02_VERSION: '1.2.4'"));
assert(fs.existsSync('RELEASE-1.2.44.md') && fs.existsSync('RELEASE-1.2.43.md'));
assert(!fs.existsSync('.github/workflows/tmp-account-taxonomy-parity-apply.yml'));
assert(!fs.existsSync('tools/apply-file00-1244.py'));
console.log('File 00 1.2.44 taxonomy/release regression PASS.');
""")

# Temporary correction machinery may not survive corrected candidate.
Path('.github/workflows/tmp-account-taxonomy-parity-apply.yml').unlink()
Path('tools/apply-file00-1244.py').unlink()

#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
path=root/'source/sabri-membership-core/README.txt'
text=path.read_text(encoding='utf-8')
if text.count('Stable tag: 1.2.18') != 1:
    raise SystemExit(f'sourcefix9: stable tag expected once, found {text.count("Stable tag: 1.2.18")}')
text=text.replace('Stable tag: 1.2.18','Stable tag: 1.2.19',1)
marker='== Changelog ==\n\n'
if text.count(marker)!=1:
    raise SystemExit('sourcefix9: changelog marker missing or duplicated')
entry='''= 1.2.19 =
* Corrective candidate for the 8 August 2026 GitHub code-only audit: fixes dual professional approval generation/independent handoff, appeal restoration provenance, reviewer assignment/document scoping, guardian immutable succession, rejected-reapplication bypass and jurisdiction-effective age enforcement.
* Adds versioned encryption-key generations for new SMC3 envelopes, global factor-level TOTP replay protection, safe 2FA replacement with current password/factor re-authentication, serialized tamper-evident audit tail, fail-closed post-commit role/session reconciliation, and stronger Safe Mode worker blocking.
* Strengthens privacy export/erasure, ancillary role/capability and break-glass cleanup, authenticated draft expiry, recovery-code receipt acknowledgement, orphan lease cleanup, outbox delivery acknowledgement, trust-transition repair and structured isolated-restore proof.
* Runtime 1.2.19; DB schema 1.4.0; public membership contract 1.2.1. This is a code/automated-QA candidate only: real WordPress/MySQL concurrency, providers, browser/accessibility, isolated restore/rollback, security review and Hostinger staging acceptance remain mandatory before production.

'''
text=text.replace(marker,marker+entry,1)
path.write_text(text,encoding='utf-8',newline='\n')
print('README stable tag/changelog synchronized to truthful 1.2.19 corrective candidate')

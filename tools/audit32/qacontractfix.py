#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'qa/dual-plan-runtime-completion-contract.mjs'
text = path.read_text(encoding='utf-8')
old = '''assert(completion.includes("SMC_Security::encrypt( wp_json_encode( $data ), 'application-draft'") && completion.includes("SMC_Security::decrypt( $receipt['envelope'], 'application-draft'"), 'Draft encryption round trip');'''
new = '''assert(completion.includes("$sealed = wp_json_encode( array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at, 'draft'=>$data ) )") && completion.includes("SMC_Security::encrypt( $sealed, 'application-draft', $context )") && completion.includes("SMC_Security::decrypt( $receipt['envelope'], 'application-draft', $context )"), 'Draft encryption round trip authenticates issued-at and expiry context');'''
if text.count(old) != 1:
    raise SystemExit(f'qacontractfix: draft assertion expected once, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('dual-plan draft-encryption QA aligned with authenticated-expiry envelope')

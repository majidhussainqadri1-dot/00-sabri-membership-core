#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]

# Draft encryption contract: the corrective design authenticates both issued_at and expires_at.
path = root / 'qa/dual-plan-runtime-completion-contract.mjs'
text = path.read_text(encoding='utf-8')
old = '''assert(completion.includes("SMC_Security::encrypt( wp_json_encode( $data ), 'application-draft'") && completion.includes("SMC_Security::decrypt( $receipt['envelope'], 'application-draft'"), 'Draft encryption round trip');'''
new = '''assert(completion.includes("$sealed = wp_json_encode( array( 'issued_at'=>$issued_at, 'expires_at'=>$expires_at, 'draft'=>$data ) )") && completion.includes("SMC_Security::encrypt( $sealed, 'application-draft', $context )") && completion.includes("SMC_Security::decrypt( $receipt['envelope'], 'application-draft', $context )"), 'Draft encryption round trip authenticates issued-at and expiry context');'''
if text.count(old) != 1:
    raise SystemExit(f'qacontractfix: draft assertion expected once, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('dual-plan draft-encryption QA aligned with authenticated-expiry envelope')

# Retained forty-round contract: runtime/package identity follows the corrective candidate,
# and restore acceptance is stronger than the historical free-text-length check.
path = root / 'qa/forty-round-1.2.10-contract.mjs'
text = path.read_text(encoding='utf-8')
old = "  ['package version', packageJson.version === '1.2.18'],"
new = "  ['package version', packageJson.version === '1.2.19'],"
if text.count(old) != 1:
    raise SystemExit(f'qacontractfix: package-version assertion expected once, found {text.count(old)}')
text = text.replace(old, new, 1)
old = "  ['restore evidence server validation', completion.includes(\"strlen( $reference ) < 8\")],"
new = "  ['restore evidence server validation', completion.includes(\"$required = array( 'restore_run_id','manifest_verified','isolated_restore','component_digests_match','row_counts_match','private_files_match','decrypt_samples_pass','key_recovery_pass','audit_chain_pass','retention_holds_reconciled','migrations_reconciled' )\") && completion.includes(\"strlen( $reference ) >= 8\") && completion.includes(\"smc_restore_proof_v1\")],"
if text.count(old) != 1:
    raise SystemExit(f'qacontractfix: restore assertion expected once, found {text.count(old)}')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8', newline='\n')
print('retained forty-round QA aligned with 1.2.19 package identity and signed restore-proof contract')

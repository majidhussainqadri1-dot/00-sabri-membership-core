#!/usr/bin/env python3
import json
from pathlib import Path

HEAD = '953a27e5184450e2ec809b1b84c1f4d48fef8bc1'
FINAL_RUN = 30853003774
CONTRACT_RUN = 30853003787
ARTIFACT_ID = 8871339869
PACKAGE_SHA = '544395db2bb4d798dd9bcc44c14ae61b56d844568086859ecb11679413905adc'


def replace(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'missing expected text in {path}: {old!r}')
    target.write_text(text.replace(old, new), encoding='utf-8')


registry_path = Path('qa/requirements-traceability.json')
data = json.loads(registry_path.read_text(encoding='utf-8'))
data['document_version'] = '2.8-forty-round-verified'
data['runtime_evidence'] = {
    'head_sha': HEAD,
    'workflow_run_id': FINAL_RUN,
    'contract_workflow_run_id': CONTRACT_RUN,
    'artifact_id': ARTIFACT_ID,
    'conclusion': 'success',
    'merge_sha': '',
    'package': 'dist/00-sabri-membership-core-1.2.8.zip',
    'package_sha256': PACKAGE_SHA,
}
data['staging_installation_candidate'] = True
review = data.setdefault('forty_round_review', {})
review.update({
    'rounds': 40,
    'status': 'verified',
    'ledger': 'docs/FORTY-ROUND-REVIEW-1.2.8.md',
    'contract': 'qa/forty-round-contract.mjs',
    'exact_head_sha': HEAD,
    'workflow_run_ids': [FINAL_RUN, CONTRACT_RUN],
    'package_sha256': PACKAGE_SHA,
    'merge_sha': '',
})
for group in data.get('groups', []):
    group['evidence'] = [p.replace('1.2.7', '1.2.8') for p in group.get('evidence', [])]
registry_path.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')

replace('README.md', '## Current forty-review candidate', '## Current verified forty-review release')
replace('README.md', '- Forty consecutive review-and-correction cycles: **completed in source; exact-head CI pending**', f'- Forty consecutive review-and-correction cycles: **40/40 completed and exact-head verified**\n- Verified source head: `{HEAD}`\n- Final Dual-Plan QA run: `{FINAL_RUN}` — **success**\n- PHP 7.4/8.3 and CF-01 integrity run: `{CONTRACT_RUN}` — **success**')
replace('README.md', '- Deterministic package target: `dist/00-sabri-membership-core-1.2.8.zip`', f'- Deterministic package: `dist/00-sabri-membership-core-1.2.8.zip`\n- Package SHA-256: `{PACKAGE_SHA}`\n- Workflow artifact: `{ARTIFACT_ID}`\n- Staging installation candidate: **Yes**')

replace('STATUS.md', '**File 00 1.2.8 forty-review correction candidate — forty source review/fix rounds completed; exact-head automated QA and final merge evidence pending.**', '**File 00 1.2.8 forty-review repository release — forty review/fix rounds and exact-head automated QA completed; final PR merge pending.**')
replace('STATUS.md', '## Completed in source', '## Completed and verified')
replace('STATUS.md', '- Known unresolved defects in the reviewed local/source scope: **0 before CI**\n- Exact-head GitHub Actions: **Pending**\n- Deterministic package checksum: **Pending**\n- Merge to main: **Pending**', f'- Known unresolved repository defects: **0**\n- Exact-head GitHub Actions: **Passed**\n- Verified source head: `{HEAD}`\n- Final Dual-Plan QA: `{FINAL_RUN}` — **success**\n- CF-01 Contract Integrity: `{CONTRACT_RUN}` — **success**\n- Deterministic package SHA-256: `{PACKAGE_SHA}`\n- Workflow artifact: `{ARTIFACT_ID}`\n- Staging installation candidate: **Yes**\n- Merge to main: **Pending final PR acceptance**')

replace('docs/FILE-00-MASTER-PLAN-2026.md', '- Exact-head CI/package/merge evidence: pending until verification completes.', f'- Verified source head: `{HEAD}`\n- Final Dual-Plan QA: `{FINAL_RUN}` — success\n- PHP 7.4/8.3 and CF-01 Contract Integrity: `{CONTRACT_RUN}` — success\n- Deterministic package SHA-256: `{PACKAGE_SHA}`\n- Final main merge: pending PR acceptance.')
replace('docs/FILE-00-MASTER-PLAN-2026.md', 'Forty source review/fix rounds are complete. Exact-head automated QA must pass before repository completion is merged.', 'Forty review/fix rounds and repository-correctable exact-head automated QA are complete. Final merge remains subject to PR acceptance.')

replace('docs/FINAL-PLAN-COMPLETION-1.2.8.md', '- Exact-head CI, package SHA and merge SHA: pending until GitHub verification completes.', f'- Verified source head: `{HEAD}`\n- Final Dual-Plan QA run: `{FINAL_RUN}` — success\n- CF-01 Contract Integrity run: `{CONTRACT_RUN}` — success under PHP 7.4 and PHP 8.3\n- Deterministic package SHA-256: `{PACKAGE_SHA}`\n- Workflow artifact: `{ARTIFACT_ID}`\n- Final merge SHA: pending PR acceptance.')
replace('docs/FINAL-PLAN-COMPLETION-1.2.8.md', 'Source corrections and the forty review records are complete. Automated-QA Green is not claimed until exact-head workflows pass.', 'Repository-correctable source, forty review records, automated QA, deterministic packaging and dual-plan traceability are exact-head verified with zero known unresolved repository defects.')

replace('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.8.md', '> Forty-round source candidate. Exact-head CI, package checksum and merge evidence are pending and must not be inferred from 1.2.7.', '> Forty consecutive review-and-correction cycles and their repository-correctable source are exact-head verified. Final merge evidence remains pending until PR acceptance.')
replace('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.8.md', '- Verified head `Pending exact-head CI`; GitHub Actions: **Pending exact-head CI**\n- Main merge `Pending`\n- Package SHA-256 `Pending`', f'- Verified source head `{HEAD}`; GitHub Actions: `{FINAL_RUN}` and `{CONTRACT_RUN}` — **success**\n- Main merge `Pending final PR acceptance`\n- Package SHA-256 `{PACKAGE_SHA}`')

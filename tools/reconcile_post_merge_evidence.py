#!/usr/bin/env python3
import json
from pathlib import Path

FINAL_HEAD = 'e8ff52477f61a6cf446390afb337201338dabab2'
FINAL_RUN = 30853368958
CONTRACT_RUN = 30853369022
FINAL_ARTIFACT = 8871478716
RUNTIME_MERGE = 'ca6d73e76b904512863617cd441eb85150a03b4a'
PACKAGE_SHA = '544395db2bb4d798dd9bcc44c14ae61b56d844568086859ecb11679413905adc'


def replace(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'missing expected text in {path}: {old!r}')
    target.write_text(text.replace(old, new), encoding='utf-8')


registry = Path('qa/requirements-traceability.json')
data = json.loads(registry.read_text(encoding='utf-8'))
data['document_version'] = '2.8-forty-round-merged'
data['runtime_evidence'].update({
    'head_sha': FINAL_HEAD,
    'workflow_run_id': FINAL_RUN,
    'contract_workflow_run_id': CONTRACT_RUN,
    'artifact_id': FINAL_ARTIFACT,
    'conclusion': 'success',
    'merge_sha': RUNTIME_MERGE,
    'package_sha256': PACKAGE_SHA,
})
data['forty_round_review'].update({
    'status': 'verified_and_merged',
    'exact_head_sha': FINAL_HEAD,
    'workflow_run_ids': [FINAL_RUN, CONTRACT_RUN],
    'package_sha256': PACKAGE_SHA,
    'merge_sha': RUNTIME_MERGE,
})
registry.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')

replace('README.md', '- Verified source head: `953a27e5184450e2ec809b1b84c1f4d48fef8bc1`\n- Final Dual-Plan QA run: `30853003774` — **success**\n- PHP 7.4/8.3 and CF-01 integrity run: `30853003787` — **success**', f'- Final verified PR head: `{FINAL_HEAD}`\n- Final Dual-Plan QA run: `{FINAL_RUN}` — **success**\n- PHP 7.4/8.3 and CF-01 integrity run: `{CONTRACT_RUN}` — **success**')
replace('README.md', '- Workflow artifact: `8871339869`', f'- Workflow artifact: `{FINAL_ARTIFACT}`\n- Runtime merge to `main`: `{RUNTIME_MERGE}`')

replace('STATUS.md', '**File 00 1.2.8 forty-review repository release — forty review/fix rounds and exact-head automated QA completed; final PR merge pending.**', '**File 00 1.2.8 forty-review repository release — forty review/fix rounds, exact-head automated QA and runtime merge to main completed.**')
replace('STATUS.md', '- Verified source head: `953a27e5184450e2ec809b1b84c1f4d48fef8bc1`\n- Final Dual-Plan QA: `30853003774` — **success**\n- CF-01 Contract Integrity: `30853003787` — **success**', f'- Final verified PR head: `{FINAL_HEAD}`\n- Final Dual-Plan QA: `{FINAL_RUN}` — **success**\n- CF-01 Contract Integrity: `{CONTRACT_RUN}` — **success**')
replace('STATUS.md', '- Workflow artifact: `8871339869`\n- Staging installation candidate: **Yes**\n- Merge to main: **Pending final PR acceptance**', f'- Workflow artifact: `{FINAL_ARTIFACT}`\n- Runtime merge to main: `{RUNTIME_MERGE}`\n- Staging installation candidate: **Yes**\n- Merge to main: **Completed**')

replace('docs/FILE-00-MASTER-PLAN-2026.md', '- Verified source head: `953a27e5184450e2ec809b1b84c1f4d48fef8bc1`\n- Final Dual-Plan QA: `30853003774` — success\n- PHP 7.4/8.3 and CF-01 Contract Integrity: `30853003787` — success\n- Deterministic package SHA-256: `544395db2bb4d798dd9bcc44c14ae61b56d844568086859ecb11679413905adc`\n- Final main merge: pending PR acceptance.', f'- Final verified PR head: `{FINAL_HEAD}`\n- Final Dual-Plan QA: `{FINAL_RUN}` — success\n- PHP 7.4/8.3 and CF-01 Contract Integrity: `{CONTRACT_RUN}` — success\n- Deterministic package SHA-256: `{PACKAGE_SHA}`\n- Runtime merge to main: `{RUNTIME_MERGE}`.')
replace('docs/FILE-00-MASTER-PLAN-2026.md', 'Forty review/fix rounds and repository-correctable exact-head automated QA are complete. Final merge remains subject to PR acceptance.', 'Forty review/fix rounds, repository-correctable exact-head automated QA and the runtime merge to main are complete.')

replace('docs/FINAL-PLAN-COMPLETION-1.2.8.md', '- Verified source head: `953a27e5184450e2ec809b1b84c1f4d48fef8bc1`\n- Final Dual-Plan QA run: `30853003774` — success\n- CF-01 Contract Integrity run: `30853003787` — success under PHP 7.4 and PHP 8.3\n- Deterministic package SHA-256: `544395db2bb4d798dd9bcc44c14ae61b56d844568086859ecb11679413905adc`\n- Workflow artifact: `8871339869`\n- Final merge SHA: pending PR acceptance.', f'- Final verified PR head: `{FINAL_HEAD}`\n- Final Dual-Plan QA run: `{FINAL_RUN}` — success\n- CF-01 Contract Integrity run: `{CONTRACT_RUN}` — success under PHP 7.4 and PHP 8.3\n- Deterministic package SHA-256: `{PACKAGE_SHA}`\n- Workflow artifact: `{FINAL_ARTIFACT}`\n- Runtime merge SHA: `{RUNTIME_MERGE}`.')

replace('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.8.md', '> Forty consecutive review-and-correction cycles and their repository-correctable source are exact-head verified. Final merge evidence remains pending until PR acceptance.', '> Forty consecutive review-and-correction cycles, repository-correctable source and runtime merge to main are verified.')
replace('docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.8.md', '- Verified source head `953a27e5184450e2ec809b1b84c1f4d48fef8bc1`; GitHub Actions: `30853003774` and `30853003787` — **success**\n- Main merge `Pending final PR acceptance`', f'- Final verified PR head `{FINAL_HEAD}`; GitHub Actions: `{FINAL_RUN}` and `{CONTRACT_RUN}` — **success**\n- Runtime main merge `{RUNTIME_MERGE}`')

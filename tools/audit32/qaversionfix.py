#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2] / 'qa'
replacements = {
    "Version: 1.2.18": "Version: 1.2.19",
    "define( 'SMC_VERSION', '1.2.18' )": "define( 'SMC_VERSION', '1.2.19' )",
    "define( 'SMC_DB_VERSION', '1.3.0' )": "define( 'SMC_DB_VERSION', '1.4.0' )",
    "define( 'SMC_CONTRACT_VERSION', '1.2.0' )": "define( 'SMC_CONTRACT_VERSION', '1.2.1' )",
    "Plugin header is 1.2.18": "Plugin header is 1.2.19",
    "Runtime version is 1.2.18": "Runtime version is 1.2.19",
    "Database version is 1.3.0": "Database version is 1.4.0",
    "Contract version is 1.2.0": "Contract version is 1.2.1",
}
changed = []
for path in sorted(root.rglob('*')):
    if not path.is_file() or path.suffix not in {'.mjs', '.js', '.php'}:
        continue
    text = path.read_text(encoding='utf-8')
    updated = text
    for old, new in replacements.items():
        updated = updated.replace(old, new)
    if updated != text:
        path.write_text(updated, encoding='utf-8', newline='\n')
        changed.append(path.relative_to(root.parent).as_posix())
print(f'aligned active QA version contracts in {len(changed)} files')
for item in changed:
    print(item)

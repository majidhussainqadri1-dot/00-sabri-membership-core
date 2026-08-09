#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('apply.py')
text = path.read_text(encoding='utf-8')
old = "out, n = re.subn(pattern, repl, text, count=1, flags=flags)"
new = "out, n = re.subn(pattern, lambda _match: repl, text, count=1, flags=flags)"
if text.count(old) != 1:
    raise SystemExit(f'regexfix: expected one helper match, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('audit32 regex replacements made literal-safe')

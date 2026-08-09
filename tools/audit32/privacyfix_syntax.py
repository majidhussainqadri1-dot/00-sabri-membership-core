#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('privacyfix.py')
text = path.read_text(encoding='utf-8')
old_open = "section = r'''# ---------------------------------------------------------------------------"
new_open = 'section = r\"\"\"# ---------------------------------------------------------------------------'
old_close = "\n'''\ntext = text[:start] + section + text[end:]"
new_close = '\n\"\"\"\ntext = text[:start] + section + text[end:]'
if text.count(old_open) != 1 or text.count(old_close) != 1:
    raise SystemExit(f'privacyfix-syntax: open={text.count(old_open)} close={text.count(old_close)}')
path.write_text(text.replace(old_open,new_open,1).replace(old_close,new_close,1),encoding='utf-8',newline='\n')
print('privacyfix nested quoting repaired')

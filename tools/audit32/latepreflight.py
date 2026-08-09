#!/usr/bin/env python3
from pathlib import Path
path=Path(__file__).with_name('apply.py')
text=path.read_text(encoding='utf-8')
start_marker="# ---------------------------------------------------------------------------\n# Private file orphan job removes its authenticated companion lease."
end_marker="# ---------------------------------------------------------------------------\n# STATUS truthfulness: remove defect-zero assertion; point to corrective branch."
start=text.find(start_marker); end=text.find(end_marker,start)
if start<0 or end<0: raise SystemExit('latepreflight: orphan section markers missing')
replacement="# ---------------------------------------------------------------------------\n# Orphan doc+lease cleanup is applied by sourcefix.py against the exact worker.\n# ---------------------------------------------------------------------------\n\n"
path.write_text(text[:start]+replacement+text[end:],encoding='utf-8',newline='\n')
print('audit32 brittle orphan patch deferred to exact source fix')

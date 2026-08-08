#!/usr/bin/env python3
from pathlib import Path

p=Path('tools/file00_sixth_review_final.py')
s=p.read_text(encoding='utf-8')

# Reviews 5 and 6 modify workflow files. They are persisted separately via owner-level GitHub API.
start=s.find('# ROUND 5\n')
end=s.find('# ROUND 7\n',start)
if start<0 or end<0:
    raise SystemExit('Round 5/6 removal markers missing')
s=s[:start] + "# ROUND 5 and ROUND 6 workflow corrections are owner-API persisted after this push.\n\n" + s[end:]

# Release-workflow edits are also owner-API persisted after the non-workflow reviewed tree lands.
start=s.find("mainwf=ROOT/'.github/workflows/file00-three-plan-qa.yml'\n")
end=s.find("newdoc=ROOT/'docs/HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md'",start)
if start<0 or end<0:
    raise SystemExit('Round 10 workflow release-edit markers missing')
s=s[:start] + "# Permanent workflow release edits are applied through the GitHub owner connector after this push.\n" + s[end:]

# round10_patch has moved cleanup before full QA. Keep that ordering, but do not delete workflow files
# in an Actions-token push. Delete every temporary Python helper instead.
cleanup_start=s.find('# Final review tree must be clean before the full release suite:')
cleanup_end=s.find("run('npm','ci','--ignore-scripts')",cleanup_start)
if cleanup_start<0 or cleanup_end<0:
    raise SystemExit('round10 pre-QA cleanup markers missing')
cleanup="""# Remove all temporary Python review tooling before full QA. Workflow cleanup is owner-API persisted.
import shutil
for temp in [
    ROOT/'tools/file00_sixth_review_apply.py',
    ROOT/'tools/file00_sixth_review_prep.py',
    ROOT/'tools/file00_sixth_review_postprep.py',
    ROOT/'tools/file00_sixth_review_round10_patch.py',
    ROOT/'tools/file00_sixth_review_version_patch.py',
    ROOT/'tools/file00_sixth_review_pushable_patch.py',
    ROOT/'tools/file00_sixth_review_final.py',
]:
    if temp.exists(): temp.unlink()
shutil.rmtree(ROOT/'tools/__pycache__', ignore_errors=True)

"""
s=s[:cleanup_start]+cleanup+s[cleanup_end:]

compile(s,'tools/file00_sixth_review_final.py','exec')
p.write_text(s,encoding='utf-8')
print('Non-workflow sixth-review applicator prepared; compile gate passed.')

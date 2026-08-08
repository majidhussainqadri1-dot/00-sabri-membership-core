#!/usr/bin/env python3
from pathlib import Path

p=Path('tools/file00_sixth_review_final.py')
s=p.read_text(encoding='utf-8')

# Workflow-governance Reviews 5 and 6 are persisted separately through the GitHub owner connector,
# because GITHUB_TOKEN is intentionally not permitted to write workflow files.
start=s.find('# ROUND 5\n')
end=s.find('# ROUND 7\n',start)
if start<0 or end<0:
    raise SystemExit('Round 5/6 removal markers missing')
s=s[:start] + "# ROUND 5 and ROUND 6 workflow corrections are persisted by owner-level GitHub API after this push.\n\n" + s[end:]

# Likewise, release-workflow edits are connector-persisted after the non-workflow reviewed tree lands.
start=s.find("mainwf=ROOT/'.github/workflows/file00-three-plan-qa.yml'\n")
end=s.find("newdoc=ROOT/'docs/HOSTINGER-STAGING-ACCEPTANCE-1.2.19.md'",start)
if start<0 or end<0:
    raise SystemExit('Round 10 workflow release-edit markers missing')
s=s[:start] + "# Permanent workflow release edits are applied through the GitHub owner connector after this push.\n" + s[end:]

# Do not delete workflow files in the Actions-token push. Delete all temporary Python applicators instead.
old="""for p in [
    ROOT/'.github/workflows/file00-sixth-review-one-shot.yml',
    ROOT/'tools/file00_sixth_review_apply.py',
    ROOT/'tools/file00_sixth_review_prep.py',
    ROOT/'tools/file00_sixth_review_postprep.py',
    ROOT/'tools/file00_sixth_review_round10_patch.py',
    ROOT/'tools/file00_sixth_review_version_patch.py',
    ROOT/'tools/file00_sixth_review_final.py',
]:
"""
new="""for p in [
    ROOT/'tools/file00_sixth_review_apply.py',
    ROOT/'tools/file00_sixth_review_prep.py',
    ROOT/'tools/file00_sixth_review_postprep.py',
    ROOT/'tools/file00_sixth_review_round10_patch.py',
    ROOT/'tools/file00_sixth_review_version_patch.py',
    ROOT/'tools/file00_sixth_review_pushable_patch.py',
    ROOT/'tools/file00_sixth_review_final.py',
]:
"""
if old not in s:
    raise SystemExit('temporary cleanup block missing')
s=s.replace(old,new,1)

# No compiled Python helper may enter any review commit.
s=s.replace("run('php','-l',str(ADV))\nrun('php',str(ROOT/'qa/sixth-fresh-review-runtime.php'))\n", "import shutil\nshutil.rmtree(ROOT/'tools/__pycache__', ignore_errors=True)\nrun('php','-l',str(ADV))\nrun('php',str(ROOT/'qa/sixth-fresh-review-runtime.php'))\n",1)

compile(s,'tools/file00_sixth_review_final.py','exec')
p.write_text(s,encoding='utf-8')
print('Non-workflow sixth-review applicator prepared; compile gate passed.')

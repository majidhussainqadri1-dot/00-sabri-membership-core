#!/usr/bin/env python3
from pathlib import Path

p=Path('tools/file00_sixth_review_final.py')
s=p.read_text(encoding='utf-8')

old="""open_needle="\\t\\t$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );\\n\\t\\t$all[ $id ] = array("
open_repl="\\t\\t$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );\\n\\t\\tif ( count( $all ) >= 200 ) { self::release_break_glass_lock( $lock ); return new WP_Error( 'smc_break_glass_capacity', 'Emergency governance request capacity is temporarily exhausted.' ); }\\n\\t\\t$all[ $id ] = array("
if open_needle not in txt: raise SystemExit('break-glass creation capacity insertion target missing')
txt=txt.replace(open_needle,open_repl,1)
"""
new="""open_needle="\\t\\t$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );\\n\\t\\t$all[ $request['id'] ] = $request;"
open_repl="\\t\\t$all = self::prune_break_glass_requests( (array) get_option( self::BREAK_GLASS_OPTION, array() ) );\\n\\t\\tif ( count( $all ) >= 200 ) { self::release_break_glass_lock( $lock ); return new WP_Error( 'smc_break_glass_capacity', __( 'Emergency governance request capacity is temporarily exhausted.', 'sabri-membership-core' ) ); }\\n\\t\\t$all[ $request['id'] ] = $request;"
if open_needle not in txt: raise SystemExit('break-glass creation capacity insertion target missing')
txt=txt.replace(open_needle,open_repl,1)
"""
if old not in s:
    raise SystemExit('Round10 capacity patch target not found in applicator')
s=s.replace(old,new,1)

old="""run('php','-l',str(ADV))
run('php',str(ROOT/'qa/sixth-fresh-review-runtime.php'))
run('node',str(ROOT/'qa/hostinger-staging-acceptance-contract.mjs'))
run('npm','ci','--ignore-scripts')
run('python3','tools/build.py')
run('npm','test')
run('python3','qa/verify-package.py','dist/00-sabri-membership-core-1.2.19.zip')
commit('Review 10: bound emergency governance retention and close File 00 1.2.19 sixth review')

for p in [
    ROOT/'.github/workflows/file00-sixth-review-one-shot.yml',
    ROOT/'tools/file00_sixth_review_apply.py',
    ROOT/'tools/file00_sixth_review_prep.py',
    ROOT/'tools/file00_sixth_review_postprep.py',
    ROOT/'tools/file00_sixth_review_final.py',
]:
    if p.exists(): p.unlink()
commit('QA closure: remove all temporary sixth-review tooling before exact-head CI')
"""
new="""run('php','-l',str(ADV))
run('php',str(ROOT/'qa/sixth-fresh-review-runtime.php'))
run('node',str(ROOT/'qa/hostinger-staging-acceptance-contract.mjs'))

# Final review tree must be clean before the full release suite: remove every write-capable helper,
# its compiled bytecode, and the temporary workflow while this running process still has its code loaded.
import shutil
for temp in [
    ROOT/'.github/workflows/file00-sixth-review-one-shot.yml',
    ROOT/'tools/file00_sixth_review_apply.py',
    ROOT/'tools/file00_sixth_review_prep.py',
    ROOT/'tools/file00_sixth_review_postprep.py',
    ROOT/'tools/file00_sixth_review_round10_patch.py',
    ROOT/'tools/file00_sixth_review_final.py',
]:
    if temp.exists(): temp.unlink()
shutil.rmtree(ROOT/'tools/__pycache__', ignore_errors=True)

run('npm','ci','--ignore-scripts')
run('python3','tools/build.py')
run('npm','test')
run('python3','qa/verify-package.py','dist/00-sabri-membership-core-1.2.19.zip')
commit('Review 10: bound emergency governance retention, remove review tooling, and close File 00 1.2.19')
"""
if old not in s:
    raise SystemExit('Round10 cleanup-order patch target not found in applicator')
s=s.replace(old,new,1)

compile(s,'tools/file00_sixth_review_final.py','exec')
p.write_text(s,encoding='utf-8')
print('Round10 marker and final-clean-tree ordering patched; compile gate passed.')

#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-authorization.php'
s=source.read_text()
old="""\tprivate static function restricted_capabilities() {\n\t\t$caps = (array) apply_filters( 'smc_restricted_capabilities', self::$restricted_caps );\n\t\treturn array_values( array_unique( array_filter( array_map( 'sanitize_key', $caps ) ) ) );\n\t}\n\n\tpublic static function hard_block_statuses() {\n\t\treturn (array) apply_filters(\n\t\t\t'smc_hard_block_statuses',\n\t\t\tarray( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' )\n\t\t);\n\t}\n"""
new="""\tprivate static function restricted_capabilities() {\n\t\t$filtered = (array) apply_filters( 'smc_restricted_capabilities', self::$restricted_caps );\n\t\t$caps = array_merge( self::$restricted_caps, $filtered );\n\t\treturn array_values( array_unique( array_filter( array_map( 'sanitize_key', $caps ) ) ) );\n\t}\n\n\tpublic static function hard_block_statuses() {\n\t\t$baseline = array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' );\n\t\t$filtered = (array) apply_filters( 'smc_hard_block_statuses', $baseline );\n\t\treturn array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $baseline, $filtered ) ) ) ) );\n\t}\n"""
if old not in s: raise SystemExit('round3 authorization baseline block not found')
source.write_text(s.replace(old,new,1))

contract=root/'qa/second-fresh-review-contract.mjs'
contract.write_text(r'''import fs from 'node:fs';
let failed=0; const pass=(n,c)=>{console.log(`${c?'PASS':'FAIL'} ${n}`); if(!c)failed++;};
const auth=fs.readFileSync('source/sabri-membership-core/includes/class-smc-authorization.php','utf8');
pass('restricted capability baseline cannot be removed by filter', auth.includes('array_merge( self::$restricted_caps, $filtered )'));
pass('hard-block baseline is unioned back after filtering', auth.includes('array_merge( $baseline, $filtered )'));
for (const state of ['rejected','suspended','expired','appeal_review','erasure_pending','invalid_application']) pass(`mandatory hard block retained: ${state}`, auth.includes(`'${state}'`));
console.log(`Second fresh static: ${9-failed} PASS / ${failed} FAIL`); process.exit(failed?1:0);
''')

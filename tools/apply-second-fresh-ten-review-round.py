#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-completion.php'
s=source.read_text()
old="""\tpublic static function safe_mode() {\n\t\t$constant = defined( 'SMC_SAFE_MODE' ) && SMC_SAFE_MODE;\n\t\treturn (bool) apply_filters( 'smc_safe_mode', $constant || (bool) get_option( 'smc_safe_mode', false ) );\n\t}\n"""
new="""\tpublic static function safe_mode() {\n\t\t$constant = defined( 'SMC_SAFE_MODE' ) && SMC_SAFE_MODE;\n\t\t$declared = $constant || (bool) get_option( 'smc_safe_mode', false );\n\t\t$filtered = (bool) apply_filters( 'smc_safe_mode', $declared );\n\t\treturn $declared || $filtered;\n\t}\n"""
if new not in s:
    if old not in s: raise SystemExit('round4 safe-mode block not found')
    source.write_text(s.replace(old,new,1))

contract=root/'qa/second-fresh-review-contract.mjs'
c=contract.read_text() if contract.exists() else "import fs from 'node:fs';\nlet failed=0; const pass=(n,c)=>{console.log(`${c?'PASS':'FAIL'} ${n}`); if(!c)failed++;};\n"
if "const completion=" not in c:
    c=c.replace("const auth=fs.readFileSync('source/sabri-membership-core/includes/class-smc-authorization.php','utf8');\n", "const auth=fs.readFileSync('source/sabri-membership-core/includes/class-smc-authorization.php','utf8');\nconst completion=fs.readFileSync('source/sabri-membership-core/includes/class-smc-completion.php','utf8');\n")
if "safe-mode declaration cannot be filtered off" not in c:
    c=c.replace("console.log(`Second fresh static: ${9-failed} PASS / ${failed} FAIL`); process.exit(failed?1:0);", "pass('safe-mode declaration cannot be filtered off', completion.includes('return $declared || $filtered;'));\nconsole.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);")
contract.write_text(c)

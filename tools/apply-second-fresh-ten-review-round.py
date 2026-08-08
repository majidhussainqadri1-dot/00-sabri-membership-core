#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-privacy.php'
s=source.read_text()
old="""\t\t$page = max( 1, absint( $page ) );\n\t\t$groups = array(\n\t\t\t1 => self::export_identity( $user->ID ),\n\t\t\t2 => self::export_evidence( $user->ID ),\n\t\t\t3 => self::export_workflow( $user->ID ),\n\t\t\t4 => self::export_security( $user->ID ),\n\t\t);\n\t\treturn array(\n\t\t\t'data' => isset( $groups[ $page ] ) ? $groups[ $page ] : array(),\n\t\t\t'done' => $page >= count( $groups ),\n\t\t);\n"""
new="""\t\t$page = max( 1, absint( $page ) );\n\t\tswitch ( $page ) {\n\t\t\tcase 1:\n\t\t\t\t$data = self::export_identity( $user->ID );\n\t\t\t\tbreak;\n\t\t\tcase 2:\n\t\t\t\t$data = self::export_evidence( $user->ID );\n\t\t\t\tbreak;\n\t\t\tcase 3:\n\t\t\t\t$data = self::export_workflow( $user->ID );\n\t\t\t\tbreak;\n\t\t\tcase 4:\n\t\t\t\t$data = self::export_security( $user->ID );\n\t\t\t\tbreak;\n\t\t\tdefault:\n\t\t\t\t$data = array();\n\t\t}\n\t\treturn array( 'data' => $data, 'done' => $page >= 4 );\n"""
if new not in s:
    if old not in s: raise SystemExit('round8 privacy exporter block not found')
    source.write_text(s.replace(old,new,1))
contract=root/'qa/second-fresh-review-contract.mjs'
c=contract.read_text()
if "const privacy=" not in c:
    c=c.replace("const workflow=fs.readFileSync('source/sabri-membership-core/includes/class-smc-workflow.php','utf8');\n", "const workflow=fs.readFileSync('source/sabri-membership-core/includes/class-smc-workflow.php','utf8');\nconst privacy=fs.readFileSync('source/sabri-membership-core/includes/class-smc-privacy.php','utf8');\n")
if "privacy exporter processes only requested page" not in c:
    c=c.replace("console.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);", "pass('privacy exporter processes only requested page', privacy.includes('switch ( $page )') && !privacy.includes('1 => self::export_identity( $user->ID )'));\nconsole.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);")
contract.write_text(c)

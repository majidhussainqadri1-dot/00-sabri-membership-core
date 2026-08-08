#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-events.php'
s=source.read_text()
old="""\t\tif ( $processed > 0 ) {\n\t\t\tself::schedule_processor();\n\t\t}\n\t\treturn $processed;\n"""
new="""\t\t$retry_backlog = (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}smc_event_outbox WHERE status IN ('pending','retry')\" );\n\t\tif ( $retry_backlog > 0 ) {\n\t\t\tself::schedule_processor();\n\t\t}\n\t\treturn $processed;\n"""
if new not in s:
    if old not in s: raise SystemExit('round9 outbox reschedule block not found')
    source.write_text(s.replace(old,new,1))
contract=root/'qa/second-fresh-review-contract.mjs'
c=contract.read_text()
if "failed outbox delivery preserves retry scheduling" not in c:
    c=c.replace("console.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);", "pass('failed outbox delivery preserves retry scheduling', events.includes(\"status IN ('pending','retry')\") && events.includes('$retry_backlog > 0'));\nconsole.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);")
contract.write_text(c)

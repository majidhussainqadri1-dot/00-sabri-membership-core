#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-workflow.php'
s=source.read_text()
old="""\t\t$user_id = get_current_user_id();\n\t\t$submission_key = sanitize_text_field( wp_unslash( $_POST['submission_key'] ?? '' ) );\n"""
new="""\t\t$user_id = get_current_user_id();\n\t\t$existing_application = smc_application( $user_id );\n\t\tif ( $existing_application && ! in_array( sanitize_key( $existing_application['status'] ?? '' ), array( 'draft', 'more_information', 'rejected' ), true ) ) {\n\t\t\tself::redirect( 'status', 'saved' );\n\t\t}\n\t\t$submission_key = sanitize_text_field( wp_unslash( $_POST['submission_key'] ?? '' ) );\n"""
if new not in s:
    if old not in s: raise SystemExit('round7 submission lifecycle anchor not found')
    s=s.replace(old,new,1)
old2="""\t\tupdate_user_meta( $user_id, '_smc_last_submission_key', $submission_key );\n\t\tupdate_user_meta( $user_id, $submission_receipt_key, array( 'status' => 'completed', 'completed_at' => time(), 'trace_id' => $trace_id ) );\n\t\tSMC_Completion::clear_draft( $user_id );\n"""
new2="""\t\t$completed_receipt = array( 'status' => 'completed', 'completed_at' => time(), 'trace_id' => $trace_id );\n\t\tupdate_user_meta( $user_id, '_smc_last_submission_key', $submission_key );\n\t\t$last_key_ok = hash_equals( $submission_key, (string) get_user_meta( $user_id, '_smc_last_submission_key', true ) );\n\t\tupdate_user_meta( $user_id, $submission_receipt_key, $completed_receipt );\n\t\t$stored_receipt = get_user_meta( $user_id, $submission_receipt_key, true );\n\t\t$receipt_ok = is_array( $stored_receipt ) && 'completed' === ( $stored_receipt['status'] ?? '' ) && hash_equals( $trace_id, (string) ( $stored_receipt['trace_id'] ?? '' ) );\n\t\tif ( ! $last_key_ok && ! $receipt_ok ) {\n\t\t\tSMC_Security::audit( 'application_idempotency_receipt_failed', $user_id, array( 'trace_id' => $trace_id ) );\n\t\t}\n\t\tSMC_Completion::clear_draft( $user_id );\n"""
if new2 not in s:
    if old2 not in s: raise SystemExit('round7 completion receipt anchor not found')
    s=s.replace(old2,new2,1)
source.write_text(s)

contract=root/'qa/second-fresh-review-contract.mjs'
c=contract.read_text()
if "const workflow=" not in c:
    c=c.replace("const installer=fs.readFileSync('source/sabri-membership-core/includes/class-smc-installer.php','utf8');\n", "const installer=fs.readFileSync('source/sabri-membership-core/includes/class-smc-installer.php','utf8');\nconst workflow=fs.readFileSync('source/sabri-membership-core/includes/class-smc-workflow.php','utf8');\n")
if "submission handler enforces lifecycle server-side" not in c:
    c=c.replace("console.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);", "pass('submission handler enforces lifecycle server-side', /handle_submit_application[\\s\\S]{0,650}existing_application[\\s\\S]{0,450}more_information[\\s\\S]{0,250}rejected/.test(workflow));\npass('submission completion verifies idempotency persistence', workflow.includes('$last_key_ok = hash_equals') && workflow.includes('$receipt_ok = is_array') && workflow.includes('application_idempotency_receipt_failed'));\nconsole.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);")
contract.write_text(c)

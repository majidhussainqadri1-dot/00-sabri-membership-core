#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=root/'source/sabri-membership-core/includes/class-smc-security.php'
s=source.read_text()
old="""\tpublic static function serve_document() {\n\t\tif ( ! current_user_can( 'smc_view_private_documents' ) && ! current_user_can( 'manage_options' ) ) {\n\t\t\twp_die( esc_html__( 'Not authorized.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );\n\t\t}\n\t\t$id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;\n"""
new="""\tpublic static function serve_document() {\n\t\t$user_id = get_current_user_id();\n\t\tif ( ( ! current_user_can( 'smc_view_private_documents' ) && ! current_user_can( 'manage_options' ) ) || ! self::session_is_verified( $user_id ) ) {\n\t\t\twp_die( esc_html__( 'A current two-factor session and private-document capability are required.', 'sabri-membership-core' ), '', array( 'response' => 403 ) );\n\t\t}\n\t\t$id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;\n"""
if new not in s:
    if old not in s: raise SystemExit('round5 document authorization block not found')
    source.write_text(s.replace(old,new,1))

contract=root/'qa/second-fresh-review-contract.mjs'
c=contract.read_text()
if "const security=" not in c:
    insert="const security=fs.readFileSync('source/sabri-membership-core/includes/class-smc-security.php','utf8');\n"
    c=c.replace("const completion=fs.readFileSync('source/sabri-membership-core/includes/class-smc-completion.php','utf8');\n", "const completion=fs.readFileSync('source/sabri-membership-core/includes/class-smc-completion.php','utf8');\n"+insert)
if "private document release requires current two-factor session" not in c:
    c=c.replace("console.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);", "pass('private document release requires current two-factor session', /serve_document[\\s\\S]{0,450}session_is_verified\\( \\$user_id \\)/.test(security));\nconsole.log(`Second fresh static complete; failures: ${failed}`); process.exit(failed?1:0);")
contract.write_text(c)

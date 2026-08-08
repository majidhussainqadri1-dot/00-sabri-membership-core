#!/usr/bin/env python3
from pathlib import Path
path = Path(__file__).with_name('apply.py')
text = path.read_text(encoding='utf-8')
old = '''replace_once(
    lifecycle,
    "\\tpublic static function daily() {\\n\\t\\tself::recheck_ages();",
    "\\tpublic static function daily() {\\n\\t\\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\\n\\t\\tself::recheck_ages();",
)'''
new = '''replace_once(
    lifecycle,
    "\\tpublic static function daily() {\\n\\t\\tglobal $wpdb;",
    "\\tpublic static function daily() {\\n\\t\\tif ( class_exists( 'SMC_Completion' ) && SMC_Completion::safe_mode() ) { return; }\\n\\t\\tglobal $wpdb;",
)'''
if old not in text:
    raise SystemExit('preflight: lifecycle patch stanza not found')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('audit32 driver preflight adjusted')

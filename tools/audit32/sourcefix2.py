#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[2]
path=root/'source/sabri-membership-core/includes/class-smc-security.php'
text=path.read_text(encoding='utf-8')
old="\t\t\t$path = trailingslashit( $dir ) . $name;\n\t\t\t$ok = $valid && self::path_is_within( $path, $dir ) && self::verified_unlink( $path );"
new="\t\t\t$path = trailingslashit( $dir ) . $name;\n\t\t\t$ok = $valid && self::path_is_within( $path, $dir ) && self::verified_unlink( $path );\n\t\t\tif ( $ok && 'delete_orphan' === sanitize_key( $job['job_type'] ) ) {\n\t\t\t\t$lease_path = $path . '.lease';\n\t\t\t\t$ok = ! file_exists( $lease_path ) || ( self::path_is_within( $lease_path, $dir ) && self::verified_unlink( $lease_path ) );\n\t\t\t}"
if text.count(old)!=1: raise SystemExit(f'sourcefix2: expected one worker unlink match, found {text.count(old)}')
path.write_text(text.replace(old,new,1),encoding='utf-8',newline='\n')
print('orphan document companion lease cleanup fixed')

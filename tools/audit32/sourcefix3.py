#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'source/sabri-membership-core/includes/class-smc-admin.php'
text = path.read_text(encoding='utf-8')
old = "\t\tif ( ! $doc || (int) $doc['user_id'] === get_current_user_id() || ! $request || (int) $request['assigned_reviewer'] !== get_current_user_id() || 'none' !== $request['conflict_status'] || ! in_array( $request['status'], $allowed_states, true ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'This document is not in your currently assigned no-conflict review.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }"
new = "\t\tif ( ! $doc ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The evidence record is unavailable.', 'sabri-membership-core' ), '', array( 'response' => 404 ) ); }\n\t\tif ( (int) $doc['user_id'] === get_current_user_id() ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'A reviewer cannot decide their own evidence.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }\n\t\tif ( ! $request || (int) $request['assigned_reviewer'] !== get_current_user_id() || 'none' !== $request['conflict_status'] || ! in_array( $request['status'], $allowed_states, true ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'This document is not in your currently assigned no-conflict review.', 'sabri-membership-core' ), '', array( 'response' => 409 ) ); }"
if text.count(old) != 1:
    raise SystemExit(f'sourcefix3: expected one reviewer guard, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8', newline='\n')
print('explicit self-review denial restored inside scoped reviewer workflow')

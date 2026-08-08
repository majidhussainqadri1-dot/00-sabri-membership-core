#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[2]
path = root / 'source/sabri-membership-core/includes/class-smc-security.php'
text = path.read_text(encoding='utf-8')
start = text.find("\n\tpublic static function recovery_codes( $user_id, $count = 8, $receipt_callback = null ) {")
end = text.find("\n\n\tpublic static function consume_recovery_code_for_session", start)
if start < 0 or end < 0:
    raise SystemExit(f'sourcefix6: recovery_codes bounds missing start={start} end={end}')
new = r'''
	public static function recovery_codes( $user_id, $count = 8, $receipt_callback = null ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$count = max( 1, min( 20, absint( $count ) ) );
		$plain = array();
		$records = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$code = strtoupper( wp_generate_password( 12, false, false ) );
			$lookup = self::blind_index( $code, 'recovery-code' );
			if ( is_wp_error( $lookup ) ) { return $lookup; }
			$plain[] = $code;
			$records[] = array(
				'user_id'=>$user_id,
				'code_lookup_hash'=>$lookup,
				'code_hash'=>wp_hash_password( $code ),
				'created_at'=>current_time( 'mysql', true ),
			);
		}
		$owns_transaction = ! self::transaction_active();
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'smc_recovery_transaction', __( 'Recovery-code rotation could not start a database transaction.', 'sabri-membership-core' ) );
		}
		$deleted = $wpdb->delete( $wpdb->prefix . 'smc_recovery_codes', array( 'user_id'=>$user_id ), array('%d') );
		if ( false === $deleted ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			return new WP_Error( 'smc_recovery_reset', __( 'Existing recovery codes could not be replaced.', 'sabri-membership-core' ) );
		}
		foreach ( $records as $record ) {
			if ( 1 !== $wpdb->insert( $wpdb->prefix . 'smc_recovery_codes', $record, array('%d','%s','%s','%s') ) ) {
				if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
				clean_user_cache( $user_id );
				return new WP_Error( 'smc_recovery_store', __( 'Recovery codes could not be stored.', 'sabri-membership-core' ) );
			}
		}
		if ( is_callable( $receipt_callback ) && true !== call_user_func( $receipt_callback, $plain ) ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_receipt', __( 'Recovery codes were not replaced because the protected receipt could not be stored.', 'sabri-membership-core' ) );
		}
		if ( ! self::audit( 'recovery_codes_rotated', $user_id ) ) {
			if ( $owns_transaction ) { $wpdb->query( 'ROLLBACK' ); }
			clean_user_cache( $user_id );
			return new WP_Error( 'smc_recovery_audit', __( 'Recovery codes were not replaced because required audit evidence could not be recorded.', 'sabri-membership-core' ) );
		}
		if ( $owns_transaction && false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'smc_recovery_commit', __( 'Recovery-code rotation could not be committed.', 'sabri-membership-core' ) );
		}
		clean_user_cache( $user_id );
		return $plain;
	}
'''
path.write_text(text[:start] + new + text[end:], encoding='utf-8', newline='\n')
print('recovery-code rotation now joins an outer factor-change transaction instead of nesting START TRANSACTION')

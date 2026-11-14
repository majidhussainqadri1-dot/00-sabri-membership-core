<?php
/**
 * File00 T38: execute the canonical inverse-subject owner resolver with
 * deterministic WordPress usermeta fixtures. PHP7.4/8.3. No installed DB.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['users'] = array( 77 => (object)array( 'ID'=>77 ), 88 => (object)array( 'ID'=>88 ) );
$GLOBALS['meta'] = array(
    77 => array( '_smc_platform_uuid_v1'=>'00000000-0000-4000-8000-000000000077' ),
    88 => array( '_smc_platform_uuid_v1'=>'00000000-0000-4000-8000-000000000088' ),
);
$GLOBALS['forced_matches'] = null;
$GLOBALS['mutation_count'] = 0;
function absint( $value ) { return abs( (int) $value ); }
function get_userdata( $id ) { return isset( $GLOBALS['users'][(int)$id] ) ? $GLOBALS['users'][(int)$id] : false; }
function get_user_meta( $id, $key, $single = true ) {
    return isset( $GLOBALS['meta'][(int)$id][$key] ) ? $GLOBALS['meta'][(int)$id][$key] : '';
}
function get_users( $args ) {
    if ( ! is_array($args) || $args['meta_key'] !== '_smc_platform_uuid_v1'
        || $args['fields'] !== 'ids' || $args['number'] !== 2 ) { return false; }
    if ( $GLOBALS['forced_matches'] !== null ) { return $GLOBALS['forced_matches']; }
    $matches = array();
    foreach ($GLOBALS['meta'] as $id => $row) {
        if ( isset($row['_smc_platform_uuid_v1']) &&
            strtolower($row['_smc_platform_uuid_v1']) === strtolower((string)$args['meta_value']) ) {
            $matches[] = $id;
        }
    }
    return array_slice($matches,0,2);
}
function add_user_meta() { $GLOBALS['mutation_count']++; return false; }
function update_user_meta() { $GLOBALS['mutation_count']++; return false; }
function wp_generate_uuid4() { $GLOBALS['mutation_count']++; return '00000000-0000-4000-8000-000000009999'; }
require dirname(__DIR__) . '/source/sabri-membership-core/includes/functions.php';
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-cf01-contract.php';
$count=0;
$check=static function($okay,$label) use (&$count) {
    $count++;
    if(!$okay){fwrite(STDERR,'File00 T38 FAIL '.$label."\n");exit(1);}
};
$uuid='00000000-0000-4000-8000-000000000077';
$check( smc_get_user_id_by_subject_uuid($uuid)===77,'unique current subject resolves to its account' );
$check( smc_get_user_id_by_subject_uuid(strtoupper($uuid))===77,'case-normalized canonical UUID' );
$check( smc_get_user_id_by_subject_uuid('not-uuid')===0,'malformed subject rejected' );
$check( smc_get_user_id_by_subject_uuid('')===0,'empty subject rejected' );
$check( smc_get_user_id_by_subject_uuid(' '.$uuid)===0,'whitespace padding rejected' );
$check( smc_get_user_id_by_subject_uuid(77)===0,'native WordPress numeric IDs not accepted' );
$check( smc_get_user_id_by_subject_uuid('00000000-0000-4000-8000-000000000099')===0,'nonexistent canonical subject fail closed' );
$GLOBALS['forced_matches']=array(77,88);
$check( smc_get_user_id_by_subject_uuid($uuid)===0,'duplicate owner matches fail closed' );
$GLOBALS['forced_matches']=array(88);
$check( smc_get_user_id_by_subject_uuid($uuid)===0,'candidate ID and persisted canonical subject mismatch' );
$GLOBALS['forced_matches']=array(999);
$check( smc_get_user_id_by_subject_uuid($uuid)===0,'deleted account is not returned' );
$GLOBALS['forced_matches']=array();
$check( smc_get_user_id_by_subject_uuid($uuid)===0,'no matching account is not an identity' );
$GLOBALS['forced_matches']=null;
$GLOBALS['meta'][88]['_smc_platform_uuid_v1']=$uuid;
$check( smc_get_user_id_by_subject_uuid($uuid)===0,'duplicate canonical meta refuses ambiguous account' );
$GLOBALS['meta'][88]['_smc_platform_uuid_v1']='00000000-0000-4000-8000-000000000088';
$check( $GLOBALS['mutation_count']===0,'lookup never created or mutated a subject' );
echo 'File00 T38 subject resolver runtime fixtures PASS '.$count.'/'.$count.
    " (standalone PHP fixtures, not staging DB or installed consumer parity)\n";

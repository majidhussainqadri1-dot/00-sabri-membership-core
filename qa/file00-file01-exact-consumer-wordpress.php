<?php
/** Disposable five-owner WordPress authorization boundary; no live or real patient operations. */
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='staging'||
wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST)!=='file06-consent-ci.local'||
getenv('SABRI_DISPOSABLE_CONSENT_QA')!=='1'||DB_NAME!=='wordpress'||
DB_HOST!=='127.0.0.1:3306'||realpath(ABSPATH)!=='/tmp/wordpress'){throw new RuntimeException('Non-disposable test forbidden');}
function f01_assert($condition,$name){if(!$condition){throw new RuntimeException('FAIL '.$name);}echo "PASS: ".$name.PHP_EOL;}
f01_assert(defined('SPF_VERSION')&&SPF_VERSION==='2.0.2'&&SPF_CONTRACT_VERSION==='2.0.0','exact File01 candidate active');
f01_assert(defined('SMC_VERSION')&&defined('SAUTH_VERSION')&&defined('SPCRC_VERSION')&&class_exists('HE_V12_Case_Consent_Owner'),'five real owners loaded');
f01_assert((string)get_option('spf_schema_version')===SPF_SCHEMA_VERSION,'File01 native schema current');
f01_assert(smc_is_founder(1),'canonical synthetic founder');
$admin=wp_create_user('file01-ci-other-admin','Disposable-Only-2026!','file01-ci-admin@example.test');
f01_assert(!is_wp_error($admin),'disposable ordinary administrator created');
(new WP_User($admin))->set_role('administrator');
$member=wp_create_user('file01-ci-member','Disposable-Only-2026!','file01-ci-member@example.test');
f01_assert(!is_wp_error($member),'disposable ordinary member created');
$cases=[
['register_manifest',['module_key'=>'file-01','owner_file'=>'01'],'manifest_registration'],
['register_contract',['owner_module'=>'file-01','contract_key'=>'ci-contract'],'contract_registration'],
['acknowledge_contract',['owner_module'=>'file-01','contract_key'=>'ci-contract'],'contract_acknowledgement'],
['map_route',['owner_module'=>'file-01','route_key'=>'ci-route'],'route_mapping'],
['repair_owned_mapping',['module_key'=>'file-01'],'safe_repair'],
['set_flag',['owner_module'=>'file-01','flag_key'=>'ci-flag','environment'=>'staging'],'feature_flag'],
];
foreach($cases as [$action,$object,$purpose]){
wp_set_current_user(1);
f01_assert(SPF_Authorization::can($action,$object,['purpose'=>$purpose])===true,'founder '.$action.' claim accepted by real File01');
wp_set_current_user($admin);
f01_assert(SPF_Authorization::can($action,$object,['purpose'=>$purpose])===false,'non-founder administrator '.$action.' denied');
wp_set_current_user($member);
f01_assert(SPF_Authorization::can($action,$object,['purpose'=>$purpose])===false,'ordinary member '.$action.' denied');
wp_set_current_user(0);
f01_assert(SPF_Authorization::can($action,$object,['purpose'=>$purpose])===false,'guest '.$action.' denied');
}
wp_set_current_user(1);
f01_assert(SPF_Authorization::can('arbitrary_unregistered_action',['module_key'=>'file-01'])===false,'unknown action denied even to founder');
wp_set_current_user($admin);
f01_assert(SPF_Authorization::can('run_system_check',['object_id'=>'system-check'])===true,'ordinary eligible administrator retains diagnostics');
f01_assert(SPF_Authorization::can('approve_release',['object_id'=>'fixture'])===false,'ordinary administrator cannot approve release');
wp_set_current_user(0);
echo "PASS exact File00 and File01 source authorization coexistence; no full owner mutation acceptance claimed".PHP_EOL;

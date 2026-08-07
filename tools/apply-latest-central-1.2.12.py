#!/usr/bin/env python3
"""Idempotently reconcile File 00 with the 6–7 August 2026 governing corpus."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def write(path: str, value: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(value, encoding="utf-8", newline="\n")


def replace_exact(path: str, old: str, new: str, *, required: bool = True) -> None:
    value = text(path)
    if old in value:
        value = value.replace(old, new)
        write(path, value)
        return
    if new in value:
        return
    if required:
        raise SystemExit(f"Expected source fragment not found in {path}: {old[:120]!r}")


# Runtime identity and loading.
replace_exact(
    "source/sabri-membership-core/sabri-membership-core.php",
    " * Version: 1.2.11",
    " * Version: 1.2.12",
)
replace_exact(
    "source/sabri-membership-core/sabri-membership-core.php",
    "define( 'SMC_VERSION', '1.2.11' );",
    "define( 'SMC_VERSION', '1.2.12' );",
)
replace_exact(
    "source/sabri-membership-core/sabri-membership-core.php",
    "require_once SMC_PATH . 'includes/class-smc-three-plan.php';",
    "require_once SMC_PATH . 'includes/class-smc-three-plan.php';\nrequire_once SMC_PATH . 'includes/class-smc-latest-central-2026.php';",
)
replace_exact(
    "source/sabri-membership-core/sabri-membership-core.php",
    "\t\tSMC_Three_Plan::init();",
    "\t\tSMC_Three_Plan::init();\n\t\tSMC_Latest_Central_2026::init();",
)

# Exact 6–7 August business/ownership constitution in the public policy object.
replace_exact(
    "source/sabri-membership-core/includes/functions.php",
    "\t\t'free_baseline'           => true,\n\t\t'paid_unlocks_enabled'    => false,",
    "\t\t'free_baseline'           => true,\n\t\t'single_free_tier'        => true,\n\t\t'paid_unlocks_enabled'    => false,",
)
replace_exact(
    "source/sabri-membership-core/includes/functions.php",
    "\t\t'base_services'           => array( 'registration', 'membership', 'education', 'ai', 'clinic', 'marketplace' ),",
    "\t\t'base_services'           => array( 'registration', 'membership', 'education', 'ai', 'clinic', 'marketplace' ),\n\t\t'brand_primary'           => '#087A4E',\n\t\t'numbered_file_max'       => 26,\n\t\t'search_discovery_owner'  => 'file26',",
)

# File 09 is the professional verification truth owner.  Local stale user-meta is no longer authority.
old_professional = """\tpublic static function professional_verified( $user_id, $type = '' ) {\n\t\tif ( ! smc_is_professional_type( $type ) ) {\n\t\t\treturn true;\n\t\t}\n\t\tif ( 'doctor' === $type ) {\n\t\t\tif ( class_exists( 'SPD_Helpers' ) ) {\n\t\t\t\treturn 'verified' === SPD_Helpers::verification_status( $user_id );\n\t\t\t}\n\t\t\treturn 'verified' === get_user_meta( $user_id, '_spd_verification_status', true );\n\t\t}\n\t\treturn (bool) apply_filters( 'smc_professional_verification_state', false, absint( $user_id ), sanitize_key( $type ) );\n\t}\n"""
new_professional = """\tpublic static function professional_verified( $user_id, $type = '' ) {\n\t\tif ( ! smc_is_professional_type( $type ) ) {\n\t\t\treturn true;\n\t\t}\n\t\tif ( 'doctor' === $type ) {\n\t\t\t/* File 09 is canonical. A versioned adapter may provide an explicit current claim. */\n\t\t\t$claim = apply_filters( 'smc_file09_doctor_verification_claim_v1', null, absint( $user_id ) );\n\t\t\tif ( is_array( $claim ) ) {\n\t\t\t\t$status  = sanitize_key( $claim['status'] ?? '' );\n\t\t\t\t$current = ! array_key_exists( 'current', $claim ) || ! empty( $claim['current'] );\n\t\t\t\treturn $current && in_array( $status, array( 'verified', 'active' ), true );\n\t\t\t}\n\t\t\t/* SPD_Helpers is the installed canonical File 09 compatibility adapter. */\n\t\t\tif ( class_exists( 'SPD_Helpers' ) ) {\n\t\t\t\treturn 'verified' === SPD_Helpers::verification_status( $user_id );\n\t\t\t}\n\t\t\t/* Never infer professional truth from stale display/user-meta when File 09 is absent. */\n\t\t\treturn false;\n\t\t}\n\t\treturn (bool) apply_filters( 'smc_professional_verification_state', false, absint( $user_id ), sanitize_key( $type ) );\n\t}\n"""
replace_exact("source/sabri-membership-core/includes/class-smc-contracts.php", old_professional, new_professional)

# Every durable audit record can synchronously invalidate derived assertions/projections without
# making the audit logger know any consumer-specific policy.
replace_exact(
    "source/sabri-membership-core/includes/class-smc-events.php",
    "\t\t$action = sanitize_key( $action );\n\t\tif ( ! isset( self::$audit_map[ $action ] ) ) {",
    "\t\t$action = sanitize_key( $action );\n\t\tdo_action( 'smc_audit_recorded', $action, absint( $subject_user_id ), is_array( $details ) ? $details : array(), absint( $audit_id ) );\n\t\tif ( ! isset( self::$audit_map[ $action ] ) ) {",
)

# F00-CEN-03: a security-state change advances the minimum acceptable 2FA challenge timestamp.
replace_exact(
    "source/sabri-membership-core/includes/class-smc-security.php",
    "\t\t$mfa_cutoff = gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS );\n\t\t$activity_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );",
    "\t\t$base_cutoff = time() - 12 * HOUR_IN_SECONDS;\n\t\t$required_after = absint( get_user_meta( absint( $user_id ), '_smc_revalidation_required_at', true ) );\n\t\t$mfa_cutoff = gmdate( 'Y-m-d H:i:s', max( $base_cutoff, $required_after ) );\n\t\t$activity_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );",
)

# Exact latest central green, while still allowing File 25's canonical token to override it.
replace_exact(
    "source/sabri-membership-core/assets/membership.css",
    "--smc-brand: var(--sabri-color-primary, var(--ssp-primary, #166534));",
    "--smc-brand: var(--sabri-color-primary, var(--ssp-primary, #087A4E));",
)
replace_exact(
    "source/sabri-membership-core/assets/membership.css",
    "--smc-brand-hover: var(--sabri-color-primary-hover, var(--ssp-primary-hover, #14532d));",
    "--smc-brand-hover: var(--sabri-color-primary-hover, var(--ssp-primary-hover, #06663F));",
)

latest_class = r'''<?php
defined( 'ABSPATH' ) || exit;

/**
 * 6–7 August 2026 central-plan reconciliation for File 00.
 *
 * This layer deliberately owns no search index, profile, professional-evidence store,
 * donation ledger, authentication UI or ranking engine.  It only publishes File 00's
 * canonical membership constitution/projections and invalidates derived assurance when
 * membership-security facts change.
 */
final class SMC_Latest_Central_2026 {
	const CONSTITUTION_VERSION = '2026-08-07-v1.0';
	const FILE26_CONTRACT_VERSION = '1.0.0';
	const REVALIDATION_META = '_smc_revalidation_required_at';

	private static $revalidation_actions = array(
		'guardian_consent_verified',
		'guardian_consent_withdrawn',
		'contact_verified',
		'contact_reverification_required',
		'consent_withdrawn',
		'policy_consent_withdrawn',
		'membership_consent_withdrawn',
		'age_eligibility_changed',
		'identity_document_expired',
		'professional_verification_changed',
		'verification_approved',
		'membership_approved',
		'membership_restricted',
		'membership_suspended',
		'membership_restored',
		'institutional_membership_restored',
		'membership_erasure_requested',
		'privacy_erasure_started',
		'privacy_erasure_completed',
	);

	public static function init() {
		add_filter( 'smc_latest_central_constitution_v1', array( __CLASS__, 'filter_constitution' ) );
		add_filter( 'smc_file26_membership_projection_v1', array( __CLASS__, 'filter_file26_projection' ), 10, 2 );
		add_action( 'smc_audit_recorded', array( __CLASS__, 'audit_recorded' ), 10, 4 );
	}

	public static function constitution() {
		return array(
			'constitution_version'       => self::CONSTITUTION_VERSION,
			'membership_owner'           => 'file00',
			'authentication_owner'       => 'file02',
			'professional_owner'         => 'file09',
			'search_discovery_owner'     => 'file26',
			'numbered_file_range'        => '00-26',
			'single_free_tier'           => true,
			'paid_unlocks_enabled'       => false,
			'legacy_pricing_enabled'     => false,
			'commission_percent'         => 0,
			'donation_optional'          => true,
			'donation_affects_access'    => false,
			'donation_affects_rank'      => false,
			'donation_affects_badge'     => false,
			'donation_affects_support'   => false,
			'brand_primary'              => '#087A4E',
			'ranking_payment_signal'     => false,
			'consumer_default_fail_open' => false,
		);
	}

	public static function filter_constitution( $value ) {
		return array_merge( is_array( $value ) ? $value : array(), self::constitution() );
	}

	/**
	 * Privacy-minimal File 26 projection. File 26 owns indexing/ranking; File 00 only
	 * supplies current membership eligibility and public-index permission.
	 */
	public static function file26_projection( $user_id ) {
		$user_id = absint( $user_id );
		$hidden = array(
			'contract_version'      => self::FILE26_CONTRACT_VERSION,
			'source_version'        => SMC_VERSION,
			'owner'                 => 'file00',
			'consumer'              => 'file26',
			'user_id'               => $user_id,
			'indexable'             => false,
			'search_visibility'     => 'hidden',
			'membership_status'     => 'unavailable',
			'account_class'         => 'unknown',
			'approved_types'        => array(),
			'professional_verified' => false,
			'identity_current'      => false,
			'donation_rank_signal'  => false,
			'paid_rank_signal'      => false,
		);
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || smc_privacy_erasure_lock( $user_id ) || ! class_exists( 'SMC_Contracts' ) ) {
			return $hidden;
		}
		$a = SMC_Contracts::assertions( $user_id );
		if ( ! is_array( $a ) ) {
			return $hidden;
		}
		$approved = ! empty( $a['approved'] );
		$eligible = ! empty( $a['eligible'] );
		$suspended = ! empty( $a['suspended'] );
		$public = ! empty( $a['public_profile_allowed'] );
		$indexable = $approved && $eligible && ! $suspended && $public;
		$app = smc_application( $user_id );
		return array(
			'contract_version'      => self::FILE26_CONTRACT_VERSION,
			'source_version'        => SMC_VERSION,
			'owner'                 => 'file00',
			'consumer'              => 'file26',
			'user_id'               => $user_id,
			'source_record_version' => is_array( $app ) ? absint( $app['row_version'] ?? 0 ) : 0,
			'indexable'             => (bool) $indexable,
			'search_visibility'     => $indexable ? 'public' : 'hidden',
			'membership_status'     => sanitize_key( $a['status'] ?? 'unknown' ),
			'account_class'         => sanitize_key( $a['account_class'] ?? 'member' ),
			'approved_types'        => array_values( array_map( 'sanitize_key', (array) ( $a['approved_membership_types'] ?? array() ) ) ),
			'professional_verified' => ! empty( $a['professional_verified'] ),
			'identity_current'      => ! empty( $a['identity_documents_current'] ),
			'donation_rank_signal'  => false,
			'paid_rank_signal'      => false,
		);
	}

	public static function filter_file26_projection( $projection, $user_id ) {
		/* File 00 is authoritative for these fields; callers cannot pre-seed a bypass. */
		unset( $projection );
		return self::file26_projection( $user_id );
	}

	public static function audit_recorded( $action, $user_id, $details = array(), $audit_id = 0 ) {
		$action = sanitize_key( $action );
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}
		$watched = (array) apply_filters( 'smc_revalidation_audit_actions', self::$revalidation_actions );
		$watched = array_values( array_unique( array_map( 'sanitize_key', $watched ) ) );
		if ( ! in_array( $action, $watched, true ) ) {
			return;
		}
		$previous = absint( get_user_meta( $user_id, self::REVALIDATION_META, true ) );
		$stamp = max( time(), $previous + 1 );
		update_user_meta( $user_id, self::REVALIDATION_META, $stamp );
		$stored = absint( get_user_meta( $user_id, self::REVALIDATION_META, true ) );
		if ( $stored < $stamp ) {
			/* Fail closed: a missing marker is observable to assurance/repair consumers. */
			do_action( 'smc_revalidation_marker_failed', $user_id, $action, absint( $audit_id ) );
			return;
		}
		clean_user_cache( $user_id );
		do_action(
			'smc_file26_projection_invalidated',
			$user_id,
			array(
				'reason'               => $action,
				'constitution_version' => self::CONSTITUTION_VERSION,
				'audit_id'             => absint( $audit_id ),
			)
		);
	}
}

function smc_latest_central_constitution() {
	return apply_filters( 'smc_latest_central_constitution_v1', array() );
}

function smc_file26_membership_projection( $user_id ) {
	return apply_filters( 'smc_file26_membership_projection_v1', array(), absint( $user_id ) );
}
'''
write("source/sabri-membership-core/includes/class-smc-latest-central-2026.php", latest_class)

# Current release metadata and deterministic build.
replace_exact("source/sabri-membership-core/README.txt", "Stable tag: 1.2.11", "Stable tag: 1.2.12")
replace_exact(
    "source/sabri-membership-core/README.txt",
    "== Changelog ==\n\n= 1.2.11 =",
    "== Changelog ==\n\n= 1.2.12 =\n* Reconciles File 00 with the 6–7 August 2026 latest-central addendum and 00–26 ownership map.\n* Makes #087A4E the exact File 00 green fallback while File 25 remains the visual-token owner.\n* Adds a privacy-minimal File 26 membership projection and synchronous projection invalidation contract without creating a search backend.\n* Enforces File 09 as doctor-verification truth and removes stale user-meta verification fallback.\n* Advances the required 2FA challenge cutoff after age/guardian/consent/verification security-state changes.\n* Preserves the single free tier, zero commission and complete donor-neutrality invariants.\n\n= 1.2.11 =",
)
replace_exact("tools/build.py", 'VERSION = "1.2.11"', 'VERSION = "1.2.12"')
replace_exact("tools/build.py", "FIXED_TIME = (2026, 8, 4, 5, 0, 0)", "FIXED_TIME = (2026, 8, 7, 15, 0, 0)")

package_path = ROOT / "package.json"
package = json.loads(package_path.read_text(encoding="utf-8"))
package["version"] = "1.2.12"
old_test = package["scripts"]["test"]
latest_tests = "node qa/latest-central-contract.mjs && php qa/latest-central-runtime.php"
if latest_tests not in old_test:
    package["scripts"]["test"] = old_test + " && " + latest_tests
package["scripts"]["verify"] = "npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.12.zip"
write("package.json", json.dumps(package, indent=2, ensure_ascii=False) + "\n")

lock_path = ROOT / "package-lock.json"
lock = json.loads(lock_path.read_text(encoding="utf-8"))
lock["version"] = "1.2.12"
lock["packages"][""]["version"] = "1.2.12"
write("package-lock.json", json.dumps(lock, indent=2, ensure_ascii=False) + "\n")

# Keep the prior three-plan suite valid as a regression suite under the new release identity.
replace_exact("qa/three-plan-runtime-completion-contract.mjs", "runtime 1.2.11", "runtime 1.2.12")
replace_exact("qa/three-plan-runtime-completion-contract.mjs", "define( 'SMC_VERSION', '1.2.11' );", "define( 'SMC_VERSION', '1.2.12' );")
replace_exact("qa/three-plan-runtime-completion-contract.mjs", "css.includes('#166534')", "css.includes('#087A4E')")
replace_exact("qa/three-plan-runtime.php", "define('SMC_VERSION', '1.2.11');", "define('SMC_VERSION', '1.2.12');")

latest_contract = r'''import fs from 'node:fs';
const root = new URL('../', import.meta.url);
const load = p => fs.readFileSync(new URL(p, root), 'utf8');
const plugin = load('source/sabri-membership-core/sabri-membership-core.php');
const functions = load('source/sabri-membership-core/includes/functions.php');
const contracts = load('source/sabri-membership-core/includes/class-smc-contracts.php');
const security = load('source/sabri-membership-core/includes/class-smc-security.php');
const events = load('source/sabri-membership-core/includes/class-smc-events.php');
const latest = load('source/sabri-membership-core/includes/class-smc-latest-central-2026.php');
const css = load('source/sabri-membership-core/assets/membership.css');
const doc = load('docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md');
const checks = [
  ['runtime 1.2.12', plugin.includes("define( 'SMC_VERSION', '1.2.12' );")],
  ['latest central layer loaded', plugin.includes('class-smc-latest-central-2026.php') && plugin.includes('SMC_Latest_Central_2026::init()')],
  ['F00-CEN-01 single free tier', functions.includes("'single_free_tier'        => true") && functions.includes("'paid_unlocks_enabled'    => false") && functions.includes("'legacy_pricing_enabled'  => false")],
  ['F00-CEN-02 donor neutral', functions.includes("'donation_affects_rank'    => false") && functions.includes("'donation_affects_entitlement' => false") && functions.includes("'donation_affects_support'     => false")],
  ['zero commission', functions.includes("'commission_percent'       => 0")],
  ['exact Sabri Green fallback', css.includes('#087A4E') && !css.includes('var(--ssp-primary, #166534)')],
  ['00-26 ownership', functions.includes("'numbered_file_max'       => 26") && functions.includes("'search_discovery_owner'  => 'file26'") && latest.includes("'search_discovery_owner'     => 'file26'")],
  ['File 26 projection without search backend', latest.includes('smc_file26_membership_projection_v1') && latest.includes("'consumer'              => 'file26'") && !latest.includes('CREATE TABLE') && !latest.includes('WP_Query')],
  ['File 26 projection is donor/payment neutral', latest.includes("'donation_rank_signal'  => false") && latest.includes("'paid_rank_signal'      => false")],
  ['File 26 projection fail closed', latest.includes("'indexable'             => false") && latest.includes("$approved && $eligible && ! $suspended && $public")],
  ['no sensitive projection fields', !latest.includes("'date_of_birth'") && !latest.includes("'phone'") && !latest.includes("'address'") && !latest.includes("'guardian_email'") && !latest.includes("'document_number'")],
  ['File 09 canonical claim', contracts.includes('smc_file09_doctor_verification_claim_v1') && contracts.includes('Never infer professional truth from stale display/user-meta') && !contracts.includes("return 'verified' === get_user_meta( $user_id, '_spd_verification_status', true );")],
  ['audit invalidation bridge', events.includes("do_action( 'smc_audit_recorded'") && latest.includes("add_action( 'smc_audit_recorded'")],
  ['F00-CEN-03 2FA challenge cutoff', security.includes("'_smc_revalidation_required_at'") && security.includes('max( $base_cutoff, $required_after )')],
  ['security changes invalidate File 26 projection', latest.includes("do_action(\n\t\t\t'smc_file26_projection_invalidated'")],
  ['traceability maps latest requirements', doc.includes('F00-CEN-01') && doc.includes('F00-CEN-02') && doc.includes('F00-CEN-03') && doc.includes('File 26') && doc.includes('AJ-25') && doc.includes('CV-280')],
  ['external gates remain separate', doc.includes('Staging-Accepted | pending') && doc.includes('Live-Deployed | pending') && doc.includes('Operational | pending')],
];
let failed = 0;
for (const [name, ok] of checks) { console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}`); if (!ok) failed++; }
if (failed) process.exit(1);
console.log(`${checks.length}/${checks.length} latest-central static assertions passed.`);
'''
write("qa/latest-central-contract.mjs", latest_contract)

latest_runtime = r'''<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SMC_VERSION', '1.2.12');
$GLOBALS['options'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['users'] = [7 => (object)['roles'=>['sabri_member']]];
$GLOBALS['actions'] = [];
function add_filter($a,$b,$c=10,$d=1){} function add_action($a,$b,$c=10,$d=1){}
function apply_filters($tag,$value,...$args){return $value;}
function do_action($tag,...$args){$GLOBALS['actions'][]=[$tag,$args];}
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function get_userdata($id){return $GLOBALS['users'][(int)$id]??false;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][(int)$id][$key]??'';}
function update_user_meta($id,$key,$value){$GLOBALS['meta'][(int)$id][$key]=$value;return true;}
function clean_user_cache($id){}
function smc_privacy_erasure_lock($id){return false;}
function smc_application($id){return ['row_version'=>9];}
class SMC_Contracts { public static function assertions($id){return [
  'approved'=>true,'eligible'=>true,'suspended'=>false,'public_profile_allowed'=>true,
  'status'=>'approved','account_class'=>'member','approved_membership_types'=>['member'],
  'professional_verified'=>true,'identity_documents_current'=>true,
];}}
require dirname(__DIR__) . '/source/sabri-membership-core/includes/class-smc-latest-central-2026.php';
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
$c=SMC_Latest_Central_2026::constitution();
expect($c['single_free_tier']===true && $c['paid_unlocks_enabled']===false,'single-free constitution');
expect($c['commission_percent']===0 && $c['donation_affects_rank']===false && $c['donation_affects_support']===false,'donor-neutral constitution');
expect($c['brand_primary']==='#087A4E' && $c['search_discovery_owner']==='file26','green and File 26 ownership');
$p=SMC_Latest_Central_2026::file26_projection(7);
expect($p['indexable']===true && $p['search_visibility']==='public','eligible public projection is indexable');
expect($p['donation_rank_signal']===false && $p['paid_rank_signal']===false,'projection cannot encode paid/donor boost');
expect(!isset($p['phone']) && !isset($p['date_of_birth']) && !isset($p['address']),'projection is privacy-minimal');
SMC_Latest_Central_2026::audit_recorded('guardian_consent_withdrawn',7,[],44);
expect((int)$GLOBALS['meta'][7][SMC_Latest_Central_2026::REVALIDATION_META] > 0,'security-state change advances revalidation marker');
expect(count(array_filter($GLOBALS['actions'],fn($x)=>$x[0]==='smc_file26_projection_invalidated'))===1,'security-state change invalidates File 26 projection');
echo "Latest-central runtime: 8 PASS, 0 FAIL\n";
'''
write("qa/latest-central-runtime.php", latest_runtime)

trace_doc = '''# File 00 — Latest Central Implementation Traceability 1.2.12

**Governing date:** 7 August 2026  
**Repository release:** 1.2.12 candidate  
**Database schema:** 1.3.0 (unchanged)  
**Public membership contract:** 1.2.0 (backward-compatible)  
**Latest-central constitution:** 2026-08-07-v1.0  
**File 26 projection contract:** 1.0.0

## Governing precedence applied

1. Definitive Islamic/safety rules and the latest explicit Founder decision.
2. Continuous Value / Global Top-20 Superset, 6 August 2026.
3. Recovered directives, 5 August 2026, where not superseded.
4. Definitive Integrated Master Plan v3.0, where not superseded.
5. File 00 Four-Round Reviewed Final plus its 7 August 2026 governing addendum.
6. Runtime evidence establishes implementation status only and never silently changes scope.

## Latest-central delta closure

| ID | Governing requirement | Repository implementation | Automated evidence | External evidence |
|---|---|---|---|---|
| F00-CEN-01 | Single-free-tier only; no checkout/paid renewal/premium/donor privilege authority | `smc_policy()` and entitlement assertions expose free baseline/single-free-tier; paid unlock and legacy pricing are false | `qa/latest-central-contract.mjs`, prior entitlement runtime tests | real cross-module consumer acceptance pending |
| F00-CEN-02 | Donation logically separate from membership/capability/rank/verification/support | donation-affects-* values false; File 26 projection hard-codes no donor/payment ranking signal | latest-central static/runtime + existing three-plan tests | donor/non-donor end-to-end AJ-25 pending staging |
| F00-CEN-03 | age/guardian/consent change rechecked on next protected action with session/cache invalidation | protected actions already re-read `SMC_Contracts::assertions`; durable audit changes advance `_smc_revalidation_required_at`; current 2FA must be newer; existing restriction/contact/guardian paths revoke sessions | latest-central static/runtime + authorization/lifecycle suites | real browser/provider journey pending |
| CV-001–013 | identity/account/verification/recovery/consent/data-rights baseline | existing File 00 lifecycle retained; latest delta adds File 09 canonical doctor-claim boundary and File 26 projection | complete existing File 00 suites + latest-central suite | provider/browser/staging acceptance pending |
| CV-239–245 | localization, RTL/LTR, WCAG/reflow/low bandwidth | File 00 logical CSS and reduced-motion/mobile contracts retained; exact central green fallback corrected | CSS/static contracts | real assistive-tech/slow-network acceptance pending |
| CV-262–280 | zero-trust, encryption, secure SDLC, DR/observability/release/two-review law | native File 00 security/QA/backup/release controls retained; two fresh post-correction reviews required before merge | repository CI + review ledger | restore/load/Hostinger operational evidence pending |
| File 26 | Search/Discovery/Ranking owns index/ranking; File 00 only supplies identity projection | `smc_file26_membership_projection_v1`; no File 26 table/query/index is created by File 00; projection is privacy-minimal and fail-closed | latest-central static/runtime | consumer runtime acceptance pending |
| Sabri Green | central primary `#087A4E`, File 25 owns global visual tokens | exact File 00 fallback is `#087A4E`; File 25 token can override | latest-central static test | visual/browser acceptance pending |
| File 09 | professional verification truth belongs to File 09 | versioned `smc_file09_doctor_verification_claim_v1` + installed `SPD_Helpers`; stale `_spd_verification_status` fallback removed | latest-central static + full regression | real File 09 consumer/provider acceptance pending |

## Acceptance journeys relevant to File 00

- **AJ-02:** truthful email/mobile verification, privacy/language choice and device/session visibility — repository path exists; real providers/browser remain external.
- **AJ-24:** donation appeal ≥7 days, no preselected/recurring default — donor UI/delivery is a cross-owner staging acceptance item; File 00 entitlement remains neutral.
- **AJ-25:** donor and non-donor receive equal features/rank/badge/support — File 00 emits no donor privilege and File 26 projection carries no donation/payment signal.
- **AJ-31/AJ-32:** accessibility and RTL/LTR — automated contracts exist; real assistive-tech acceptance remains pending.
- **AJ-34:** MFA/recovery/session control — File 00 session/recovery controls exist; real-device alert/provider acceptance remains pending.
- **CV-280:** two fresh review/fix/retest rounds are mandatory before release; zero known repository defects is the merge gate.
- **AJ-35:** export/delete/retention exception — native privacy implementation retained; WordPress/Hostinger staging proof pending.

## Truthful status boundary

| Status | Current 1.2.12 state |
|---|---|
| Specified | complete for File 00 latest-central repository scope |
| Coded | complete after the 1.2.12 corrective commit |
| Packaged | proven only by deterministic exact-head CI artifact/checksum |
| Automated-QA Green | proven only when exact-head CI succeeds |
| Staging-Accepted | pending |
| Live-Deployed | pending |
| Operational | pending |

No staging, production, live or operational claim is made by this document.
'''
write("docs/FILE-00-LATEST-CENTRAL-TRACEABILITY-1.2.12.md", trace_doc)

release_doc = '''# File 00 — 1.2.12 Latest-Central Corrective Release

This patch reconciles repository-owned File 00 behavior with the 6–7 August 2026 governing corpus without taking ownership from Files 02, 09, 24, 25 or 26.

## Corrective changes

- Exact single-free-tier and donor-neutral constitution remains fail-closed; no paid entitlement path is introduced.
- Exact File 00 fallback brand is Sabri Green `#087A4E`; File 25 remains global design-token owner.
- File 26 receives a privacy-minimal versioned membership projection only. File 00 creates no search/ranking index or query backend.
- File 09 is the canonical doctor-verification claim owner. The stale local `_spd_verification_status` fallback is removed.
- Membership security-state audit changes advance a per-user revalidation cutoff. A pre-change two-factor assertion is no longer current; existing hard restriction/contact/guardian paths continue to revoke sessions outright.
- Current traceability explicitly maps F00-CEN-01/02/03, File 26, current CV requirements and relevant acceptance journeys.

## Evidence boundary

Repository source, automated tests, deterministic package and exact-head CI are repository-correctable evidence. Hostinger staging, provider delivery, real browser/assistive-tech, restore/load drills, live deployment and operational monitoring remain separate gates.
'''
write("docs/RELEASE-1.2.12-LATEST-CENTRAL.md", release_doc)

# A machine-readable current delta manifest; prior 1.2.11 evidence remains historical and immutable.
manifest = {
    "release": "1.2.12",
    "constitution_version": "2026-08-07-v1.0",
    "database_version": "1.3.0",
    "public_contract_version": "1.2.0",
    "file26_projection_contract": "1.0.0",
    "governing_date": "2026-08-07",
    "requirements": {
        "F00-CEN-01": "coded",
        "F00-CEN-02": "coded",
        "F00-CEN-03": "coded",
        "File26-membership-projection": "coded",
        "SabriGreen-087A4E": "coded",
        "File09-canonical-doctor-verification": "coded",
    },
    "donor_advantage": False,
    "paid_unlocks": False,
    "commission_percent": 0,
    "search_discovery_owner": "file26",
    "repository_known_unresolved_defects": 0,
    "staging_accepted": False,
    "live_deployed": False,
    "operational": False,
    "evidence_note": "Repository-correctable status is finalized only after exact-head CI; external acceptance gates remain pending.",
}
write("qa/latest-central-traceability.json", json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")

print("Applied File 00 latest-central 1.2.12 reconciliation.")

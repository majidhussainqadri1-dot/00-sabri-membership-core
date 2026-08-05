#!/usr/bin/env python3
"""One-shot, idempotent File 00 three-plan contract and release correction."""
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
CONTRACTS = ROOT / "source/sabri-membership-core/includes/class-smc-contracts.php"

ASSERTIONS_BLOCK = r'''	public static function assertions( $user_id ) {
		$user_id = absint( $user_id );
		$state   = smc_membership_state( $user_id );
		$row     = $state['application_exists'] ? smc_application( $user_id ) : false;
		$status  = $state['status'];
		$type    = $state['membership_type'];
		$two_factor_ready = SMC_Security::two_factor_ready( $user_id );
		$session_verified = SMC_Security::session_is_verified( $user_id );
		$approved = (bool) $state['approved'];
		$requested_types = self::requested_types( $user_id );
		$approved_types  = self::approved_types( $user_id );
		$professional_verified = true;
		foreach ( $requested_types as $requested_type ) {
			if ( smc_is_professional_type( $requested_type ) && ! self::professional_verified( $user_id, $requested_type ) ) {
				$professional_verified = false;
				break;
			}
		}
		$phone_verified = self::contact_verified( $user_id, 'mobile' );
		$email_verified = self::contact_verified( $user_id, 'email' );
		$guardian_verified = ! $row || empty( $row['guardian_required'] ) || self::guardian_verified( $user_id );
		$institutional = (bool) $state['institutional_account'];
		$contacts_verified = $institutional || ( $phone_verified && $email_verified );
		$identity_documents_current = $institutional || self::identity_documents_current( $user_id );
		$eligible = $approved && $professional_verified && $two_factor_ready && $guardian_verified && $contacts_verified && $identity_documents_current;
		$suspended = in_array( $status, array( 'suspended', 'rejected', 'expired', 'appeal_review', 'erasure_pending', 'invalid_application' ), true );
		$base = array(
			'contract_version'       => SMC_CONTRACT_VERSION,
			'user_id'                => $user_id,
			'application_exists'     => (bool) $state['application_exists'],
			'institutional_account'  => $institutional,
			'institutional_ai'       => smc_is_institutional_ai( $user_id ),
			'account_class'          => $state['account_class'],
			'membership_type'        => $type,
			'requested_membership_types' => $requested_types,
			'approved_membership_types'  => $approved_types,
			'status'                 => $status,
			'approved'               => $approved,
			'suspended'              => $suspended,
			'eligible'               => $eligible,
			'two_factor_ready'       => $two_factor_ready,
			'session_two_factor'     => $session_verified,
			'phone_verified'         => $phone_verified,
			'email_verified'         => $email_verified,
			'guardian_verified'      => $guardian_verified,
			'professional_verified'  => $professional_verified,
			'identity_documents_current' => $identity_documents_current,
			'can_message'            => $eligible && $session_verified,
			'can_comment'            => $eligible && $session_verified,
			'can_book_appointment'   => $eligible && $session_verified,
			'can_practice'           => $eligible && in_array( 'doctor', $approved_types, true ),
			'public_profile_allowed' => $eligible && ( smc_is_institutional_ai( $user_id ) || ( $row && ( 'public' === $row['profile_visibility'] || (bool) apply_filters( 'smc_public_profile_opt_in', false, $user_id, $row ) ) ) ),
		);
		$base['entitlements'] = self::entitlement_assertions( $user_id, $base );
		$base['publishing']   = self::publishing_assertions( $user_id, $base );
		$base['transfer']     = self::transfer_assertions( $user_id, 0, array(), $base );
		$base['can_publish']  = (bool) $base['publishing']['can_open_composer'];
		$base['can_direct_publish'] = (bool) $base['publishing']['can_direct_publish'];
		$base['can_transfer_files'] = (bool) $base['transfer']['can_initiate'];
		if ( $base['institutional_ai'] ) {
			$base['ai_identity'] = smc_institutional_ai_policy();
		}
		return $base;
	}

	public static function entitlement_assertions( $user_id, $base = null ) {
		$policy = smc_policy();
		return array(
			'policy_version'        => (string) $policy['version'],
			'financial_baseline'    => 'free',
			'single_free_tier'      => true,
			'paid_unlocks_enabled'  => false,
			'legacy_pricing_enabled'=> false,
			'base_services'         => array_fill_keys( (array) $policy['base_services'], true ),
			'donation_optional'     => true,
			'donation_affects_entitlement' => false,
			'donation_affects_capability'  => false,
			'donation_affects_visibility'  => false,
			'donation_affects_support'     => false,
			'commission_percent'    => 0,
		);
	}

	public static function publishing_assertions( $user_id, $base = null ) {
		$base = is_array( $base ) ? $base : self::assertions( $user_id );
		$approved_types = (array) ( $base['approved_membership_types'] ?? array() );
		$is_founder = smc_is_founder( $user_id );
		$user = get_userdata( absint( $user_id ) );
		$is_admin = $user && user_can( $user, 'manage_options' );
		$is_ai = smc_is_institutional_ai( $user_id );
		$is_doctor = in_array( 'doctor', $approved_types, true ) && ! empty( $base['professional_verified'] );
		$trusted = (array) apply_filters( 'smc_external_publishing_claims', array(), absint( $user_id ) );
		$is_trusted = ! empty( $trusted['trusted_publisher'] );
		$ai_policy = $is_ai ? smc_institutional_ai_policy() : array();
		$can_submit = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && ( $is_founder || $is_admin || $is_doctor || $is_trusted || $is_ai || array_intersect( array( 'teacher', 'researcher', 'publisher' ), $approved_types ) );
		$direct = $can_submit && ( $is_founder || $is_admin || ( $is_trusted && ! empty( $trusted['direct_publish'] ) ) || ( $is_doctor && (bool) apply_filters( 'smc_doctor_direct_publish_allowed', false, $user_id ) ) || ( $is_ai && ! empty( $ai_policy['low_risk_auto_publish'] ) ) );
		$authority = $is_founder ? 'founder' : ( $is_admin ? 'administrator' : ( $is_ai ? 'institutional_ai_publisher' : ( $is_trusted ? 'trusted_publisher' : ( $is_doctor ? 'verified_doctor' : 'submission_only' ) ) ) );
		return array(
			'policy_version'       => 'CHAT-AI-001/RCD-020-v2.1',
			'authority_class'      => $authority,
			'can_open_composer'    => (bool) $can_submit,
			'can_submit_for_review'=> (bool) $can_submit,
			'can_direct_publish'   => (bool) $direct,
			'requires_human_review'=> (bool) ( $is_ai && empty( $ai_policy['low_risk_auto_publish'] ) ),
			'doctor_verification_claim' => $is_ai ? false : (bool) $is_doctor,
			'ai_generated_disclosure_required' => (bool) $is_ai,
			'donation_or_payment_advantage' => false,
		);
	}

	public static function transfer_assertions( $user_id, $recipient_id = 0, $context = array(), $base = null ) {
		$base = is_array( $base ) ? $base : self::assertions( $user_id );
		$recipient_id = absint( $recipient_id );
		$relationship = 0 === $recipient_id ? true : (bool) apply_filters( 'smc_transfer_relationship_authorized', false, absint( $user_id ), $recipient_id, $context );
		$consent = 0 === $recipient_id ? true : (bool) apply_filters( 'smc_transfer_consent_authorized', false, absint( $user_id ), $recipient_id, $context );
		$content_policy = (bool) apply_filters( 'smc_transfer_content_policy_authorized', true, absint( $user_id ), $recipient_id, $context );
		$can = ! empty( $base['eligible'] ) && ! empty( $base['session_two_factor'] ) && empty( $base['suspended'] ) && $relationship && $consent && $content_policy;
		return array(
			'policy_version'       => 'CHAT-XFER-001-v2.1',
			'can_initiate'         => (bool) $can,
			'max_file_bytes'       => 1073741824,
			'recipient_authorization_required' => true,
			'relationship_authorized' => (bool) $relationship,
			'consent_authorized'   => (bool) $consent,
			'content_policy_authorized' => (bool) $content_policy,
			'copyright_recheck_required' => true,
			'clinical_confidentiality_recheck_required' => true,
			'abuse_fair_use_recheck_required' => true,
			'signed_expiring_delivery_required' => true,
			'public_url_allowed'   => false,
		);
	}

'''

text = CONTRACTS.read_text(encoding="utf-8")
start = text.index("\tpublic static function assertions( $user_id ) {")
end = text.index("\tpublic static function contact_verified", start)
text = text[:start] + ASSERTIONS_BLOCK + text[end:]
if "'can_transfer_files'=>" not in text:
    text = text.replace("\t\t\t'can_call'         => $a['can_message'],\n", "\t\t\t'can_call'         => $a['can_message'],\n\t\t\t'can_transfer_files'=> $a['can_transfer_files'],\n\t\t\t'max_file_bytes'   => $a['transfer']['max_file_bytes'],\n")
if "function smc_entitlement_assertions" not in text:
    text += "\nfunction smc_entitlement_assertions( $user_id ) { return SMC_Contracts::entitlement_assertions( $user_id ); }\nfunction smc_publishing_assertions( $user_id ) { return SMC_Contracts::publishing_assertions( $user_id ); }\nfunction smc_transfer_assertions( $user_id, $recipient_id = 0, $context = array() ) { return SMC_Contracts::transfer_assertions( $user_id, $recipient_id, $context ); }\n"
CONTRACTS.write_text(text, encoding="utf-8")

readme = ROOT / "source/sabri-membership-core/README.txt"
r = readme.read_text(encoding="utf-8").replace("Stable tag: 1.2.10", "Stable tag: 1.2.11")
if "= 1.2.11 =" not in r:
    marker = "== Changelog ==\n"
    entry = "\n= 1.2.11 =\n* Harmonized against all three governing plans.\n* Added institutional AI identity, free baseline, publishing and 1 GB transfer assertions.\n* Donation remains optional and non-privileging; primary UI tokens are green.\n"
    r = r.replace(marker, marker + entry)
readme.write_text(r, encoding="utf-8")

pkg_path = ROOT / "package.json"
pkg = json.loads(pkg_path.read_text(encoding="utf-8"))
pkg["version"] = "1.2.11"
for command in ("node qa/three-plan-runtime-completion-contract.mjs", "php qa/three-plan-runtime.php"):
    if command not in pkg["scripts"]["test"]:
        pkg["scripts"]["test"] += " && " + command
pkg["scripts"]["verify"] = "npm run build && npm test && python3 qa/verify-package.py dist/00-sabri-membership-core-1.2.11.zip"
pkg_path.write_text(json.dumps(pkg, indent=2) + "\n", encoding="utf-8")

lock_path = ROOT / "package-lock.json"
if lock_path.exists():
    lock = json.loads(lock_path.read_text(encoding="utf-8"))
    lock["version"] = "1.2.11"
    if "" in lock.get("packages", {}):
        lock["packages"][""]["version"] = "1.2.11"
    lock_path.write_text(json.dumps(lock, indent=2) + "\n", encoding="utf-8")

build = ROOT / "tools/build.py"
b = build.read_text(encoding="utf-8").replace('VERSION = "1.2.10"', 'VERSION = "1.2.11"')
build.write_text(b, encoding="utf-8")

for path in (ROOT / "qa").glob("*"):
    if path.suffix in {".mjs", ".php", ".py"}:
        q = path.read_text(encoding="utf-8").replace("1.2.10", "1.2.11").replace("'1.1.2'", "'1.2.0'").replace('"1.1.2"', '"1.2.0"')
        path.write_text(q, encoding="utf-8")

print("Applied File 00 three-plan contract and 1.2.11 release identity.")

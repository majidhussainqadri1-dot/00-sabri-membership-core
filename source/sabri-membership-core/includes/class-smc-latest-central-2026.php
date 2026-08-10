<?php
defined( 'ABSPATH' ) || exit;

/**
 * 6–10 August 2026 central-plan reconciliation for File 00.
 *
 * This layer deliberately owns no search index, profile, professional-evidence
 * store, donation ledger, authentication UI or ranking engine. It publishes
 * File 00's canonical membership constitution/projections and invalidates
 * derived projections when membership-security facts change.
 *
 * Founder change-control dated 10 August 2026 retired File 00 MFA. The former
 * _smc_revalidation_required_at / post-audit TOTP revalidation marker is no
 * longer written here. Authentication assurance belongs to File 02 (or a
 * separately approved authentication owner), while File 00 continues to
 * invalidate dependent membership projections immediately.
 */
final class SMC_Latest_Central_2026 {
	const CONSTITUTION_VERSION = '2026-08-10-v1.1';
	const FILE26_CONTRACT_VERSION = '1.0.0';

	private static $projection_invalidation_actions = array(
		'guardian_consent_verified',
		'guardian_consent_withdrawn',
		'guardian_requirement_ended_at_adulthood',
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
		/*
		 * The guard remains mandatory so a failed projection invalidation can fail
		 * the caller closed, but it no longer creates a File 00 MFA requirement.
		 */
		add_filter( 'smc_audit_record_guard', array( __CLASS__, 'audit_record_guard' ), 10, 5 );
	}

	public static function constitution() {
		return array(
			'constitution_version'       => self::CONSTITUTION_VERSION,
			'membership_owner'           => 'file00',
			'authentication_owner'       => 'file02',
			'mfa_owner'                  => 'none',
			'file00_mfa_required'        => false,
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

	private static function hidden_file26_projection( $platform_uuid = '' ) {
		return array(
			'contract_version'      => self::FILE26_CONTRACT_VERSION,
			'source_version'        => SMC_VERSION,
			'owner'                 => 'file00',
			'consumer'              => 'file26',
			'platform_uuid'         => (string) $platform_uuid,
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
	}

	/**
	 * Privacy-minimal File 26 projection. File 26 owns indexing/ranking; File 00
	 * only supplies current membership eligibility and public-index permission.
	 */
	public static function file26_projection( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || smc_privacy_erasure_lock( $user_id ) || ! class_exists( 'SMC_Contracts' ) || ! class_exists( 'SMC_CF01_Contract' ) ) {
			return self::hidden_file26_projection();
		}
		$platform_uuid = SMC_CF01_Contract::ensure_subject_uuid( $user_id );
		if ( '' === $platform_uuid ) {
			return self::hidden_file26_projection();
		}
		$a = SMC_Contracts::assertions( $user_id );
		if ( ! is_array( $a ) ) {
			return self::hidden_file26_projection( $platform_uuid );
		}
		$approved  = ! empty( $a['approved'] );
		$eligible  = ! empty( $a['eligible'] );
		$suspended = ! empty( $a['suspended'] );
		$public    = ! empty( $a['public_profile_allowed'] );
		$indexable = $approved && $eligible && ! $suspended && $public;
		$app = smc_application( $user_id );
		return array(
			'contract_version'      => self::FILE26_CONTRACT_VERSION,
			'source_version'        => SMC_VERSION,
			'owner'                 => 'file00',
			'consumer'              => 'file26',
			'platform_uuid'         => $platform_uuid,
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

	/**
	 * Mandatory post-audit guard for projection invalidation only.
	 *
	 * Extensions may add extra invalidation actions, but cannot delete File 00's
	 * baseline. No MFA/TOTP revalidation marker is written.
	 */
	public static function audit_record_guard( $allowed, $action, $user_id, $details = array(), $audit_id = 0 ) {
		if ( true !== $allowed ) {
			return false;
		}
		unset( $details, $audit_id );
		$action  = sanitize_key( $action );
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return true;
		}

		$filtered = (array) apply_filters( 'smc_projection_invalidation_audit_actions', self::$projection_invalidation_actions );
		$watched = array_values(
			array_unique(
				array_merge(
					self::$projection_invalidation_actions,
					array_map( 'sanitize_key', $filtered )
				)
			)
		);
		if ( ! in_array( $action, $watched, true ) ) {
			return true;
		}

		do_action(
			'smc_file26_projection_invalidated',
			$user_id,
			array(
				'reason'               => $action,
				'constitution_version' => self::CONSTITUTION_VERSION,
			)
		);
		return true;
	}
}

function smc_latest_central_constitution() {
	return SMC_Latest_Central_2026::constitution();
}

function smc_file26_membership_projection( $user_id ) {
	return SMC_Latest_Central_2026::file26_projection( absint( $user_id ) );
}

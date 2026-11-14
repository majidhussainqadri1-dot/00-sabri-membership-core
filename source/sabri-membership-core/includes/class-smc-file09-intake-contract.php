<?php
defined( 'ABSPATH' ) || exit;

/**
 * File 00-owned, purpose-scoped membership prerequisite for File 09 intake.
 *
 * Deliberately DIFFERENT from the CF-01 clinical-identity-link permission:
 * applicants cannot obtain professional verification until they can submit
 * evidence to File 09. A pending credential does not authorize treatment.
 * Clinical, publishing, directory, appointment and patient-data grants remain
 * governed by their current owners and NEVER arise from this assertion.
 */
final class SMC_File09_Intake_Contract {
    const CONTRACT_NAME = 'smc.file09.doctor-intake';
    const CONTRACT_VERSION = '1.0.0';
    const ASSERTION_TTL = 60;
    const ACTION = 'doctor_verification_intake';
    const PURPOSE = 'professional_credential_application';

    public static function contract() {
        return array(
            'contract' => self::CONTRACT_NAME,
            'contract_version' => self::CONTRACT_VERSION,
            'owner' => 'File 00',
            'consumer' => 'File 09',
            'authorization_scope' => 'file09_doctor_application_intake_only',
            'writes_professional_decisions' => false,
            'grants_clinical_authorization' => false,
            'requires_file09_independent_review' => true,
            'expires_after_seconds' => self::ASSERTION_TTL,
        );
    }

    /**
     * An allow is permission to *apply for professional verification only*.
     * No globally eligible membership, already verified professional role,
     * File 09 claim, or File 00 MFA flag is required for this isolated step.
     * The membership, role request, age, document, contact and jurisdiction
     * evidence must nevertheless be current and explicitly established.
     */
    public static function assertion( $user_id, $context = array() ) {
        $user_id = absint( $user_id );
        $context = is_array( $context ) ? $context : array();
        $now = time();
        $action = sanitize_key( isset( $context['action'] ) ? $context['action'] : '' );
        $purpose = sanitize_key( isset( $context['purpose'] ) ? $context['purpose'] : '' );
        $result = array(
            'contract'                       => self::CONTRACT_NAME,
            'contract_version'               => self::CONTRACT_VERSION,
            'producer_version'               => defined( 'SMC_VERSION' ) ? (string) SMC_VERSION : '',
            'issued_at'                      => gmdate( 'c', $now ),
            'expires_at'                     => gmdate( 'c', $now + self::ASSERTION_TTL ),
            'action'                         => $action,
            'purpose'                        => $purpose,
            'authorization_scope'            => 'file09_doctor_application_intake_only',
            'result'                         => 'unknown',
            'reason_code'                    => 'prerequisites_unavailable',
            'grants_clinical_authorization'  => false,
            'grants_professional_verification'=> false,
            'grants_patient_record_access'   => false,
            'grants_prescription_authority'  => false,
            'grants_public_badge'            => false,
            'grants_appointment_authority'   => false,
        );
        if ( self::ACTION !== $action || self::PURPOSE !== $purpose ) {
            $result['reason_code'] = 'unsupported_action_or_purpose';
            return $result;
        }
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            $result['reason_code'] = 'subject_unavailable';
            return $result;
        }
        // This consumer-only permission is a self-service application, not a
        // credential grant or an administrator impersonation capability.
        // Binding must be checked by File 00 itself: a caller-supplied ID,
        // anonymous request or different logged-in principal cannot borrow
        // another member's otherwise valid doctor-intake assertion.
        if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'is_user_logged_in' )
            || ! is_user_logged_in() || absint( get_current_user_id() ) !== $user_id ) {
            $result['reason_code'] = 'authenticated_subject_mismatch';
            return $result;
        }
        try {
            $base = SMC_Contracts::assertions( $user_id );
            $app = smc_application( $user_id );
        } catch ( Throwable $error ) {
            unset( $error );
            return $result;
        }
        if ( ! is_array( $base ) || ! is_array( $app )
            || absint( isset( $base['user_id'] ) ? $base['user_id'] : 0 ) !== $user_id
            || empty( $base['application_exists'] )
            || ! self::compatible_base_version( isset( $base['contract_version'] ) ? $base['contract_version'] : '' )
            || absint( isset( $app['row_version'] ) ? $app['row_version'] : 0 ) <= 0 ) {
            $result['reason_code'] = 'membership_record_invalid';
            return $result;
        }
        if ( 'approved' !== sanitize_key( isset( $base['status'] ) ? $base['status'] : '' )
            || empty( $base['approved'] ) || ! empty( $base['suspended'] ) ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'membership_not_approved_or_restricted';
            return $result;
        }
        // The global eligible flag may be false for credential preverification,
        // but it is also false under File 00's advanced containment, transition,
        // reverification or continuity holds. Never bypass those owner controls.
        if ( ! class_exists( 'SMC_MFA_Retirement' )
            || ! is_callable( array( 'SMC_MFA_Retirement', 'advanced_trust_allows_without_mfa' ) ) ) {
            $result['reason_code'] = 'advanced_trust_provider_unavailable';
            return $result;
        }
        try {
            $trust_allowed = SMC_MFA_Retirement::advanced_trust_allows_without_mfa( $user_id );
        } catch ( Throwable $error ) {
            unset( $error );
            $result['reason_code'] = 'advanced_trust_provider_unavailable';
            return $result;
        }
        if ( true !== $trust_allowed ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'advanced_trust_containment_or_reverification_hold';
            return $result;
        }
        // Role grants are independently owned by File 00. A pending doctor
        // request can apply; revoked/suspended grants cannot. No doctor role
        // is upgraded to approved, and no global eligibility is changed here.
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            return $result;
        }
        $wpdb->last_error = '';
        try {
            $grants = SMC_Contracts::role_grants( $user_id );
        } catch ( Throwable $error ) {
            unset( $error );
            return $result;
        }
        if ( ! is_array( $grants ) || ! empty( $wpdb->last_error ) ) {
            $result['reason_code'] = 'doctor_role_evidence_unavailable';
            return $result;
        }
        $doctor_requested = false;
        foreach ( $grants as $grant ) {
            if ( ! is_array( $grant ) || 'doctor' !== sanitize_key( isset( $grant['membership_type'] ) ? $grant['membership_type'] : '' ) ) {
                continue;
            }
            $status = sanitize_key( isset( $grant['status'] ) ? $grant['status'] : '' );
            if ( 'suspended' === $status || 'rejected' === $status ) {
                $result['result'] = 'deny';
                $result['reason_code'] = 'doctor_role_restricted';
                return $result;
            }
            if ( in_array( $status, array( 'pending', 'approved' ), true )
                && ( empty( $grant['expires_at'] ) || strtotime( $grant['expires_at'] . ' UTC' ) >= $now ) ) {
                $doctor_requested = true;
            }
        }
        $requested = isset( $base['requested_membership_types'] ) && is_array( $base['requested_membership_types'] )
            ? array_map( 'sanitize_key', $base['requested_membership_types'] ) : array();
        if ( ! $doctor_requested || ! in_array( 'doctor', $requested, true ) ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'doctor_role_not_requested';
            return $result;
        }
        if ( empty( $base['identity_documents_current'] )
            || empty( $base['phone_verified'] ) || empty( $base['email_verified'] )
            || ! empty( $app['guardian_required'] ) && empty( $base['guardian_verified'] ) ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'identity_or_contact_prerequisites_incomplete';
            return $result;
        }
        if ( empty( $app['date_of_birth_enc'] ) ) {
            $result['reason_code'] = 'verified_age_unavailable';
            return $result;
        }
        try {
            $dob = SMC_Security::decrypt( $app['date_of_birth_enc'], 'date-of-birth', array( 'user_id' => $user_id ) );
            $age = is_wp_error( $dob ) ? false : smc_age_from_dob( $dob );
        } catch ( Throwable $error ) {
            unset( $error );
            return $result;
        }
        if ( false === $age || ! is_numeric( $age ) ) {
            $result['reason_code'] = 'verified_age_unavailable';
            return $result;
        }
        $minimum_age = max( 18, absint( apply_filters( 'smc_file09_minimum_professional_age', 18, $user_id, $base ) ) );
        if ( (int) $age < $minimum_age ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'professional_age_not_met';
            return $result;
        }
        $requested_country = strtoupper( trim( (string) ( isset( $context['jurisdiction'] ) ? $context['jurisdiction'] : '' ) ) );
        if ( '' !== $requested_country && 1 !== preg_match( '/^[A-Z]{2}$/D', $requested_country ) ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'jurisdiction_invalid';
            return $result;
        }
        $wpdb->last_error = '';
        try {
            $canonical = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT issuing_country FROM {$wpdb->prefix}smc_identity_records WHERE user_id=%d LIMIT 1",
                $user_id
            ) );
        } catch ( Throwable $error ) {
            unset( $error );
            return $result;
        }
        $canonical = strtoupper( trim( $canonical ) );
        if ( ! empty( $wpdb->last_error ) || 1 !== preg_match( '/^[A-Z]{2}$/D', $canonical ) ) {
            $result['reason_code'] = 'canonical_jurisdiction_unavailable';
            return $result;
        }
        if ( '' !== $requested_country && ! hash_equals( $canonical, $requested_country ) ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'jurisdiction_mismatch';
            return $result;
        }
        $filter = apply_filters( 'smc_file09_intake_narrow', true, $user_id, $base, $app );
        if ( true !== $filter ) {
            $result['result'] = 'deny';
            $result['reason_code'] = 'owner_policy_narrowed';
            return $result;
        }
        // Issuing File 00's canonical subject UUID is the final prerequisite.
        $uuid = SMC_CF01_Contract::ensure_subject_uuid( $user_id );
        if ( ! is_string( $uuid ) || 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $uuid ) ) {
            $result['reason_code'] = 'subject_uuid_unavailable';
            return $result;
        }
        // A downstream owner-policy hook may change the current WordPress
        // principal after the initial gate. Recheck immediately before issuing
        // a successful assertion so neither stale actor context nor another
        // user's UUID/age information can escape on a switched session.
        if ( ! is_user_logged_in() || absint( get_current_user_id() ) !== $user_id ) {
            $result['reason_code'] = 'authenticated_subject_changed';
            return $result;
        }
        $result['subject'] = array(
            'platform_uuid'  => strtolower( $uuid ),
            'record_version' => absint( $app['row_version'] ),
            'source_owner'   => 'File 00',
        );
        $result['age_context'] = array( 'known' => true, 'age_years' => (int) $age );
        $result['jurisdiction_context'] = array(
            'known'             => true,
            'canonical_country' => $canonical,
            'requested_country' => $requested_country,
            'mismatch'          => false,
        );
        $result['role_scope'] = array( 'doctor_role_requested' => true, 'professional_verification_required' => true );
        $result['result'] = 'allow';
        $result['reason_code'] = 'doctor_intake_prerequisites_satisfied';
        return $result;
    }

    private static function compatible_base_version( $version ) {
        $version = (string) $version;
        return 1 === preg_match( '/^1\.[0-9]+\.[0-9]+$/D', $version )
            && version_compare( $version, '1.2.0', '>=' );
    }
}

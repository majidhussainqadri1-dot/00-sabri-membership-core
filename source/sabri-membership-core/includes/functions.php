<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public, versioned policy constants. Commission is intentionally immutable.
 */
function smc_policy() {
	return array(
		'version'                  => '2026-08-05-v2.1',
		'commission_percent'       => 0,
		'donation_optional'        => true,
		'donation_affects_rank'    => false,
		'donation_affects_entitlement' => false,
		'donation_affects_capability'  => false,
		'donation_affects_visibility'  => false,
		'donation_affects_support'     => false,
		'free_baseline'           => true,
		'single_free_tier'        => true,
		'paid_unlocks_enabled'    => false,
		'legacy_pricing_enabled'  => false,
		'base_services'           => array( 'registration', 'membership', 'education', 'ai', 'clinic', 'marketplace' ),
		'brand_primary'           => '#087A4E',
		'numbered_file_max'       => 26,
		'search_discovery_owner'  => 'file26',
		'donation_amounts_usd'    => array( 10, 14, 50 ),
		'donation_preselected'    => false,
		'donation_recurring_default' => false,
		'male_minimum_age'         => 15,
		'female_minimum_age'       => 12,
		'guardian_required_under'  => 18,
		'professional_minimum_age' => 18,
		'founder_public_name'      => 'Dr. Allamah Majid Hussain Sabri Muhaddith Mursheed',
	);
}

function smc_account_types() {
	return array(
		'member'     => __( 'General Member', 'sabri-membership-core' ),
		'patient'    => __( 'Patient', 'sabri-membership-core' ),
		'student'    => __( 'Homeopathy Student', 'sabri-membership-core' ),
		'doctor'     => __( 'Homeopathic Doctor', 'sabri-membership-core' ),
		'teacher'    => __( 'Teacher', 'sabri-membership-core' ),
		'researcher' => __( 'Researcher', 'sabri-membership-core' ),
		'pharmacy'   => __( 'Pharmacy', 'sabri-membership-core' ),
		'clinic'     => __( 'Clinic or Institution', 'sabri-membership-core' ),
		'publisher'  => __( 'Publisher', 'sabri-membership-core' ),
	);
}

function smc_role_for_type( $type, $approved = false ) {
	$pending = array(
		'member'     => 'sabri_member_pending',
		'patient'    => 'sabri_patient_pending',
		'student'    => 'sabri_student_pending',
		'doctor'     => 'sabri_doctor_pending',
		'teacher'    => 'sabri_teacher_pending',
		'researcher' => 'sabri_researcher_pending',
		'pharmacy'   => 'sabri_pharmacy_pending',
		'clinic'     => 'sabri_clinic_pending',
		'publisher'  => 'sabri_publisher_pending',
	);
	$approved_roles = array(
		'member'     => 'sabri_member',
		'patient'    => 'sabri_patient',
		'student'    => 'sabri_student',
		'doctor'     => 'sabri_doctor_verified',
		'teacher'    => 'sabri_teacher',
		'researcher' => 'sabri_researcher',
		'pharmacy'   => 'sabri_pharmacy',
		'clinic'     => 'sabri_clinic',
		'publisher'  => 'sabri_publisher',
	);
	$map = $approved ? $approved_roles : $pending;
	return isset( $map[ $type ] ) ? $map[ $type ] : $map['member'];
}

function smc_membership_roles() {
	$roles = array();
	foreach ( array_keys( smc_account_types() ) as $type ) {
		$roles[] = smc_role_for_type( $type, false );
		$roles[] = smc_role_for_type( $type, true );
	}
	return array_values( array_unique( $roles ) );
}

function smc_managed_roles() {
	return array_merge( smc_membership_roles(), array( 'sabri_membership_reviewer', 'sabri_membership_senior_reviewer', 'sabri_institutional_ai_teacher', 'sabri_institutional_ai_publisher' ) );
}

function smc_institutional_ai_user_id() {
	$id = defined( 'SMC_INSTITUTIONAL_AI_USER_ID' ) ? absint( SMC_INSTITUTIONAL_AI_USER_ID ) : absint( get_option( 'smc_institutional_ai_user_id', 0 ) );
	return $id && get_userdata( $id ) ? $id : 0;
}

function smc_is_institutional_ai( $user_id ) {
	return $user_id > 0 && absint( $user_id ) === smc_institutional_ai_user_id();
}

function smc_is_institutional_account( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) { return false; }
	if ( smc_is_founder( $user_id ) || smc_is_institutional_ai( $user_id ) ) { return true; }
	$user = get_userdata( $user_id );
	return $user && user_can( $user, 'manage_options' );
}

function smc_institutional_ai_policy() {
	$activated = (string) get_option( 'smc_institutional_ai_activated_at', '' );
	$probation_complete = $activated && strtotime( $activated . ' UTC' ) <= ( time() - 30 * DAY_IN_SECONDS );
	$auto_publish = $probation_complete && (bool) get_option( 'smc_institutional_ai_low_risk_auto_publish', false );
	return array(
		'policy_version'        => 'CHAT-AI-001-v2.1',
		'public_name'           => 'AI Homeopathy Teacher',
		'alternate_name'        => 'Sabri AI Teacher',
		'provider_disclosure'   => 'Powered by Claude AI',
		'account_class'         => 'institutional_ai_teacher',
		'institutional_roles'   => array( 'ai_teacher', 'ai_publisher' ),
		'doctor_verification'   => false,
		'daily_post_limit'      => 4,
		'human_review_days'     => 30,
		'probation_complete'    => (bool) $probation_complete,
		'low_risk_auto_publish' => (bool) $auto_publish,
		'patient_specific_clinical_authority' => false,
	);
}

function smc_account_class_for_user( $user_id, $user = null ) {
	if ( smc_is_founder( $user_id ) ) { return 'founder'; }
	if ( smc_is_institutional_ai( $user_id ) ) { return 'institutional_ai_teacher'; }
	$user = $user ?: get_userdata( absint( $user_id ) );
	return $user && user_can( $user, 'manage_options' ) ? 'administrator' : 'member';
}

function smc_sanitize_membership_types( $types ) {
	$types = is_array( $types ) ? $types : array( $types );
	$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	$types = array_values( array_intersect( array_keys( smc_account_types() ), $types ) );
	return $types ? $types : array( 'member' );
}

function smc_review_queue_types() {
	return array(
		'new'          => __( 'New applications', 'sabri-membership-core' ),
		'resubmitted'  => __( 'Resubmitted applications', 'sabri-membership-core' ),
		'guardian'     => __( 'Guardian verification', 'sabri-membership-core' ),
		'identity'     => __( 'Identity evidence', 'sabri-membership-core' ),
		'professional' => __( 'Professional verification', 'sabri-membership-core' ),
		'expiry'       => __( 'Expiring or expired evidence', 'sabri-membership-core' ),
		'appeal'       => __( 'Appeals and restoration', 'sabri-membership-core' ),
		'erasure'      => __( 'Erasure and retention', 'sabri-membership-core' ),
	);
}

function smc_review_reason_codes() {
	return array(
		'identity_match'          => __( 'Identity evidence matched', 'sabri-membership-core' ),
		'identity_mismatch'       => __( 'Identity evidence mismatch', 'sabri-membership-core' ),
		'missing_evidence'        => __( 'Required evidence is missing', 'sabri-membership-core' ),
		'guardian_verified'       => __( 'Guardian authority verified', 'sabri-membership-core' ),
		'guardian_invalid'        => __( 'Guardian authority is insufficient', 'sabri-membership-core' ),
		'professional_verified'   => __( 'Professional owner verification satisfied', 'sabri-membership-core' ),
		'professional_pending'    => __( 'Professional owner verification pending', 'sabri-membership-core' ),
		'policy_ineligible'       => __( 'Current policy eligibility not satisfied', 'sabri-membership-core' ),
		'appeal_upheld'           => __( 'Appeal upheld and membership restored', 'sabri-membership-core' ),
		'appeal_denied'           => __( 'Appeal denied on current evidence', 'sabri-membership-core' ),
		'security_restriction'    => __( 'Security restriction required', 'sabri-membership-core' ),
		'privacy_erasure'         => __( 'Privacy erasure or retention workflow', 'sabri-membership-core' ),
		'more_information'        => __( 'More information required', 'sabri-membership-core' ),
	);
}

function smc_professional_types() {
	return array( 'doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher' );
}

function smc_is_professional_type( $type ) {
	return in_array( sanitize_key( $type ), smc_professional_types(), true );
}

function smc_statuses() {
	return array(
		'draft'            => __( 'Draft', 'sabri-membership-core' ),
		'guardian_pending' => __( 'Guardian Consent Pending', 'sabri-membership-core' ),
		'submitted'        => __( 'Submitted', 'sabri-membership-core' ),
		'under_review'     => __( 'Under Review', 'sabri-membership-core' ),
		'more_information' => __( 'More Information Required', 'sabri-membership-core' ),
		'resubmitted'      => __( 'Resubmitted', 'sabri-membership-core' ),
		'approval_pending' => __( 'Second Approval Pending', 'sabri-membership-core' ),
		'approved'         => __( 'Approved', 'sabri-membership-core' ),
		'rejected'         => __( 'Rejected', 'sabri-membership-core' ),
		'suspended'        => __( 'Suspended', 'sabri-membership-core' ),
		'expired'          => __( 'Expired Evidence', 'sabri-membership-core' ),
		'appeal_review'    => __( 'Appeal Under Review', 'sabri-membership-core' ),
		'erasure_pending'  => __( 'Erasure Pending', 'sabri-membership-core' ),
	);
}

function smc_allowed_genders() {
	return array(
		'male'   => __( 'Male', 'sabri-membership-core' ),
		'female' => __( 'Female', 'sabri-membership-core' ),
	);
}

function smc_age_from_dob( $dob ) {
	$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$date     = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $dob, $timezone );
	$errors   = DateTimeImmutable::getLastErrors();
	if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $dob ) {
		return false;
	}
	$today = new DateTimeImmutable( 'today', $timezone );
	if ( $date > $today ) {
		return false;
	}
	return (int) $date->diff( $today )->y;
}

function smc_minimum_age_for_gender( $gender ) {
	$gender = sanitize_key( $gender );
	if ( ! isset( smc_allowed_genders()[ $gender ] ) ) {
		return false;
	}
	$policy = smc_policy();
	return 'female' === $gender ? (int) $policy['female_minimum_age'] : (int) $policy['male_minimum_age'];
}

/**
 * Return the stricter approved jurisdictional minimum without weakening the
 * Founder-approved sex-specific baseline. Jurisdiction policy is supplied by
 * an approved legal/child-safety adapter and may only raise the minimum.
 */
function smc_effective_minimum_age( $gender, $country = '' ) {
	$baseline = smc_minimum_age_for_gender( $gender );
	if ( false === $baseline ) {
		return false;
	}
	$country = strtoupper( sanitize_text_field( $country ) );
	$jurisdiction = (int) apply_filters( 'smc_jurisdiction_minimum_age', $baseline, $country, sanitize_key( $gender ) );
	return max( (int) $baseline, $jurisdiction );
}

function smc_normalize_phone( $value ) {
	$value = preg_replace( '/[\s().-]+/', '', trim( (string) $value ) );
	if ( ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $value ) ) {
		return new WP_Error( 'smc_phone', __( 'Use an international E.164 phone number, for example +923001234567.', 'sabri-membership-core' ) );
	}
	return $value;
}

function smc_application( $user_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'smc_applications';
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d LIMIT 1", absint( $user_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Return the persistent privacy-erasure lock for a WordPress account.
 *
 * The lock is intentionally stored outside the File 00 application tables so
 * an erased Founder or Administrator cannot become implicitly verified again
 * merely because their membership row no longer exists.
 *
 * @param int $user_id WordPress user ID.
 * @return array<string,mixed>|false
 */
function smc_privacy_erasure_lock( $user_id ) {
	$lock = get_user_meta( absint( $user_id ), '_smc_privacy_erasure_lock', true );
	return is_array( $lock ) && ! empty( $lock['locked_at'] ) ? $lock : false;
}

/**
 * Return the explicit membership state without conflating an absent application
 * with a real draft application.
 *
 * Founder, Administrator and the explicitly configured Institutional AI Teacher
 * are institutional authorities, not ordinary membership applications. Historic
 * draft/pending rows cannot silently cancel that identity; explicit disciplinary,
 * erasure or corrupt states remain controlling and fail closed.
 *
 * @param int $user_id WordPress user ID.
 * @return array<string,mixed>
 */
function smc_membership_state( $user_id ) {
	$user_id   = absint( $user_id );
	$row       = $user_id ? smc_application( $user_id ) : false;
	$raw_status = $row && isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
	$status    = $row && isset( smc_statuses()[ $raw_status ] ) ? $raw_status : '';
	$type      = $row && isset( $row['membership_type'] ) ? sanitize_key( $row['membership_type'] ) : '';
	$user      = $user_id ? get_userdata( $user_id ) : false;

	$is_founder    = $user_id > 0 && smc_is_founder( $user_id );
	$is_admin      = $user && user_can( $user, 'manage_options' );
	$is_ai         = $user_id > 0 && smc_is_institutional_ai( $user_id );
	$institutional = $is_founder || $is_admin || $is_ai;
	$account_class = smc_account_class_for_user( $user_id, $user );
	$hard_blocks   = array( 'rejected', 'suspended', 'expired', 'appeal_review', 'erasure_pending' );
	$erasure_lock = $user_id ? smc_privacy_erasure_lock( $user_id ) : false;
	$effects_hold = $user_id ? get_user_meta( $user_id, '_smc_membership_effects_hold_v1', true ) : false;

	if ( $effects_hold ) {
		return array( 'contract_version' => SMC_CONTRACT_VERSION, 'user_id' => $user_id, 'application_exists' => (bool) $row, 'application_status' => $status, 'status' => 'effects_reconciliation', 'membership_type' => $type, 'institutional_account' => (bool) $institutional, 'account_class' => $account_class, 'approved' => false );
	}

	if ( $erasure_lock ) {
		return array(
			'contract_version'      => SMC_CONTRACT_VERSION,
			'user_id'               => $user_id,
			'application_exists'    => (bool) $row,
			'application_status'    => 'erasure_pending',
			'status'                => 'erasure_pending',
			'membership_type'       => $type,
			'institutional_account' => (bool) $institutional,
			'account_class'         => $account_class,
			'approved'              => false,
		);
	}

	if ( $row && '' === $status ) {
		return array(
			'contract_version'      => SMC_CONTRACT_VERSION,
			'user_id'               => $user_id,
			'application_exists'    => true,
			'application_status'    => $raw_status,
			'status'                => 'invalid_application',
			'membership_type'       => $type,
			'institutional_account' => (bool) $institutional,
			'account_class'         => $account_class,
			'approved'              => false,
		);
	}

	if ( $institutional ) {
		if ( $status && in_array( $status, $hard_blocks, true ) ) {
			return array(
				'contract_version'      => SMC_CONTRACT_VERSION,
				'user_id'               => $user_id,
				'application_exists'    => (bool) $row,
				'application_status'    => $status,
				'status'                => $status,
				'membership_type'       => $type,
				'institutional_account' => true,
				'account_class'         => $account_class,
				'approved'              => false,
			);
		}

		return array(
			'contract_version'      => SMC_CONTRACT_VERSION,
			'user_id'               => $user_id,
			'application_exists'    => (bool) $row,
			'application_status'    => $status,
			'status'                => 'verified',
			'membership_type'       => $type,
			'institutional_account' => true,
			'account_class'         => $account_class,
			'approved'              => true,
		);
	}

	if ( $row && $status ) {
		return array(
			'contract_version'      => SMC_CONTRACT_VERSION,
			'user_id'               => $user_id,
			'application_exists'    => true,
			'application_status'    => $status,
			'status'                => $status,
			'membership_type'       => $type,
			'institutional_account' => false,
			'account_class'         => 'member',
			'approved'              => 'approved' === $status,
		);
	}

	return array(
		'contract_version'      => SMC_CONTRACT_VERSION,
		'user_id'               => $user_id,
		'application_exists'    => false,
		'application_status'    => '',
		'status'                => 'not_enrolled',
		'membership_type'       => '',
		'institutional_account' => false,
		'account_class'         => 'member',
		'approved'              => false,
	);
}

function smc_user_status( $user_id ) {
	$state = smc_membership_state( $user_id );
	return $state['status'];
}

function smc_founder_user_id() {
	$id = defined( 'SMC_FOUNDER_USER_ID' ) ? absint( SMC_FOUNDER_USER_ID ) : absint( get_option( 'smc_founder_user_id', 0 ) );
	return $id && get_userdata( $id ) ? $id : 0;
}

function smc_is_founder( $user_id ) {
	return $user_id > 0 && absint( $user_id ) === smc_founder_user_id();
}

function smc_page_url( $key, $fallback = '/' ) {
	$map = (array) get_option( 'smc_page_map', array() );
	$url = ! empty( $map[ $key ] ) && 'publish' === get_post_status( absint( $map[ $key ] ) )
		? get_permalink( absint( $map[ $key ] ) )
		: home_url( $fallback );
	return apply_filters( 'sabri_platform_route', $url, 'membership.' . sanitize_key( $key ) );
}

function smc_is_membership_page() {
	$page_id = get_queried_object_id();
	$map     = array_map( 'absint', (array) get_option( 'smc_page_map', array() ) );
	if ( $page_id && in_array( $page_id, $map, true ) ) {
		return true;
	}
	return (bool) apply_filters( 'smc_is_membership_page', false, $page_id );
}

function smc_required_identity_documents() {
	return array(
		'identity_front' => __( 'Identity document front', 'sabri-membership-core' ),
		'identity_back'  => __( 'Identity document back or passport identity page', 'sabri-membership-core' ),
		'identity_selfie'=> __( 'Live identity comparison photograph', 'sabri-membership-core' ),
	);
}

function smc_notice( $message, $type = 'info' ) {
	$allowed = array( 'info', 'success', 'warning', 'error' );
	$type    = in_array( $type, $allowed, true ) ? $type : 'info';
	return '<div class="smc-notice smc-notice--' . esc_attr( $type ) . '" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '">' . esc_html( $message ) . '</div>';
}

function smc_notify( $user_id, $type, $title, $body, $priority = 'high', $link = '' ) {
	$args = array(
		'user_id'    => absint( $user_id ),
		'category'   => 'administration',
		'type'       => sanitize_key( $type ),
		'priority'   => sanitize_key( $priority ),
		'title'      => sanitize_text_field( $title ),
		'body'       => sanitize_textarea_field( $body ),
		'link'       => esc_url_raw( $link ),
		'source'     => 'membership',
		'dedupe_key' => 'membership:' . sanitize_key( $type ) . ':' . absint( $user_id ) . ':' . gmdate( 'YmdHi' ),
	);
	if ( class_exists( 'SUN_Core' ) && is_callable( array( 'SUN_Core', 'create' ) ) ) {
		return (int) SUN_Core::create( $args ) > 0;
	}
	do_action( 'sabri_notify', $args );
	return has_action( 'sabri_notify' ) > 0;
}

function smc_db_error( $message ) {
	return new WP_Error( 'smc_database', $message );
}

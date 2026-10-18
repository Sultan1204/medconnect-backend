<?php

/*
|--------------------------------------------------------------------------
| MedConnect Saudi platform configuration
|--------------------------------------------------------------------------
|
| Every external integration defaults to a mock/manual driver. Switching a
| driver to a real provider requires a signed agreement, credentials and the
| regulatory approval recorded in docs/compliance/requirements-register.md.
|
*/

return [

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Riyadh'),

    'currency' => 'SAR',

    'supported_locales' => ['ar', 'en'],

    'auth' => [
        // Lifetime of a fully authenticated bearer token (minutes).
        'token_ttl_minutes' => (int) env('SANCTUM_TOKEN_EXPIRATION', 43200),

        // Short-lived token after password login while MFA is outstanding.
        'mfa_challenge_ttl_minutes' => (int) env('MFA_CHALLENGE_TTL', 10),
        'mfa_enrollment_ttl_minutes' => 30,

        // Roles that must complete TOTP multi-factor authentication.
        'mfa_required_roles' => ['doctor', 'pharmacy_staff', 'admin', 'super_admin'],

        // Per-account lockout after repeated password failures.
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,

        'mfa_recovery_codes' => 8,
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => 60,
        // HMAC pepper for OTP and destination hashes. Never reuse APP_KEY.
        'hash_key' => env('OTP_HASH_KEY'),
    ],

    'drivers' => [
        'sms' => env('SMS_DRIVER', 'log'),
        'push' => env('PUSH_DRIVER', 'log'),
        'video' => env('VIDEO_DRIVER', 'mock'),
        'payment' => env('PAYMENT_DRIVER', 'mock'),
        'prescription' => env('PRESCRIPTION_GATEWAY', 'mock'),
        // Must be a clinically validated drug knowledge base in production.
        'clinical_safety' => env('CLINICAL_SAFETY_CHECKER', 'mock'),
        'pharmacy' => env('PHARMACY_GATEWAY', 'mock'),
        'professional_verification' => env('PROFESSIONAL_VERIFICATION_DRIVER', 'manual'),
        'file_scanner' => env('FILE_SCANNER_DRIVER', 'mock'),
    ],

    'payments' => [
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        // Webhooks older/newer than this are rejected (replay protection).
        'webhook_tolerance_seconds' => 300,
        // Platform commission in basis points (2000 = 20%), snapshotted per payment.
        // ASSUMPTION: commercial terms to be confirmed with doctors' agreements.
        'commission_bps' => (int) env('PLATFORM_COMMISSION_BPS', 2000),
        // Execution of payouts to doctors stays disabled until the provider's
        // marketplace capabilities and the applicable regulations are confirmed.
        'payouts_enabled' => (bool) env('PAYOUTS_ENABLED', false),
    ],

    'files' => [
        'disk' => env('PRIVATE_FILES_DISK', 'private'),
        'max_upload_kb' => 10240,
        'credential_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'download_link_minutes' => 5,
    ],

    'patients' => [
        // Age at which a patient may consent to telemedicine services on their own.
        // ASSUMPTION - must be confirmed by Saudi legal counsel (see compliance register).
        'age_of_majority' => (int) env('PATIENT_AGE_OF_MAJORITY', 18),
        // Guardian links take effect only after an administrator verifies them.
        'guardian_links_require_verification' => (bool) env('GUARDIAN_LINKS_REQUIRE_VERIFICATION', true),
    ],

    // Current versions of consent documents. Bumping a version requires
    // patients to re-consent before the actions listed in `required_for_booking`.
    'consents' => [
        'versions' => [
            'terms_of_service' => '2026-10',
            'privacy_policy' => '2026-10',
            'telemedicine' => '2026-10',
            'pharmacy_data_sharing' => '2026-10',
        ],
        'required_for_booking' => ['terms_of_service', 'privacy_policy', 'telemedicine'],
    ],

    'doctors' => [
        // Bodies whose registrations are accepted. SCFHS = Saudi Commission for Health Specialties.
        'licensing_authorities' => ['SCFHS'],
        // Upper bound for a single consultation fee, in halalas (10,000 SAR).
        'max_fee_amount' => 1_000_000,
    ],

    'scheduling' => [
        // Paid bookings hold the slot this long while the patient pays.
        'payment_window_minutes' => 15,
        'max_active_appointments_per_patient' => 5,
        'no_show_grace_minutes' => 10,
        // Longest date range accepted by the availability endpoint.
        'availability_max_range_days' => 14,
    ],

    'consultations' => [
        // How early the doctor may start before the booked time.
        'early_start_minutes' => 10,
        // Allowed overrun beyond the booked duration (video sessions are capped at this).
        'overtime_grace_minutes' => 10,
        // Participants may keep messaging this long after completion (follow-up questions).
        'follow_up_messaging_hours' => 24,
        // A doctor keeps read access to the patient's record this long after the consultation.
        'care_access_days' => 30,
        'attachment_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'attachment_max_kb' => 10240,
    ],

    /*
    | The platform is NOT an emergency service. These values are shown to
    | patients and drive the red-flag screening at intake. ASSUMPTION: numbers
    | and questions must be validated by the clinical governance lead before
    | launch (see compliance register).
    */
    'emergency' => [
        'numbers' => [
            ['number' => '997', 'label_en' => 'Ambulance (Saudi Red Crescent)', 'label_ar' => 'الإسعاف (هيئة الهلال الأحمر السعودي)'],
            ['number' => '911', 'label_en' => 'Unified emergency number', 'label_ar' => 'رقم الطوارئ الموحد'],
        ],
        'screening_questions' => [
            'chest_pain' => ['en' => 'Severe chest pain or pressure', 'ar' => 'ألم أو ضغط شديد في الصدر'],
            'breathing_difficulty' => ['en' => 'Severe difficulty breathing', 'ar' => 'صعوبة شديدة في التنفس'],
            'stroke_signs' => ['en' => 'Sudden weakness, facial drooping or difficulty speaking', 'ar' => 'ضعف مفاجئ أو تدلي الوجه أو صعوبة في الكلام'],
            'severe_bleeding' => ['en' => 'Heavy bleeding that will not stop', 'ar' => 'نزيف شديد لا يتوقف'],
            'loss_of_consciousness' => ['en' => 'Fainting or loss of consciousness', 'ar' => 'إغماء أو فقدان الوعي'],
            'severe_allergic_reaction' => ['en' => 'Swelling of the face or throat after exposure to an allergen', 'ar' => 'تورم الوجه أو الحلق بعد التعرض لمادة مسببة للحساسية'],
            'self_harm' => ['en' => 'Thoughts of harming yourself or others', 'ar' => 'أفكار بإيذاء نفسك أو الآخرين'],
        ],
    ],

    'medical_records' => [
        // A booked doctor may review the patient's record this long before the appointment.
        'pre_access_hours' => 24,
    ],

    'prescriptions' => [
        // Validity after issuance when the e-prescribing service does not return one.
        // ASSUMPTION: confirm against the authorised service's rules.
        'default_validity_days' => 30,
        'max_items' => 10,
        // Accepted medication catalogue systems. "MOCK" is for development only.
        'catalog_systems' => ['SFDA', 'MOCK'],
        // Controlled / restricted medicines. Codes must come from verified Saudi
        // requirements (SFDA schedules); the default list is intentionally empty.
        'controlled_codes' => [],
        'controlled_allowed_via_telemedicine' => false,
        // Lets mock-issued (simulated) prescriptions flow to pharmacies in
        // local/test environments only. Ignored in production.
        'allow_simulated_dispensing' => (bool) env('ALLOW_SIMULATED_DISPENSING', true),
    ],

    'reviews' => [
        // Reviews wait for moderation before becoming public. Reviews that look like
        // they contain contact details are held for moderation regardless of this flag.
        'require_moderation' => (bool) env('REVIEWS_REQUIRE_MODERATION', true),
        // A completed consultation can be reviewed for this many days.
        'window_days' => 30,
        'comment_max_length' => 1000,
    ],

    'video' => [
        // Participant credential lifetime; clients request fresh credentials to reconnect.
        'token_ttl_minutes' => 10,
    ],

    'step_up' => [
        // After a successful step-up MFA check, further high-risk actions are
        // allowed without a new code for this many seconds.
        'grace_seconds' => 300,
    ],

];

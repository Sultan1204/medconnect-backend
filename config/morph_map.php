<?php

use App\Modules\Administration\Models\Complaint;
use App\Modules\Administration\Models\ComplaintEvent;
use App\Modules\Administration\Models\PlatformSetting;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Models\ConsultationEscalation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\DoctorCredential;
use App\Modules\Doctors\Models\DoctorVerification;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Models\User;
use App\Modules\MedicalRecords\Models\ClinicalNote;
use App\Modules\MedicalRecords\Models\ClinicalNoteVersion;
use App\Modules\MedicalRecords\Models\MedicalRecord;
use App\Modules\MedicalRecords\Models\PatientAllergy;
use App\Modules\MedicalRecords\Models\PatientMedication;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageAttachment;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientConsent;
use App\Modules\Patients\Models\PatientDataRequest;
use App\Modules\Patients\Models\PatientGuardian;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Pharmacies\Models\DispenseEvent;
use App\Modules\Pharmacies\Models\DispenseRequest;
use App\Modules\Pharmacies\Models\Pharmacy;
use App\Modules\Pharmacies\Models\PharmacyBranch;
use App\Modules\Pharmacies\Models\PharmacyStaff;
use App\Modules\Prescriptions\Models\Prescription;
use App\Modules\Prescriptions\Models\PrescriptionEvent;
use App\Modules\Prescriptions\Models\PrescriptionItem;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Models\ReviewReport;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AvailabilityRule;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use App\Modules\Settlements\Models\Payout;
use App\Modules\Settlements\Models\SettlementEntry;
use App\Modules\Specialties\Models\Specialty;
use App\Modules\Video\Models\VideoSession;
use App\Modules\Video\Models\VideoSessionEvent;

/*
|--------------------------------------------------------------------------
| Polymorphic type map
|--------------------------------------------------------------------------
| Every model referenced by a polymorphic column (roles, tokens, audit
| subjects, notifications...) must be listed. Keys are persisted - never
| rename them without a data migration.
*/

return [
    'user' => User::class,
    'otp_code' => OtpCode::class,
    'audit_log' => AuditLog::class,

    'specialty' => Specialty::class,

    'patient' => Patient::class,
    'patient_guardian' => PatientGuardian::class,
    'patient_consent' => PatientConsent::class,
    'patient_data_request' => PatientDataRequest::class,

    'doctor' => Doctor::class,
    'doctor_credential' => DoctorCredential::class,
    'doctor_verification' => DoctorVerification::class,

    'appointment' => Appointment::class,
    'availability_slot' => AvailabilitySlot::class,
    'availability_rule' => AvailabilityRule::class,
    'doctor_schedule_settings' => DoctorScheduleSettings::class,

    'consultation' => Consultation::class,
    'consultation_escalation' => ConsultationEscalation::class,

    'message' => Message::class,
    'message_attachment' => MessageAttachment::class,

    'video_session' => VideoSession::class,
    'video_session_event' => VideoSessionEvent::class,

    'medical_record' => MedicalRecord::class,
    'patient_allergy' => PatientAllergy::class,
    'patient_medication' => PatientMedication::class,
    'clinical_note' => ClinicalNote::class,
    'clinical_note_version' => ClinicalNoteVersion::class,

    'integration_event' => IntegrationEvent::class,
    'payment' => Payment::class,
    'payment_webhook_event' => PaymentWebhookEvent::class,
    'refund' => Refund::class,
    'settlement_entry' => SettlementEntry::class,
    'payout' => Payout::class,

    'prescription' => Prescription::class,
    'prescription_item' => PrescriptionItem::class,
    'prescription_event' => PrescriptionEvent::class,

    'pharmacy' => Pharmacy::class,
    'pharmacy_branch' => PharmacyBranch::class,
    'pharmacy_staff' => PharmacyStaff::class,
    'dispense_request' => DispenseRequest::class,
    'dispense_event' => DispenseEvent::class,

    'notification' => Notification::class,
    'notification_preference' => NotificationPreference::class,
    'notification_delivery' => NotificationDelivery::class,
    'push_device' => PushDevice::class,

    'review' => Review::class,
    'review_report' => ReviewReport::class,

    'complaint' => Complaint::class,
    'complaint_event' => ComplaintEvent::class,
    'platform_setting' => PlatformSetting::class,
];

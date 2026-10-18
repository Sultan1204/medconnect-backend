<?php

namespace App\Modules\Notifications\Enums;

use App\Modules\Notifications\Enums\NotificationCategory as Category;
use App\Modules\Notifications\Enums\NotificationChannel as Channel;

/**
 * Every notification the platform can send. The value doubles as the
 * translation key under lang/<locale>/notifications.php.
 *
 * Texts are deliberately generic: no clinical details, doctor names,
 * specialties or medication names appear in SMS, e-mail or push.
 */
enum NotificationType: string
{
    case AppointmentNewBooking = 'appointment.new_booking';
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentReminder = 'appointment.reminder';
    case AppointmentCancelled = 'appointment.cancelled';
    case PaymentConfirmed = 'payment.confirmed';
    case RefundSucceeded = 'refund.succeeded';
    case MessageReceived = 'message.received';
    case ConsultationStarted = 'consultation.started';
    case ConsultationEmergency = 'consultation.emergency';
    case PrescriptionStatusChanged = 'prescription.status_changed';
    case PharmacyRequestUpdated = 'pharmacy.request_updated';
    case PharmacyRequestReceived = 'pharmacy.request_received';
    case SecurityNewDevice = 'security.new_device';
    case SecurityAccountLocked = 'security.account_locked';
    case ComplaintUpdated = 'complaint.updated';
    case DataRequestUpdated = 'data_request.updated';

    public function category(): Category
    {
        return match ($this) {
            self::AppointmentNewBooking, self::AppointmentConfirmed,
            self::AppointmentReminder, self::AppointmentCancelled => Category::Appointments,
            self::PaymentConfirmed, self::RefundSucceeded => Category::Payments,
            self::MessageReceived => Category::Messages,
            self::ConsultationStarted, self::ConsultationEmergency => Category::Consultations,
            self::PrescriptionStatusChanged => Category::Prescriptions,
            self::PharmacyRequestUpdated, self::PharmacyRequestReceived => Category::Pharmacy,
            self::SecurityNewDevice, self::SecurityAccountLocked => Category::Security,
            self::ComplaintUpdated, self::DataRequestUpdated => Category::Support,
        };
    }

    /**
     * Outbound channels used unless the user changed their preferences. The
     * in-app feed entry is always created.
     *
     * @return list<Channel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::AppointmentNewBooking => [Channel::Push, Channel::Email],
            self::AppointmentConfirmed, self::AppointmentCancelled => [Channel::Sms, Channel::Email, Channel::Push],
            self::AppointmentReminder, self::ConsultationStarted => [Channel::Sms, Channel::Push],
            self::PaymentConfirmed, self::RefundSucceeded => [Channel::Email, Channel::Push],
            // Chat content never leaves the platform; the alert is a push only.
            self::MessageReceived => [Channel::Push],
            self::ConsultationEmergency => [Channel::Sms, Channel::Push],
            self::PrescriptionStatusChanged => [Channel::Email, Channel::Push],
            self::PharmacyRequestUpdated => [Channel::Sms, Channel::Push],
            self::PharmacyRequestReceived => [Channel::Push],
            self::SecurityNewDevice, self::SecurityAccountLocked => [Channel::Sms, Channel::Email],
            self::ComplaintUpdated, self::DataRequestUpdated => [Channel::Email, Channel::Push],
        };
    }
}

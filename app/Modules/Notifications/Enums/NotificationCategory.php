<?php

namespace App\Modules\Notifications\Enums;

enum NotificationCategory: string
{
    case Appointments = 'appointments';
    case Payments = 'payments';
    case Messages = 'messages';
    case Consultations = 'consultations';
    case Prescriptions = 'prescriptions';
    case Pharmacy = 'pharmacy';
    case Security = 'security';
    case Support = 'support';

    /**
     * Mandatory categories cannot be switched off: a user must always learn about
     * security events on their account.
     */
    public function isMandatory(): bool
    {
        return $this === self::Security;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}

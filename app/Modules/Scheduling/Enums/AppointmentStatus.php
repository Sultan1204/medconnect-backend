<?php

namespace App\Modules\Scheduling\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NoShow = 'no_show';

    /**
     * Statuses that hold the time slot (mirrors the DB exclusion constraints).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::AwaitingPayment, self::Confirmed, self::InProgress];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::AwaitingPayment, self::Confirmed], true);
    }
}

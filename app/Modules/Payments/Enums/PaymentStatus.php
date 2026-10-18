<?php

namespace App\Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    /** Provider reported something inconsistent (e.g. amount mismatch) - needs a human. */
    case ReviewRequired = 'review_required';

    public function isCaptured(): bool
    {
        return in_array($this, [self::Captured, self::PartiallyRefunded, self::Refunded], true);
    }
}

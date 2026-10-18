<?php

namespace App\Modules\Pharmacies\Enums;

enum DispenseStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case UnderReview = 'under_review';
    case Ready = 'ready';
    case Dispensed = 'dispensed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Transitions pharmacy staff may perform.
     *
     * @return list<self>
     */
    public function staffTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Received, self::UnderReview, self::Rejected],
            self::Received => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Ready, self::Rejected],
            self::Ready => [self::Dispensed, self::Rejected],
            default => [],
        };
    }

    public function isPatientCancellable(): bool
    {
        return in_array($this, [self::Requested, self::Received, self::UnderReview, self::Ready], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Received, self::UnderReview, self::Ready], true);
    }
}

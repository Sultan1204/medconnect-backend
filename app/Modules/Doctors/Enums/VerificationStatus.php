<?php

namespace App\Modules\Doctors\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Expired = 'expired';

    /** Statuses from which the doctor may (re)submit for review. */
    public function canSubmit(): bool
    {
        return in_array($this, [self::Pending, self::Rejected, self::Expired], true);
    }
}

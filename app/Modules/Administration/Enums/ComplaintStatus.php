<?php

namespace App\Modules\Administration\Enums;

enum ComplaintStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function isClosed(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected], true);
    }
}

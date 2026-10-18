<?php

namespace App\Modules\Reviews\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    /** Taken down after publication (kept for the record, excluded from ratings). */
    case Hidden = 'hidden';
    case Rejected = 'rejected';
}

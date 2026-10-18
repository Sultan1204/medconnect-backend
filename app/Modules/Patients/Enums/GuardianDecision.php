<?php

namespace App\Modules\Patients\Enums;

enum GuardianDecision: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Revoke = 'revoke';
}

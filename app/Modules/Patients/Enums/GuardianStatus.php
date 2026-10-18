<?php

namespace App\Modules\Patients\Enums;

enum GuardianStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}

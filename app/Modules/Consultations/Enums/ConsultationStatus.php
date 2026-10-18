<?php

namespace App\Modules\Consultations\Enums;

enum ConsultationStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';
}

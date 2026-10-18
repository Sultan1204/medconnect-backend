<?php

namespace App\Modules\Notifications\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}

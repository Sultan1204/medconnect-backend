<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsProvider;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Development driver: writes SMS to the application log so OTPs can be read
 * locally. Refuses to run in production, where it would leak secrets to logs.
 */
class LogSmsProvider implements SmsProvider
{
    public function send(string $to, string $message): string
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The log SMS driver must not be used in production.');
        }

        $reference = 'log-'.Str::uuid();
        Log::channel(config('logging.default'))->info('[dev-sms] message', [
            'to' => PhoneNumber::mask($to),
            'body' => $message,
            'reference' => $reference,
        ]);

        return $reference;
    }
}

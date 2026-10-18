<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Outbound SMS gateway. Implementations must:
 *  - never log message bodies in production (they may contain OTPs),
 *  - throw on delivery failure so queued jobs can retry and record it.
 */
interface SmsProvider
{
    /**
     * @param  string  $to  E.164 phone number
     * @return string Provider message reference
     */
    public function send(string $to, string $message): string;
}

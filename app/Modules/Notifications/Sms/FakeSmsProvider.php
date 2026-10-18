<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsProvider;
use Illuminate\Support\Str;

/**
 * In-memory driver for automated tests. Registered as a singleton so tests
 * can inspect what would have been sent.
 */
class FakeSmsProvider implements SmsProvider
{
    /** @var list<array{to: string, message: string, reference: string}> */
    public array $sent = [];

    public function send(string $to, string $message): string
    {
        $reference = 'fake-'.Str::uuid();
        $this->sent[] = ['to' => $to, 'message' => $message, 'reference' => $reference];

        return $reference;
    }

    /** @return list<array{to: string, message: string, reference: string}> */
    public function sentTo(string $to): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['to'] === $to));
    }

    /**
     * Extracts the most recent numeric code sent to a number (test helper).
     */
    public function lastCodeFor(string $to): ?string
    {
        $messages = $this->sentTo($to);
        $last = end($messages);

        return $last && preg_match('/\b(\d{6})\b/', $last['message'], $m) ? $m[1] : null;
    }
}

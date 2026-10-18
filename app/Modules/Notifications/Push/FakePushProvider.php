<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushProvider;
use App\Modules\Notifications\Exceptions\InvalidPushToken;
use Illuminate\Support\Str;
use RuntimeException;

/** In-memory driver for automated tests. */
class FakePushProvider implements PushProvider
{
    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
    public array $sent = [];

    /** @var list<string> tokens the "provider" rejects as unregistered */
    public array $invalidTokens = [];

    /** Simulates a provider outage. */
    public bool $failing = false;

    public function send(string $token, string $title, string $body, array $data = []): string
    {
        if ($this->failing) {
            throw new RuntimeException('Push provider unavailable.');
        }

        if (in_array($token, $this->invalidTokens, true)) {
            throw new InvalidPushToken('Unregistered device token.');
        }

        $this->sent[] = ['token' => $token, 'title' => $title, 'body' => $body, 'data' => $data];

        return 'fake-'.Str::uuid();
    }
}

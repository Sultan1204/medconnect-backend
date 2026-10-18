<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/** Development driver: logs that a push would have been sent (never the token). */
class LogPushProvider implements PushProvider
{
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The log push driver must not be used in production.');
        }

        $reference = 'log-'.Str::uuid();
        Log::info('[dev-push] notification', ['title' => $title, 'data' => $data, 'reference' => $reference]);

        return $reference;
    }
}

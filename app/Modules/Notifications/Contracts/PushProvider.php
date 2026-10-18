<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Exceptions\InvalidPushToken;

/**
 * Mobile/web push gateway (FCM/APNs or a Saudi-hosted equivalent). Payloads
 * must carry no clinical content - only a generic title/body and opaque ids.
 */
interface PushProvider
{
    /**
     * @param  array<string, string>  $data  opaque identifiers for deep links
     * @return string Provider message reference
     *
     * @throws InvalidPushToken when the device token is no longer valid
     */
    public function send(string $token, string $title, string $body, array $data = []): string;
}

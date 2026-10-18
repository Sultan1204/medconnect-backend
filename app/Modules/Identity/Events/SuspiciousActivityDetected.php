<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Security signal (new-device sign-in, lockout, ...). The Notifications module
 * informs the account holder; security monitoring can subscribe as well.
 */
class SuspiciousActivityDetected
{
    use Dispatchable;

    public const NEW_DEVICE = 'new_device_login';

    public const ACCOUNT_LOCKED = 'account_locked';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly User $user,
        public readonly string $type,
        public readonly array $context = [],
    ) {}
}

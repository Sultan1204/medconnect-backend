<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after self-registration commits. Profile modules (Patients, Doctors)
 * listen to create their records, keeping Identity independent of them.
 */
class UserRegistered
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $profile  validated, module-specific registration fields
     */
    public function __construct(
        public readonly User $user,
        public readonly string $accountType,
        public readonly array $profile = [],
    ) {}
}

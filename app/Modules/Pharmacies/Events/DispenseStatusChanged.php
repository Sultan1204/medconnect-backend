<?php

namespace App\Modules\Pharmacies\Events;

use App\Modules\Identity\Models\User;
use App\Modules\Pharmacies\Enums\DispenseStatus;
use App\Modules\Pharmacies\Models\DispenseRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dispense request was created or moved to a new status. `$actor` is null
 * for system changes (e.g. the prescription was cancelled).
 */
class DispenseStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly DispenseRequest $request,
        public readonly ?DispenseStatus $from,
        public readonly DispenseStatus $to,
        public readonly ?User $actor,
    ) {}
}

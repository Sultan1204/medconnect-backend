<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The provider confirmed the refund. Settlements reverses earnings if they
 * were already recorded.
 */
class RefundSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Refund $refund) {}
}

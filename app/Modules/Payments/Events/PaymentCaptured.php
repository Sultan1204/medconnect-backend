<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentCaptured
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}

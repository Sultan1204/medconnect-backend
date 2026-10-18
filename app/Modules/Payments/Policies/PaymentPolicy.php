<?php

namespace App\Modules\Payments\Policies;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\Gate;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $payment->payer_user_id === $user->id
            || Gate::forUser($user)->allows('actFor', Patient::withTrashed()->find($payment->patient_id))
            || $user->can(Permission::PaymentsView->value);
    }
}

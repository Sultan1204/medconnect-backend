<?php

namespace App\Modules\Settlements\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Settlements\Models\Payout;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Payout *records* can be prepared at any time. *Executing* a payout moves
 * money and stays disabled (PAYOUTS_ENABLED=false) until the payment
 * provider's marketplace capabilities and the applicable Saudi regulatory
 * requirements have been confirmed.
 */
class PayoutService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Doctor $doctor, int $amount, User $admin): Payout
    {
        return DB::transaction(function () use ($doctor, $amount, $admin) {
            // Serialise payout requests per doctor so the balance check holds.
            Doctor::whereKey($doctor->id)->lockForUpdate()->first();

            $available = $this->ledger->availableForPayout($doctor->id);
            if ($amount <= 0 || $amount > $available) {
                throw ApiException::make('payout_exceeds_balance', 'The payout exceeds the available balance.', 422, ['available' => $available]);
            }

            $payout = Payout::create([
                'doctor_id' => $doctor->id,
                'amount' => $amount,
                'currency' => $doctor->currency,
                'requested_by_user_id' => $admin->id,
            ]);

            $this->audit->record('settlements.payout_created', $payout, ['actor' => $admin, 'metadata' => ['amount' => $amount]]);

            return $payout;
        });
    }

    /**
     * Records that the payout was transferred (e.g. by finance, with the bank reference).
     */
    public function execute(Payout $payout, User $admin, string $reference): Payout
    {
        if (! config('medconnect.payments.payouts_enabled')) {
            throw ApiException::conflict('payouts_disabled', 'Payout execution is disabled pending provider and regulatory confirmation.');
        }

        return DB::transaction(function () use ($payout, $admin, $reference) {
            /** @var Payout $locked */
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ApiException::conflict('invalid_state_transition', "The payout is {$locked->status}.");
            }

            $locked->forceFill([
                'status' => 'paid',
                'executed_at' => now(),
                'executed_by_user_id' => $admin->id,
                'provider_payout_id' => $reference,
            ])->save();

            $this->ledger->recordPayout($locked);
            $this->audit->record('settlements.payout_executed', $locked, ['actor' => $admin, 'metadata' => ['amount' => $locked->amount]]);

            return $locked;
        });
    }

    public function cancel(Payout $payout, User $admin): Payout
    {
        if ($payout->status !== 'pending') {
            throw ApiException::conflict('invalid_state_transition', "The payout is {$payout->status}.");
        }

        $payout->forceFill(['status' => 'cancelled'])->save();
        $this->audit->record('settlements.payout_cancelled', $payout, ['actor' => $admin]);

        return $payout;
    }
}

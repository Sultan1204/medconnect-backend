<?php

namespace App\Modules\Settlements\Services;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Settlements\Models\Payout;
use App\Modules\Settlements\Models\SettlementEntry;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Commission and earnings ledger. Every financial fact is a new immutable
 * row; balances are always computed by summing.
 *
 *  consultation completed  → doctor_payable  +(net - commission)
 *                            platform_revenue +commission
 *  refund after completion → proportional negative reversals on both
 *  payout paid             → doctor_payable  -amount
 */
class Ledger
{
    /**
     * Earnings are recognised when the service is delivered (consultation
     * completed), on the amount still captured at that time.
     */
    public function recordEarning(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            /** @var Payment|null $payment */
            $payment = Payment::where('appointment_id', $appointment->id)
                ->whereIn('status', ['captured', 'partially_refunded'])
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return; // free consultation, or fully refunded
            }

            $net = $payment->amount - $payment->refunded_amount;
            [$doctorShare, $commission] = Money::split($net, $payment->commission_rate_bps);

            try {
                DB::transaction(function () use ($payment, $appointment, $doctorShare, $commission) {
                    $this->entry(SettlementEntry::DOCTOR, $appointment->doctor_id, 'consultation_earning', $doctorShare, $payment, $appointment);
                    $this->entry(SettlementEntry::PLATFORM, null, 'platform_commission', $commission, $payment, $appointment);
                });
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e; // anything but "already recorded"
                }
            }
        });
    }

    /**
     * A refund after earnings were recorded reverses both sides
     * proportionally. Refunds before completion need no entry: the earning
     * is later computed on the net amount.
     */
    public function recordRefund(Refund $refund): void
    {
        DB::transaction(function () use ($refund) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();

            $earned = SettlementEntry::where('payment_id', $payment->id)->where('type', 'consultation_earning')->first();
            if ($earned === null || SettlementEntry::where('refund_id', $refund->id)->exists()) {
                return;
            }

            [$doctorShare, $commission] = Money::split($refund->amount, $payment->commission_rate_bps);
            $appointment = Appointment::findOrFail($payment->appointment_id);

            $this->entry(SettlementEntry::DOCTOR, $earned->doctor_id, 'refund_reversal', -$doctorShare, $payment, $appointment, $refund);
            $this->entry(SettlementEntry::PLATFORM, null, 'commission_reversal', -$commission, $payment, $appointment, $refund);
        });
    }

    public function recordPayout(Payout $payout): void
    {
        SettlementEntry::create([
            'account' => SettlementEntry::DOCTOR,
            'doctor_id' => $payout->doctor_id,
            'type' => 'payout',
            'amount' => -$payout->amount,
            'currency' => $payout->currency,
            'payout_id' => $payout->id,
            'description' => 'Payout '.$payout->id,
        ]);
    }

    public function doctorBalance(string $doctorId): int
    {
        return (int) SettlementEntry::where('account', SettlementEntry::DOCTOR)->where('doctor_id', $doctorId)->sum('amount');
    }

    /** Balance minus payouts already requested but not yet paid. */
    public function availableForPayout(string $doctorId): int
    {
        $reserved = (int) Payout::where('doctor_id', $doctorId)->whereIn('status', ['pending', 'processing'])->sum('amount');

        return $this->doctorBalance($doctorId) - $reserved;
    }

    public function platformRevenue(): int
    {
        return (int) SettlementEntry::where('account', SettlementEntry::PLATFORM)->sum('amount');
    }

    private function entry(string $account, ?string $doctorId, string $type, int $amount, Payment $payment, Appointment $appointment, ?Refund $refund = null): void
    {
        SettlementEntry::create([
            'account' => $account,
            'doctor_id' => $doctorId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $payment->currency,
            'appointment_id' => $appointment->id,
            'payment_id' => $payment->id,
            'refund_id' => $refund?->id,
            'description' => str_replace('_', ' ', $type).' for appointment '.$appointment->id,
        ]);
    }
}

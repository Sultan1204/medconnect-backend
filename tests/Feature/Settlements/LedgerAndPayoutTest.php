<?php

namespace Tests\Feature\Settlements;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Settlements\Models\SettlementEntry;
use App\Modules\Settlements\Services\Ledger;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class LedgerAndPayoutTest extends TestCase
{
    use ConsultationFixtures;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function paidConsultation(int $amount = 15000): Consultation
    {
        $consultation = $this->activeConsultation();
        $appointment = $consultation->appointment;

        $payment = Payment::create([
            'appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id, 'payer_user_id' => $consultation->patient->user_id,
            'amount' => $amount, 'currency' => 'SAR', 'commission_rate_bps' => 2000, 'status' => PaymentStatus::Captured,
            'provider' => 'mock', 'idempotency_key' => 'ledger-'.$appointment->id,
        ]);
        $payment->forceFill(['provider_payment_id' => 'mock_'.$payment->id, 'captured_at' => now()])->save();

        return $consultation;
    }

    private function complete(Consultation $consultation): void
    {
        app(ConsultationLifecycle::class)->complete($consultation, $consultation->doctor->user);
    }

    /** @return array<string, string> */
    private function stepUp(User $admin): array
    {
        $admin->forceFill(['mfa_last_timestep' => null])->save();

        return ['X-MFA-Code' => app(MfaService::class)->currentCode(self::SECRET)];
    }

    public function test_completion_records_doctor_earning_and_platform_commission(): void
    {
        $consultation = $this->paidConsultation();
        $this->complete($consultation);

        $ledger = app(Ledger::class);
        $this->assertSame(12000, $ledger->doctorBalance($consultation->doctor_id));
        $this->assertSame(3000, $ledger->platformRevenue());

        // Re-delivery of the completion event never double-counts.
        $ledger->recordEarning($consultation->appointment);
        $this->assertSame(2, SettlementEntry::count());
    }

    public function test_free_consultations_create_no_ledger_entries(): void
    {
        $consultation = $this->activeConsultation();
        $this->complete($consultation);

        $this->assertSame(0, SettlementEntry::count());
    }

    public function test_refund_after_completion_reverses_proportionally(): void
    {
        $consultation = $this->paidConsultation();
        $this->complete($consultation);
        $payment = Payment::where('appointment_id', $consultation->appointment_id)->firstOrFail();

        app(RefundService::class)->refund($payment, 5000, 'dispute', 'dispute-1');

        $this->assertSame(12000 - 4000, app(Ledger::class)->doctorBalance($consultation->doctor_id));
        $this->assertSame(3000 - 1000, app(Ledger::class)->platformRevenue());

        // Originals untouched; reversals are new rows linked to the refund.
        $this->assertSame(4, SettlementEntry::count());
        $this->assertSame(['commission_reversal', 'refund_reversal'], SettlementEntry::whereNotNull('refund_id')->orderBy('type')->pluck('type')->all());
    }

    public function test_refund_before_completion_reduces_the_earning_basis(): void
    {
        $consultation = $this->paidConsultation();
        $payment = Payment::where('appointment_id', $consultation->appointment_id)->firstOrFail();
        app(RefundService::class)->refund($payment, 5000, 'service_issue', 'pre-1');

        $this->complete($consultation);

        $this->assertSame(8000, app(Ledger::class)->doctorBalance($consultation->doctor_id)); // 80% of 10000
        $this->assertSame(0, SettlementEntry::whereNotNull('refund_id')->count());
    }

    public function test_over_refunding_is_impossible(): void
    {
        $consultation = $this->paidConsultation();
        $payment = Payment::where('appointment_id', $consultation->appointment_id)->firstOrFail();
        app(RefundService::class)->refund($payment, 10000, 'admin', 'r-1');

        $this->expectException(ApiException::class);
        app(RefundService::class)->refund($payment, 5001, 'admin', 'r-2');
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $consultation = $this->paidConsultation();
        $this->complete($consultation);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only ledger');
        DB::transaction(fn () => DB::table('settlement_entries')->update(['amount' => 999999]));
    }

    public function test_doctor_sees_own_earnings(): void
    {
        $consultation = $this->paidConsultation();
        $this->complete($consultation);

        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $this->getJson('/api/v1/doctors/me/earnings')->assertOk()
            ->assertJsonPath('data.balance', 12000)
            ->assertJsonPath('data.available', 12000)
            ->assertJsonPath('data.entries.0.type', 'consultation_earning');
    }

    public function test_payouts_respect_balance_and_stay_disabled_until_authorised(): void
    {
        $consultation = $this->paidConsultation();
        $this->complete($consultation);
        $admin = User::factory()->admin()->withMfa(self::SECRET)->create();
        Sanctum::actingAs($admin, ['*']);

        $this->assertErrorCode($this->postJson('/api/v1/admin/payouts', ['doctor_id' => $consultation->doctor_id, 'amount' => 12001], $this->stepUp($admin)), 422, 'payout_exceeds_balance');

        $payoutId = $this->postJson('/api/v1/admin/payouts', ['doctor_id' => $consultation->doctor_id, 'amount' => 10000], $this->stepUp($admin))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(2000, app(Ledger::class)->availableForPayout($consultation->doctor_id));

        $this->assertErrorCode($this->postJson("/api/v1/admin/payouts/{$payoutId}/execute", ['transfer_reference' => 'BANK-1'], $this->stepUp($admin)), 409, 'payouts_disabled');

        config(['medconnect.payments.payouts_enabled' => true]);
        $this->postJson("/api/v1/admin/payouts/{$payoutId}/execute", ['transfer_reference' => 'BANK-1'], $this->stepUp($admin))
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(2000, app(Ledger::class)->doctorBalance($consultation->doctor_id));
    }

    public function test_admin_refunds_require_permission_and_step_up(): void
    {
        $consultation = $this->paidConsultation();
        $payment = Payment::where('appointment_id', $consultation->appointment_id)->firstOrFail();

        $this->actingAsRole(RoleName::Doctor);
        $this->assertErrorCode($this->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => 100, 'reason' => 'admin', 'note' => 'x']), 403, 'forbidden');

        $admin = User::factory()->admin()->withMfa(self::SECRET)->create();
        Sanctum::actingAs($admin, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => 100, 'reason' => 'admin', 'note' => 'x']), 403, 'step_up_required');

        $this->postJson("/api/v1/admin/payments/{$payment->id}/refunds", ['amount' => 2500, 'reason' => 'service_issue', 'note' => 'Video quality complaint'], $this->stepUp($admin))
            ->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.payment.refunded_amount', 2500);

        $this->assertDatabaseHas('audit_logs', ['action' => 'payments.refund_requested', 'actor_id' => $admin->id]);
    }
}

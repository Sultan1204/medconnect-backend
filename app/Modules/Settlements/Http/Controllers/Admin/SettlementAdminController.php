<?php

namespace App\Modules\Settlements\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Settlements\Models\Payout;
use App\Modules\Settlements\Models\SettlementEntry;
use App\Modules\Settlements\Services\Ledger;
use App\Modules\Settlements\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @tags Admin - Settlements
 */
class SettlementAdminController extends Controller
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly PayoutService $payouts,
    ) {}

    /**
     * Doctor balances and platform revenue.
     */
    public function balances(): JsonResponse
    {
        $rows = SettlementEntry::query()
            ->where('account', SettlementEntry::DOCTOR)
            ->groupBy('doctor_id')
            ->select('doctor_id', DB::raw('SUM(amount) AS balance'))
            ->get();

        return response()->json(['data' => [
            'platform_revenue' => $this->ledger->platformRevenue(),
            'doctors' => $rows->map(fn ($row) => [
                'doctor_id' => $row->getAttribute('doctor_id'),
                'balance' => (int) $row->getAttribute('balance'),
                'available' => $this->ledger->availableForPayout((string) $row->getAttribute('doctor_id')),
            ]),
            'payouts_enabled' => (bool) config('medconnect.payments.payouts_enabled'),
        ]]);
    }

    /**
     * Prepare a payout (requires step-up MFA).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['doctor_id' => ['required', 'uuid'], 'amount' => ['required', 'integer', 'min:1']]);
        $payout = $this->payouts->create(Doctor::findOrFail($data['doctor_id']), $data['amount'], $request->user());

        return response()->json(['data' => $payout->only(['id', 'doctor_id', 'amount', 'currency', 'status'])], 201);
    }

    /**
     * Execute a payout (requires step-up MFA; disabled until authorised).
     */
    public function execute(Request $request, Payout $payout): JsonResponse
    {
        $reference = $request->validate(['transfer_reference' => ['required', 'string', 'max:200']])['transfer_reference'];

        return response()->json(['data' => $this->payouts->execute($payout, $request->user(), $reference)->only(['id', 'status', 'executed_at'])]);
    }

    /**
     * Cancel a pending payout.
     */
    public function cancel(Request $request, Payout $payout): JsonResponse
    {
        return response()->json(['data' => $this->payouts->cancel($payout, $request->user())->only(['id', 'status'])]);
    }
}

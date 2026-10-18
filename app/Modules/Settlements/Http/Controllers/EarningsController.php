<?php

namespace App\Modules\Settlements\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Http\Controllers\DoctorProfileController;
use App\Modules\Settlements\Models\Payout;
use App\Modules\Settlements\Models\SettlementEntry;
use App\Modules\Settlements\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Doctors - Earnings
 */
class EarningsController extends Controller
{
    /**
     * My earnings and ledger.
     *
     * `balance` is the sum of all ledger entries; `available` excludes payouts
     * already requested. Amounts in halalas.
     */
    public function index(Request $request, Ledger $ledger): JsonResponse
    {
        $doctor = DoctorProfileController::own($request->user());

        $entries = SettlementEntry::where('account', SettlementEntry::DOCTOR)
            ->where('doctor_id', $doctor->id)
            ->latest('created_at')
            ->paginate(50);

        return response()->json([
            'data' => [
                'currency' => $doctor->currency,
                'balance' => $ledger->doctorBalance($doctor->id),
                'available' => $ledger->availableForPayout($doctor->id),
                'entries' => collect($entries->items())->map(fn (SettlementEntry $e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'amount' => $e->amount,
                    'appointment_id' => $e->appointment_id,
                    'created_at' => $e->created_at->toIso8601ZuluString(),
                ]),
            ],
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
        ]);
    }

    /**
     * My payouts.
     */
    public function payouts(Request $request): JsonResponse
    {
        $doctor = DoctorProfileController::own($request->user());

        return response()->json(['data' => Payout::where('doctor_id', $doctor->id)->latest()->get()->map(fn (Payout $p) => [
            'id' => $p->id,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'executed_at' => $p->executed_at?->toIso8601ZuluString(),
            'created_at' => $p->created_at->toIso8601ZuluString(),
        ])]);
    }
}

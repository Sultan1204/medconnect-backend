<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Http\Requests\AvailabilityQueryRequest;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * @tags Scheduling - Availability
 */
class AvailabilityController extends Controller
{
    /**
     * Bookable slots for a doctor.
     *
     * `from`/`to` are local dates (Asia/Riyadh), at most 14 days apart.
     * Each slot's `starts_at` (UTC) is what you send to `POST /appointments`.
     *
     * @unauthenticated
     */
    public function __invoke(AvailabilityQueryRequest $request, string $doctor, AvailabilityService $availability): JsonResponse
    {
        $doctor = Doctor::publiclyListed()->findOrFail($doctor);
        $tz = (string) config('medconnect.display_timezone');

        $from = CarbonImmutable::parse($request->validated('from', CarbonImmutable::now($tz)->toDateString()), $tz);
        $to = CarbonImmutable::parse($request->validated('to', $from->addDays(6)->toDateString()), $tz);
        $type = $request->validated('consultation_type');

        $slots = $availability->slots($doctor, $from, $to, $type);

        return response()->json([
            'data' => array_map(fn (Slot $slot) => $slot->toArray() + [
                'fees' => array_map(fn (string $t) => ['type' => $t, 'amount' => $doctor->feeFor($t), 'currency' => $doctor->currency],
                    array_values(array_filter($slot->consultationTypes, fn (string $t) => $doctor->feeFor($t) !== null))),
            ], $slots),
            'meta' => ['timezone' => $tz, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}

<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Doctors\Http\Controllers\DoctorProfileController;
use App\Modules\Scheduling\Http\Requests\AvailabilitySlotRequest;
use App\Modules\Scheduling\Http\Requests\ReplaceAvailabilityRulesRequest;
use App\Modules\Scheduling\Http\Requests\ScheduleSettingsRequest;
use App\Modules\Scheduling\Http\Resources\AvailabilityRuleResource;
use App\Modules\Scheduling\Http\Resources\AvailabilitySlotResource;
use App\Modules\Scheduling\Models\AvailabilityRule;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\DoctorScheduleSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in doctor's schedule. Existing appointments are never changed
 * by schedule edits; cancel them explicitly if needed.
 *
 * @tags Scheduling - My schedule
 */
class DoctorScheduleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Weekly availability rules.
     */
    public function rules(Request $request): AnonymousResourceCollection
    {
        $doctor = DoctorProfileController::own($request->user());

        return AvailabilityRuleResource::collection(
            AvailabilityRule::where('doctor_id', $doctor->id)->orderBy('day_of_week')->orderBy('start_time')->get(),
        );
    }

    /**
     * Replace weekly availability rules.
     *
     * Times are local (Asia/Riyadh). Send an empty `rules` array to clear the schedule.
     */
    public function replaceRules(ReplaceAvailabilityRulesRequest $request): AnonymousResourceCollection
    {
        $doctor = DoctorProfileController::own($request->user());

        DB::transaction(function () use ($doctor, $request) {
            AvailabilityRule::where('doctor_id', $doctor->id)->delete();

            foreach ($request->validated('rules') as $rule) {
                AvailabilityRule::create([
                    'doctor_id' => $doctor->id,
                    'day_of_week' => $rule['day_of_week'],
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'slot_minutes' => $rule['slot_minutes'],
                    'breaks' => $rule['breaks'] ?? [],
                    'consultation_types' => $rule['consultation_types'],
                    'valid_from' => $rule['valid_from'] ?? null,
                    'valid_until' => $rule['valid_until'] ?? null,
                ]);
            }

            $this->audit->record('scheduling.rules_replaced', $doctor, ['metadata' => ['count' => count($request->validated('rules'))]]);
        });

        return $this->rules($request);
    }

    /**
     * Upcoming date-specific availability and time off.
     */
    public function slots(Request $request): AnonymousResourceCollection
    {
        $doctor = DoctorProfileController::own($request->user());

        return AvailabilitySlotResource::collection(
            AvailabilitySlot::where('doctor_id', $doctor->id)->where('ends_at', '>', now())->orderBy('starts_at')->get(),
        );
    }

    /**
     * Add extra availability or time off.
     */
    public function storeSlot(AvailabilitySlotRequest $request): AvailabilitySlotResource
    {
        $doctor = DoctorProfileController::own($request->user());
        $slot = AvailabilitySlot::create(['doctor_id' => $doctor->id] + $request->validated());
        $this->audit->record('scheduling.slot_added', $slot, ['metadata' => ['type' => $slot->type]]);

        return new AvailabilitySlotResource($slot);
    }

    /**
     * Remove extra availability or time off.
     */
    public function destroySlot(Request $request, string $slot): JsonResponse
    {
        $doctor = DoctorProfileController::own($request->user());
        $model = AvailabilitySlot::where('doctor_id', $doctor->id)->findOrFail($slot);
        $model->delete();
        $this->audit->record('scheduling.slot_removed', $model);

        return response()->json(['data' => ['status' => 'removed']]);
    }

    /**
     * Booking and cancellation settings.
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => DoctorScheduleSettings::for(DoctorProfileController::own($request->user()))->only([
            'min_notice_minutes', 'max_days_ahead', 'buffer_minutes', 'max_daily_appointments',
            'auto_confirm', 'free_cancellation_hours', 'late_cancellation_refund_percent',
        ])]);
    }

    /**
     * Update booking and cancellation settings.
     *
     * Cancellation-policy changes apply to new bookings only; existing
     * appointments keep the policy agreed at booking time.
     */
    public function updateSettings(ScheduleSettingsRequest $request): JsonResponse
    {
        $doctor = DoctorProfileController::own($request->user());
        $settings = DoctorScheduleSettings::for($doctor);
        $settings->fill($request->validated())->save();
        $this->audit->record('scheduling.settings_updated', $doctor, ['changes' => AuditLogger::changedFields($settings)]);

        return $this->settings($request);
    }
}

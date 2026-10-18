<?php

namespace App\Modules\Consultations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Events\EmergencyEscalated;
use App\Modules\Consultations\Http\Resources\ConsultationResource;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Models\ConsultationEscalation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Consultations\Services\EmergencyScreening;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @tags Consultations
 */
class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationLifecycle $lifecycle,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Consultation detail (participants only).
     */
    public function show(Request $request, Consultation $consultation): ConsultationResource
    {
        $this->authorize('view', $consultation);
        $this->audit->record('consultations.viewed', $consultation, [
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
        ]);

        return new ConsultationResource($consultation->load(['appointment.patient', 'appointment.doctor']));
    }

    /**
     * Find the consultation for an appointment.
     */
    public function forAppointment(Request $request, string $appointment): ConsultationResource
    {
        $consultation = Consultation::where('appointment_id', $appointment)->firstOrFail();

        return $this->show($request, $consultation);
    }

    /**
     * Start (or resume) the consultation (doctor).
     *
     * Allowed from 10 minutes before the booked time until the booked end
     * plus the overtime grace.
     */
    public function start(Request $request, Consultation $consultation): ConsultationResource
    {
        $this->authorize('conduct', $consultation);

        return new ConsultationResource($this->lifecycle->start($consultation, $request->user())->load(['appointment.patient', 'appointment.doctor']));
    }

    /**
     * Complete the consultation (doctor).
     */
    public function complete(Request $request, Consultation $consultation): ConsultationResource
    {
        $this->authorize('conduct', $consultation);

        return new ConsultationResource($this->lifecycle->complete($consultation, $request->user())->load(['appointment.patient', 'appointment.doctor']));
    }

    /**
     * Mark the consultation interrupted (doctor), e.g. after a connection failure.
     */
    public function interrupt(Request $request, Consultation $consultation): ConsultationResource
    {
        $this->authorize('conduct', $consultation);
        $reason = $request->validate(['reason' => ['required', Rule::in(['connection_failure', 'patient_left', 'technical_issue', 'other'])]])['reason'];

        return new ConsultationResource($this->lifecycle->interrupt($consultation, $request->user(), $reason)->load(['appointment.patient', 'appointment.doctor']));
    }

    /**
     * Submit intake information (patient).
     *
     * Include `screening` answers for every question from `GET /emergency-info`.
     * If any red flag is answered "yes" the response contains emergency
     * guidance to show immediately - the patient must not wait for the doctor.
     */
    public function intake(Request $request, Consultation $consultation, EmergencyScreening $screening): JsonResponse
    {
        $this->authorize('submitIntake', $consultation);

        if (! in_array($consultation->status, [ConsultationStatus::Scheduled, ConsultationStatus::Active], true)) {
            throw ApiException::conflict('intake_closed', 'Intake can no longer be changed for this consultation.');
        }

        $questions = array_keys(config('medconnect.emergency.screening_questions'));
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string', 'max:5000'],
            'screening' => ['required', 'array'],
            ...collect($questions)->mapWithKeys(fn (string $q) => ["screening.{$q}" => ['required', 'boolean']])->all(),
        ]);

        $redFlags = $screening->redFlags($data['screening']);

        DB::transaction(function () use ($consultation, $data, $redFlags, $request) {
            $consultation->forceFill([
                'intake_reason' => $data['reason'],
                'intake_details' => $data['details'] ?? null,
                'intake_screening' => array_intersect_key($data['screening'], array_flip(array_keys(config('medconnect.emergency.screening_questions')))),
                'intake_submitted_at' => now(),
                'emergency_flagged' => $consultation->emergency_flagged || $redFlags !== [],
            ])->save();

            $this->audit->record('consultations.intake_submitted', $consultation, [
                'patient_id' => $consultation->patient_id,
                'purpose' => 'consultation:'.$consultation->id,
                'metadata' => ['red_flag_count' => count($redFlags)],
            ]);

            if ($redFlags !== []) {
                $escalation = ConsultationEscalation::create([
                    'consultation_id' => $consultation->id,
                    'raised_by_user_id' => $request->user()->id,
                    'source' => 'screening',
                    'type' => 'emergency',
                    'details' => implode(', ', $redFlags),
                ]);
                DB::afterCommit(fn () => EmergencyEscalated::dispatch($escalation));
            }
        });

        return response()->json(['data' => [
            'consultation' => new ConsultationResource($consultation->load(['appointment.patient', 'appointment.doctor'])),
            'emergency' => $redFlags !== [] ? $screening->guidance() + ['red_flags' => $redFlags] : null,
        ]]);
    }

    /**
     * Escalate to emergency or in-person care (doctor).
     *
     * The patient immediately receives instructions; the consultation may
     * continue or be completed.
     */
    public function escalate(Request $request, Consultation $consultation, EmergencyScreening $screening): JsonResponse
    {
        $this->authorize('conduct', $consultation);
        $data = $request->validate([
            'type' => ['required', Rule::in(['emergency', 'urgent_in_person', 'in_person_referral'])],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $escalation = DB::transaction(function () use ($consultation, $data, $request) {
            $escalation = ConsultationEscalation::create([
                'consultation_id' => $consultation->id,
                'raised_by_user_id' => $request->user()->id,
                'source' => 'doctor',
                'type' => $data['type'],
                'details' => $data['details'] ?? null,
            ]);

            if ($data['type'] === 'emergency') {
                $consultation->forceFill(['emergency_flagged' => true])->save();
            }

            $this->audit->record('consultations.escalated', $escalation, [
                'patient_id' => $consultation->patient_id,
                'purpose' => 'consultation:'.$consultation->id,
                'metadata' => ['type' => $data['type']],
            ]);

            return $escalation;
        });

        EmergencyEscalated::dispatch($escalation);

        return response()->json(['data' => [
            'escalation_id' => $escalation->id,
            'type' => $escalation->type,
            'patient_guidance' => $escalation->type === 'emergency' ? $screening->guidance() : null,
        ]], 201);
    }
}

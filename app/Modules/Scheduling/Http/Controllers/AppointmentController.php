<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Enums\GuardianStatus;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Actions\BookAppointment;
use App\Modules\Scheduling\Actions\CancelAppointment;
use App\Modules\Scheduling\Actions\TransitionAppointment;
use App\Modules\Scheduling\Http\Requests\AppointmentIndexRequest;
use App\Modules\Scheduling\Http\Requests\BookAppointmentRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Appointments
 */
class AppointmentController extends Controller
{
    /**
     * List my appointments.
     *
     * Patients see their own and their verified dependants' appointments;
     * doctors see appointments booked with them.
     */
    public function index(AppointmentIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validated();

        $query = Appointment::query()->with(['patient', 'doctor']);

        if ($user->hasRole(RoleName::Doctor->value)) {
            $query->whereIn('doctor_id', Doctor::where('user_id', $user->id)->select('id'));
        } else {
            $query->whereIn('patient_id', self::actablePatientIds($user));
        }

        self::applyFilters($query, $filters);

        return AppointmentResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    /**
     * Book an appointment.
     *
     * Free consultations are confirmed immediately (or `pending` if the doctor
     * accepts bookings manually). Paid consultations are `awaiting_payment`
     * and hold the slot until `payment_due_at`. Send an `Idempotency-Key`
     * header so retries never create duplicate bookings.
     */
    public function store(BookAppointmentRequest $request, BookAppointment $book): JsonResponse
    {
        $appointment = $book->handle($request->user(), $request->validated(), $request->idempotencyKey());
        $created = $appointment->wasRecentlyCreated;

        return (new AppointmentResource($appointment->load(['patient', 'doctor'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Appointment detail.
     */
    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment->load(['patient', 'doctor']));
    }

    /**
     * Cancel an appointment.
     *
     * The refund owed follows the cancellation policy agreed at booking.
     */
    public function cancel(Request $request, Appointment $appointment, CancelAppointment $cancel): AppointmentResource
    {
        $this->authorize('cancel', $appointment);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        /** @var User $user */
        $user = $request->user();
        $role = match (true) {
            $user->can('isDoctor', $appointment) => CancelAppointment::BY_DOCTOR,
            $user->can('isPatientSide', $appointment) => CancelAppointment::BY_PATIENT,
            default => CancelAppointment::BY_ADMIN,
        };

        return new AppointmentResource($cancel->handle($appointment, $user, $role, $reason)->load(['patient', 'doctor']));
    }

    /**
     * Accept a pending booking (doctor).
     */
    public function accept(Request $request, Appointment $appointment, TransitionAppointment $transitions): AppointmentResource
    {
        $this->authorize('manageAsDoctor', $appointment);

        return new AppointmentResource($transitions->accept($appointment, $request->user())->load(['patient', 'doctor']));
    }

    /**
     * Decline a pending booking (doctor).
     *
     * The patient receives a full refund of anything paid.
     */
    public function decline(Request $request, Appointment $appointment, CancelAppointment $cancel): AppointmentResource
    {
        $this->authorize('manageAsDoctor', $appointment);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return new AppointmentResource($cancel->handle($appointment, $request->user(), CancelAppointment::BY_DOCTOR, $reason)->load(['patient', 'doctor']));
    }

    /**
     * Mark the patient as a no-show (doctor).
     */
    public function noShow(Request $request, Appointment $appointment, TransitionAppointment $transitions): AppointmentResource
    {
        $this->authorize('manageAsDoctor', $appointment);

        return new AppointmentResource($transitions->markNoShow($appointment, $request->user())->load(['patient', 'doctor']));
    }

    /**
     * Patients the user may act for: themselves (if adult) and verified dependants.
     *
     * @return Builder<Patient>
     */
    public static function actablePatientIds(User $user): Builder
    {
        return Patient::query()
            ->where('user_id', $user->id)
            ->orWhereHas('guardianLinks', fn (Builder $q) => $q->where('guardian_user_id', $user->id)->where('status', GuardianStatus::Active))
            ->select('id');
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array<string, mixed>  $filters
     */
    public static function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        foreach (['patient_id', 'doctor_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['from'])) {
            $query->where('starts_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('starts_at', '<=', $filters['to']);
        }

        match ($filters['when'] ?? 'upcoming') {
            'upcoming' => $query->where('ends_at', '>', now())->orderBy('starts_at'),
            'past' => $query->where('ends_at', '<=', now())->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };
    }
}

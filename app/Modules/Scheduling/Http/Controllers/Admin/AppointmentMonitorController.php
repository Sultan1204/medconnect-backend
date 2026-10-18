<?php

namespace App\Modules\Scheduling\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Requests\AppointmentIndexRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Operational monitoring. Shows scheduling data only - no clinical content.
 *
 * @tags Admin - Appointments
 */
class AppointmentMonitorController extends Controller
{
    /**
     * Search all appointments.
     */
    public function index(AppointmentIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated() + ['when' => 'all'];
        $query = Appointment::query()->with(['patient', 'doctor']);
        AppointmentController::applyFilters($query, $filters);

        return AppointmentResource::collection($query->paginate($filters['per_page'] ?? 50)->withQueryString());
    }
}

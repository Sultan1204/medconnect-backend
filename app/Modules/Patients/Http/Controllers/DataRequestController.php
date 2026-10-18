<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Patients\Http\Requests\DataRequestRequest;
use App\Modules\Patients\Http\Resources\DataRequestResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientDataRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Personal data requests under the PDPL (access, correction, deletion, restriction).
 *
 * @tags Patients - Data requests
 */
class DataRequestController extends Controller
{
    /**
     * List my data requests.
     */
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('actFor', $patient);

        return DataRequestResource::collection(
            PatientDataRequest::where('patient_id', $patient->id)->latest()->paginate(20),
        );
    }

    /**
     * Submit a data request.
     *
     * Requests are handled by the data-protection team. Deletion requests are
     * subject to mandatory medical-record retention periods.
     */
    public function store(DataRequestRequest $request, Patient $patient, AuditLogger $audit): DataRequestResource
    {
        $this->authorize('actFor', $patient);

        $dataRequest = PatientDataRequest::create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $request->user()->id,
            'type' => $request->validated('type'),
            'details' => $request->validated('details'),
        ]);

        $audit->record('patients.data_request_submitted', $dataRequest, [
            'patient_id' => $patient->id,
            'metadata' => ['type' => $dataRequest->type],
        ]);

        return new DataRequestResource($dataRequest);
    }
}

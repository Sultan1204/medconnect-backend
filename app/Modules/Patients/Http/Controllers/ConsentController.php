<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Http\Requests\ConsentRequest;
use App\Modules\Patients\Http\Resources\ConsentResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Patients - Consent
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    /**
     * Current consent status.
     *
     * Returns the latest decision per consent type, the current document
     * versions, and which consents are still needed before booking.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json(['data' => [
            'consents' => ConsentResource::collection($this->consents->latest($patient)->values()),
            'current_versions' => config('medconnect.consents.versions'),
            'missing_for_booking' => $this->consents->missingForBooking($patient),
        ]]);
    }

    /**
     * Grant or withdraw a consent.
     *
     * Only an adult patient or a verified guardian may record consent. The
     * submitted `version` must be the current document version.
     */
    public function store(ConsentRequest $request, Patient $patient): ConsentResource
    {
        $this->authorize('actFor', $patient);

        $type = $request->validated('type');
        if ($request->validated('version') !== ConsentService::currentVersion($type)) {
            throw ApiException::make('consent_version_outdated', 'Please review the latest version of this document.', 409, [
                'current_version' => ConsentService::currentVersion($type),
            ]);
        }

        return new ConsentResource($this->consents->record($patient, $type, (bool) $request->validated('granted'), $request->user()));
    }
}

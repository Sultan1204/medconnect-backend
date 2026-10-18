<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Http\Requests\SubmitComplaintRequest;
use App\Modules\Administration\Http\Resources\ComplaintResource;
use App\Modules\Administration\Models\Complaint;
use App\Modules\Administration\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Complaints raised by patients, doctors and pharmacy staff.
 *
 * @tags Complaints
 */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    /**
     * Submit a complaint.
     *
     * The subject must be something you took part in. Otherwise `invalid_subject`.
     */
    public function store(SubmitComplaintRequest $request): JsonResponse
    {
        $complaint = $this->complaints->submit($request->user(), $request->validated());

        return (new ComplaintResource($complaint->load('events')))->response()->setStatusCode(201);
    }

    /**
     * Your complaints.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ComplaintResource::collection(
            Complaint::where('complainant_user_id', $request->user()->id)->orderByDesc('created_at')->paginate(25),
        );
    }

    /**
     * One of your complaints, with its public history.
     */
    public function show(Complaint $complaint): ComplaintResource
    {
        $this->authorize('view', $complaint);

        return new ComplaintResource($complaint->load('events'));
    }
}

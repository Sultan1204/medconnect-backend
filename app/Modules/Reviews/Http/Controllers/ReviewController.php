<?php

namespace App\Modules\Reviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Http\Requests\ReportReviewRequest;
use App\Modules\Reviews\Http\Requests\SubmitReviewRequest;
use App\Modules\Reviews\Http\Resources\PublicReviewResource;
use App\Modules\Reviews\Http\Resources\ReviewResource;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ratings and reviews of completed consultations.
 *
 * @tags Reviews
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Review a completed consultation.
     *
     * Allowed once per consultation, for the patient (or a verified guardian)
     * within the review window. New reviews are held for moderation by default
     * and are published only after approval. Do not include medical details.
     */
    public function store(SubmitReviewRequest $request, Consultation $consultation): JsonResponse
    {
        $review = $this->reviews->submit($consultation, $request->user(), $request->validated('rating'), $request->validated('comment'));

        return (new ReviewResource($review))->response()->setStatusCode(201);
    }

    /**
     * My reviews (all states).
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return ReviewResource::collection(Review::where('author_user_id', $request->user()->id)->latest()->paginate(20));
    }

    /**
     * Report a published review for moderation.
     */
    public function report(ReportReviewRequest $request, Review $review): JsonResponse
    {
        $this->authorize('report', $review);

        $report = $this->reviews->report($review, $request->user(), $request->validated('reason'), $request->validated('details'));

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]], 201);
    }

    /**
     * Published reviews of a doctor.
     *
     * Reviews are anonymous: no patient or consultation details are shown.
     *
     * @unauthenticated
     */
    public function forDoctor(Doctor $doctor): AnonymousResourceCollection
    {
        if (! $doctor->isPubliclyListed()) {
            throw new NotFoundHttpException;
        }

        return $this->listing($doctor);
    }

    /**
     * Rating summary of a doctor (average, count and distribution).
     *
     * @unauthenticated
     */
    public function summary(Doctor $doctor): JsonResponse
    {
        if (! $doctor->isPubliclyListed()) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => $this->summaryFor($doctor)]);
    }

    /**
     * Reviews of me (doctor): published only, plus my summary.
     */
    public function mineAsDoctor(Request $request): JsonResponse
    {
        $doctor = Doctor::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'data' => PublicReviewResource::collection(
                Review::where('doctor_id', $doctor->id)->where('status', ReviewStatus::Published)->orderByDesc('published_at')->paginate(20),
            )->resolve(),
            'summary' => $this->summaryFor($doctor),
        ]);
    }

    /** @return AnonymousResourceCollection<PublicReviewResource> */
    private function listing(Doctor $doctor): AnonymousResourceCollection
    {
        return PublicReviewResource::collection(
            Review::where('doctor_id', $doctor->id)->where('status', ReviewStatus::Published)->orderByDesc('published_at')->paginate(20),
        );
    }

    /** @return array{average: float|null, count: int, distribution: array<int, int>} */
    private function summaryFor(Doctor $doctor): array
    {
        $distribution = array_fill_keys([1, 2, 3, 4, 5], 0);

        Review::where('doctor_id', $doctor->id)->where('status', ReviewStatus::Published)
            ->selectRaw('rating, count(*) as total')->groupBy('rating')->get()
            ->each(function (Review $row) use (&$distribution) {
                $distribution[(int) $row->rating] = (int) $row->getAttribute('total');
            });

        return [
            'average' => $doctor->rating_average !== null ? (float) $doctor->rating_average : null,
            'count' => (int) $doctor->rating_count,
            'distribution' => $distribution,
        ];
    }
}

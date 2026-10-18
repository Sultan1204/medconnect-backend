<?php

namespace App\Modules\Reviews\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Http\Requests\ModerateReviewRequest;
use App\Modules\Reviews\Http\Requests\ResolveReportRequest;
use App\Modules\Reviews\Http\Resources\ReviewReportResource;
use App\Modules\Reviews\Http\Resources\ReviewResource;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Models\ReviewReport;
use App\Modules\Reviews\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Moderation of reviews and review reports.
 *
 * @tags Admin - Reviews
 */
class ReviewAdminController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Review moderation queue.
     *
     * Defaults to reviews awaiting moderation (oldest first). Use `status`,
     * `doctor_id` or `reported=1` (published reviews with open reports).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ReviewStatus::class)],
            'doctor_id' => ['sometimes', 'uuid'],
            'reported' => ['sometimes', 'boolean'],
        ]);

        $query = Review::query()->withCount(['reports as reports_count' => fn ($q) => $q->where('status', 'open')]);

        if ($request->boolean('reported')) {
            $query->whereHas('reports', fn ($q) => $q->where('status', 'open'));
        }

        $query->where('status', $filters['status'] ?? ReviewStatus::Pending->value)
            ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
            ->orderBy('created_at');

        return ReviewResource::collection($query->paginate(50));
    }

    /**
     * Publish, hide or reject a review.
     *
     * Hiding and rejecting require a note. Published reviews can only be hidden.
     */
    public function moderate(ModerateReviewRequest $request, Review $review): ReviewResource
    {
        $this->authorize('moderate', $review);

        return new ReviewResource($this->reviews->moderate($review, $request->user(), $request->validated('decision'), $request->validated('note')));
    }

    /**
     * Open review reports (oldest first).
     */
    public function reports(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['sometimes', Rule::in(['open', 'actioned', 'dismissed'])]])['status'] ?? 'open';

        return ReviewReportResource::collection(
            ReviewReport::where('status', $status)->with('review')->orderBy('created_at')->paginate(50),
        );
    }

    /**
     * Dismiss a report or take the reported review down.
     */
    public function resolveReport(ResolveReportRequest $request, ReviewReport $report): ReviewReportResource
    {
        return new ReviewReportResource(
            $this->reviews->resolveReport($report, $request->user(), $request->validated('action'), $request->validated('note'))->load('review'),
        );
    }
}

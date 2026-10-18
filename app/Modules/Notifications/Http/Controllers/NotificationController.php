<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The signed-in user's in-app notification feed.
 *
 * @tags Notifications
 */
class NotificationController extends Controller
{
    /**
     * List my notifications, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', Rule::in(NotificationCategory::values())],
        ]);

        $notifications = Notification::where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->latest()
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    /**
     * Number of unread notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'unread' => Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]]);
    }

    /**
     * Mark one notification as read.
     */
    public function read(Request $request, string $notification): NotificationResource
    {
        // Scoped to the owner: another user's id is simply "not found".
        $model = Notification::where('user_id', $request->user()->id)->findOrFail($notification);

        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return new NotificationResource($model);
    }

    /**
     * Mark all my notifications as read.
     */
    public function readAll(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['marked_read' => $count]]);
    }
}

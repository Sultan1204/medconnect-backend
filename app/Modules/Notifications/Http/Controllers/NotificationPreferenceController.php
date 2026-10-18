<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Enums\NotificationCategory;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-category, per-channel notification preferences.
 *
 * @tags Notifications - Preferences
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Show my preferences.
     *
     * Every category/channel pair is listed with its effective value.
     * Security notices are mandatory and cannot be turned off.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->matrix($request->user()->id)]);
    }

    /**
     * Update my preferences.
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $userId = $request->user()->id;

        foreach ($request->validated('preferences') as $preference) {
            if (NotificationCategory::from($preference['category'])->isMandatory()) {
                throw ApiException::make('notification_category_mandatory', 'Security notifications cannot be changed.', 422, ['category' => $preference['category']]);
            }

            NotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'category' => $preference['category'], 'channel' => $preference['channel']],
                ['enabled' => $preference['enabled']],
            );
        }

        return response()->json(['data' => $this->matrix($userId)]);
    }

    /**
     * @return list<array{category: string, channel: string, enabled: bool, mandatory: bool}>
     */
    private function matrix(string $userId): array
    {
        $explicit = NotificationPreference::where('user_id', $userId)->get()
            ->mapWithKeys(fn (NotificationPreference $p) => ["{$p->category}.{$p->channel}" => $p->enabled]);

        $rows = [];

        foreach (NotificationCategory::cases() as $category) {
            $defaults = collect(NotificationType::cases())
                ->filter(fn (NotificationType $t) => $t->category() === $category)
                ->flatMap(fn (NotificationType $t) => array_map(fn (NotificationChannel $c) => $c->value, $t->defaultChannels()))
                ->unique();

            foreach (NotificationChannel::cases() as $channel) {
                $rows[] = [
                    'category' => $category->value,
                    'channel' => $channel->value,
                    'enabled' => $category->isMandatory() ? $defaults->contains($channel->value) : (bool) ($explicit["{$category->value}.{$channel->value}"] ?? $defaults->contains($channel->value)),
                    'mandatory' => $category->isMandatory(),
                ];
            }
        }

        return $rows;
    }
}

<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for sending a notification to a user.
 *
 * Creates the in-app entry, decides the outbound channels from platform
 * defaults and the user's preferences, and queues one delivery job per
 * channel. Callers pass identifiers and non-clinical values in $params only.
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $params  identifiers / non-clinical values rendered into the text
     * @param  string|null  $dedupeKey  a second call with the same key for the same user is ignored
     */
    public function notify(User $user, NotificationType $type, array $params = [], ?string $dedupeKey = null): ?Notification
    {
        if (! $user->isActive()) {
            return null;
        }

        if ($dedupeKey !== null && Notification::where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            // A transaction (savepoint when nested) lets a lost dedupe race roll back cleanly.
            $notification = DB::transaction(function () use ($user, $type, $params, $dedupeKey) {
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'category' => $type->category(),
                    'params' => $params,
                    'dedupe_key' => $dedupeKey,
                ]);

                foreach ($this->channelsFor($user, $type) as $channel) {
                    $notification->deliveries()->create(['channel' => $channel->value, 'status' => NotificationDelivery::PENDING]);
                }

                return $notification->load('deliveries');
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        foreach ($notification->deliveries as $delivery) {
            DeliverNotification::dispatch($delivery->id)->afterCommit();
        }

        return $notification;
    }

    /**
     * Platform defaults for the type, adjusted by the user's explicit choices.
     * Mandatory categories (security) ignore preferences.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(User $user, NotificationType $type): array
    {
        $channels = array_map(fn (NotificationChannel $c) => $c->value, $type->defaultChannels());

        if (! $type->category()->isMandatory()) {
            $preferences = NotificationPreference::where('user_id', $user->id)
                ->where('category', $type->category()->value)
                ->pluck('enabled', 'channel');

            foreach ($preferences as $channel => $enabled) {
                $channels = $enabled
                    ? [...$channels, $channel]
                    : array_values(array_diff($channels, [$channel]));
            }
        }

        return array_map(NotificationChannel::from(...), array_values(array_unique($channels)));
    }
}

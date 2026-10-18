<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $text = app(NotificationRenderer::class)->render($this->resource, $request->user()->preferred_locale);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'category' => $this->category->value,
            'title' => $text['title'],
            'body' => $text['body'],
            // Identifiers for deep links (appointment_id, consultation_id ...).
            'params' => $this->params,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}

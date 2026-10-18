<?php

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'sender_role' => $this->sender_role,
            'is_mine' => $this->sender_user_id === $request->user()?->id,
            'type' => $this->type,
            'body' => $this->body,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn (MessageAttachment $a) => [
                'id' => $a->id,
                'file_name' => $a->file_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])),
            'client_message_id' => $this->client_message_id,
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}

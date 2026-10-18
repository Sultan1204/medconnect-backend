<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time notice that a message exists. Deliberately carries NO message
 * content: clients fetch it through the authorised REST endpoint, so
 * clinical text never passes through the queue, Redis or the WebSocket server.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(public readonly Message $message) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('consultation.'.$this->message->consultation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'consultation_id' => $this->message->consultation_id,
            'sender_role' => $this->message->sender_role,
            'type' => $this->message->type,
            'created_at' => $this->message->created_at->toIso8601ZuluString(),
        ];
    }
}

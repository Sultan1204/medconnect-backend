<?php

namespace App\Modules\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Delivery / read receipts for the other participant.
 */
class MessagesAcknowledged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public readonly string $consultationId,
        public readonly string $status,
        public readonly array $messageIds,
        public readonly string $byRole,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('consultation.'.$this->consultationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.'.$this->status;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message_ids' => $this->messageIds, 'by_role' => $this->byRole, 'at' => now()->toIso8601ZuluString()];
    }
}

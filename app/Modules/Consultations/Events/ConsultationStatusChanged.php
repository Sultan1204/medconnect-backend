<?php

namespace App\Modules\Consultations\Events;

use App\Modules\Consultations\Models\Consultation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast to both participants. Carries status only - no clinical content.
 */
class ConsultationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(public readonly Consultation $consultation) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('consultation.'.$this->consultation->id)];
    }

    public function broadcastAs(): string
    {
        return 'consultation.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'consultation_id' => $this->consultation->id,
            'status' => $this->consultation->status->value,
            'at' => now()->toIso8601ZuluString(),
        ];
    }
}

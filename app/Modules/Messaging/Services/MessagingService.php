<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Events\MessagesAcknowledged;
use App\Modules\Messaging\Events\MessageSent;
use App\Modules\Messaging\Models\Message;
use App\Support\Exceptions\ApiException;
use App\Support\Files\PrivateFileStore;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MessagingService
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Messages may be sent while the consultation is active, and for a short
     * follow-up window after completion.
     */
    public function isOpen(Consultation $consultation): bool
    {
        return match ($consultation->status) {
            ConsultationStatus::Active, ConsultationStatus::Interrupted => true,
            ConsultationStatus::Completed => $consultation->ended_at !== null
                && $consultation->ended_at->copy()->addHours((int) config('medconnect.consultations.follow_up_messaging_hours'))->isFuture(),
            default => false,
        };
    }

    /**
     * Idempotent per (sender, client_message_id): a client that re-sends
     * after a dropped connection receives the original message.
     */
    public function send(Consultation $consultation, User $sender, string $role, ?string $body, ?UploadedFile $file, ?string $clientMessageId): Message
    {
        if (! $this->isOpen($consultation)) {
            throw ApiException::conflict('messaging_closed', 'Messaging is not open for this consultation.');
        }

        if ($clientMessageId !== null && ($existing = $this->findByClientId($consultation, $sender, $clientMessageId)) !== null) {
            return $existing;
        }

        // Scan and store before the transaction so a slow scan never holds row locks.
        $stored = $file !== null ? $this->files->store($file, "consultations/{$consultation->id}/attachments") : null;

        try {
            $message = DB::transaction(function () use ($consultation, $sender, $role, $body, $stored, $clientMessageId) {
                $message = Message::create([
                    'consultation_id' => $consultation->id,
                    'sender_user_id' => $sender->id,
                    'sender_role' => $role,
                    'type' => $stored !== null ? 'attachment' : 'text',
                    'body' => $body,
                    'client_message_id' => $clientMessageId,
                ]);

                if ($stored !== null) {
                    $message->attachments()->create([
                        'file_path' => $stored->path,
                        'file_name' => $stored->originalName,
                        'mime_type' => $stored->mimeType,
                        'size_bytes' => $stored->size,
                        'sha256' => $stored->sha256,
                    ]);
                }

                // Metadata only - the body is never written to the audit log.
                $this->audit->record('messages.sent', $message, [
                    'actor' => $sender,
                    'patient_id' => $consultation->patient_id,
                    'purpose' => 'consultation:'.$consultation->id,
                    'metadata' => ['type' => $message->type],
                ]);

                return $message;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && $clientMessageId !== null && ($existing = $this->findByClientId($consultation, $sender, $clientMessageId)) !== null) {
                if ($stored !== null) {
                    $this->files->delete($stored->path);
                }

                return $existing;
            }

            throw $e;
        }

        MessageSent::dispatch($message);

        return $message;
    }

    /**
     * Marks the other participant's messages delivered or read, up to and
     * including $upToMessageId. Read implies delivered.
     *
     * @return list<string> affected message ids
     */
    public function acknowledge(Consultation $consultation, User $reader, string $role, string $status, string $upToMessageId): array
    {
        /** @var Message $upTo */
        $upTo = Message::where('consultation_id', $consultation->id)->findOrFail($upToMessageId);
        $column = $status === 'read' ? 'read_at' : 'delivered_at';

        $ids = Message::query()
            ->where('consultation_id', $consultation->id)
            ->where('sender_role', '!=', $role)
            ->whereNull($column)
            ->where('seq', '<=', $upTo->seq)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            $values = $status === 'read'
                ? ['read_at' => now(), 'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())')]
                : ['delivered_at' => now()];
            Message::whereIn('id', $ids)->update($values);

            MessagesAcknowledged::dispatch($consultation->id, $status, $ids, $role);
        }

        return $ids;
    }

    private function findByClientId(Consultation $consultation, User $sender, string $clientMessageId): ?Message
    {
        return Message::where('consultation_id', $consultation->id)
            ->where('sender_user_id', $sender->id)
            ->where('client_message_id', $clientMessageId)
            ->first();
    }
}

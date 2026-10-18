<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Http\Requests\SendMessageRequest;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageAttachment;
use App\Modules\Messaging\Services\MessagingService;
use App\Support\Files\PrivateFileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Consultation chat. Real-time notices arrive on the private channel
 * `private-consultation.{id}` (event `message.sent`, metadata only); fetch
 * the content here.
 *
 * @tags Consultations - Messages
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    /**
     * List messages.
     *
     * Newest first by default (`before` cursor for older pages). After a
     * reconnect, pass `after=<last message id you have>` to receive everything
     * newer in chronological order.
     */
    public function index(Request $request, Consultation $consultation, AuditLogger $audit): AnonymousResourceCollection
    {
        $this->authorize('participate', $consultation);
        $data = $request->validate([
            'before' => ['sometimes', 'uuid'],
            'after' => ['sometimes', 'uuid', 'prohibits:before'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Message::query()->where('consultation_id', $consultation->id)->with('attachments');
        $limit = $data['limit'] ?? 50;

        if (isset($data['after'])) {
            $query->where('seq', '>', $this->cursorSeq($consultation, $data['after']))->orderBy('seq');
        } else {
            if (isset($data['before'])) {
                $query->where('seq', '<', $this->cursorSeq($consultation, $data['before']));
            }
            $query->orderByDesc('seq');
        }

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;

        $audit->record('messages.listed', $consultation, [
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
        ]);

        return MessageResource::collection($messages->take($limit))->additional(['meta' => ['has_more' => $hasMore]]);
    }

    /**
     * Send a message.
     *
     * JSON for text, or multipart/form-data with `file` for an attachment.
     * Re-sending with the same `client_message_id` returns the original message.
     */
    public function store(SendMessageRequest $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('participate', $consultation);

        /** @var User $user */
        $user = $request->user();
        $message = $this->messaging->send(
            $consultation,
            $user,
            (string) $consultation->participantRole($user),
            $request->validated('body'),
            $request->file('file'),
            $request->validated('client_message_id'),
        );

        return (new MessageResource($message->load('attachments')))->response()->setStatusCode($message->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Acknowledge delivery or reading of the other participant's messages.
     *
     * Marks every message up to and including `up_to` (a message id).
     */
    public function acknowledge(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('participate', $consultation);
        $data = $request->validate([
            'status' => ['required', Rule::in(['delivered', 'read'])],
            'up_to' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $ids = $this->messaging->acknowledge($consultation, $user, (string) $consultation->participantRole($user), $data['status'], $data['up_to']);

        return response()->json(['data' => ['acknowledged' => count($ids)]]);
    }

    /**
     * Short-lived download link for an attachment.
     */
    public function attachment(Consultation $consultation, string $attachment, PrivateFileStore $files, AuditLogger $audit): JsonResponse
    {
        $this->authorize('participate', $consultation);

        /** @var MessageAttachment $file */
        $file = MessageAttachment::whereHas('message', fn ($q) => $q->where('consultation_id', $consultation->id))->findOrFail($attachment);
        $minutes = (int) config('medconnect.files.download_link_minutes');

        $audit->record('messages.attachment_downloaded', $file, [
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
        ]);

        return response()->json(['data' => [
            'url' => $files->temporaryUrl($file->file_path, $file->file_name, $minutes),
            'expires_at' => now()->addMinutes($minutes)->toIso8601ZuluString(),
        ]]);
    }

    private function cursorSeq(Consultation $consultation, string $messageId): int
    {
        return (int) Message::where('consultation_id', $consultation->id)->whereKey($messageId)->valueOrFail('seq');
    }
}

<?php

namespace App\Modules\Video\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Video\Models\VideoSession;
use App\Modules\Video\Models\VideoSessionEvent;
use App\Modules\Video\Services\VideoService;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @tags Consultations - Video
 */
class VideoController extends Controller
{
    public function __construct(private readonly VideoService $video) {}

    /**
     * Get video credentials.
     *
     * Only for participants of an active video consultation. Credentials
     * expire after a few minutes (request new ones to reconnect) and never
     * outlive the consultation's hard end time. Recording is not supported.
     */
    public function token(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('participate', $consultation);

        /** @var User $user */
        $user = $request->user();
        $credentials = $this->video->credentialsFor($consultation, $user, (string) $consultation->participantRole($user));

        return response()->json(['data' => [
            'token' => $credentials->token,
            'expires_at' => $credentials->expiresAt->toIso8601ZuluString(),
            'connection' => $credentials->connection,
            'session_id' => VideoSession::where('consultation_id', $consultation->id)->latest()->value('id'),
        ]]);
    }

    /**
     * Report a technical session event.
     *
     * Clients report joins, leaves, reconnects and failures for quality
     * monitoring. Metadata must be technical only (e.g. error codes).
     */
    public function event(Request $request, string $session): JsonResponse
    {
        /** @var VideoSession $videoSession */
        $videoSession = VideoSession::with(['consultation.doctor', 'consultation.patient'])->findOrFail($session);
        $this->authorize('participate', $videoSession->consultation);

        if (! $videoSession->isOpen()) {
            throw ApiException::conflict('video_session_closed', 'This video session has ended.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(VideoSessionEvent::TYPES)],
            'metadata' => ['sometimes', 'array', 'max:10'],
            'metadata.*' => ['nullable', 'string', 'max:100'],
        ]);

        $this->video->recordEvent($videoSession, $request->user(), $data['type'], $data['metadata'] ?? []);

        return response()->json(['data' => ['status' => 'recorded']], 202);
    }
}

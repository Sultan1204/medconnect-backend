<?php

namespace App\Modules\Video\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Identity\Models\User;
use App\Modules\Video\Contracts\VideoProvider;
use App\Modules\Video\Data\ParticipantCredentials;
use App\Modules\Video\Models\VideoSession;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly VideoProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Short-lived credentials for one participant of an active video consultation.
     */
    public function credentialsFor(Consultation $consultation, User $user, string $role): ParticipantCredentials
    {
        if ($consultation->consultation_type !== 'video') {
            throw ApiException::make('not_a_video_consultation', 'This consultation does not use video.');
        }

        if ($consultation->status !== ConsultationStatus::Active) {
            throw ApiException::conflict('consultation_not_active', 'The doctor has not started this consultation yet.');
        }

        $session = $this->openSession($consultation);
        $ttl = (int) config('medconnect.video.token_ttl_minutes');
        $expiresAt = CarbonImmutable::now()->addMinutes($ttl)->min(CarbonImmutable::instance($session->expires_at));

        $credentials = $this->provider->participantCredentials($session, $user, $role, $expiresAt);

        $this->audit->record('video.credentials_issued', $session, [
            'actor' => $user,
            'patient_id' => $consultation->patient_id,
            'purpose' => 'consultation:'.$consultation->id,
            'metadata' => ['role' => $role, 'expires_at' => $expiresAt->toIso8601ZuluString()],
        ]);

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $metadata  technical details only (codec, network type, error code)
     */
    public function recordEvent(VideoSession $session, User $user, string $type, array $metadata = []): void
    {
        DB::transaction(function () use ($session, $user, $type, $metadata) {
            $session->events()->create(['user_id' => $user->id, 'type' => $type, 'metadata' => $metadata ?: null]);

            if ($type === 'joined' && $session->status === 'created') {
                $session->forceFill(['status' => 'active', 'started_at' => now()])->save();
            }

            if ($type === 'connection_failed') {
                $session->forceFill(['last_error' => mb_substr((string) ($metadata['code'] ?? 'unknown'), 0, 300)])->save();
            }
        });
    }

    /**
     * Closes the room when the consultation ends or is interrupted.
     */
    public function endFor(Consultation $consultation): void
    {
        VideoSession::where('consultation_id', $consultation->id)
            ->whereIn('status', ['created', 'active'])
            ->each(function (VideoSession $session) {
                try {
                    $this->provider->endRoom($session);
                    $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
                } catch (Throwable $e) {
                    report($e);
                    $session->forceFill(['status' => 'failed', 'ended_at' => now(), 'last_error' => 'end_room_failed'])->save();
                }
            });
    }

    private function openSession(Consultation $consultation): VideoSession
    {
        return DB::transaction(function () use ($consultation) {
            // Serialise so both participants land in the same room.
            Consultation::whereKey($consultation->id)->lockForUpdate()->first();

            $session = VideoSession::where('consultation_id', $consultation->id)
                ->whereIn('status', ['created', 'active'])
                ->where('expires_at', '>', now())
                ->latest()
                ->first();

            if ($session !== null) {
                return $session;
            }

            $closesAt = CarbonImmutable::instance($consultation->hardEndsAt());

            return VideoSession::create([
                'consultation_id' => $consultation->id,
                'provider' => $this->provider->name(),
                'provider_room_id' => $this->provider->createRoom($consultation->id, $closesAt),
                'status' => 'created',
                'expires_at' => $closesAt,
            ]);
        });
    }
}

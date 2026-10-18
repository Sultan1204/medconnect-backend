<?php

namespace App\Modules\Video\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Video\Contracts\VideoProvider;
use App\Modules\Video\Data\ParticipantCredentials;
use App\Modules\Video\Models\VideoSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development provider. Issues signed, expiring tokens with the same
 * shape as a real provider so clients can be built against it, but connects
 * no media. Refuses to run in production.
 */
class MockVideoProvider implements VideoProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function createRoom(string $consultationId, CarbonImmutable $closesAt): string
    {
        $this->guard();

        return 'mock-room-'.Str::uuid7();
    }

    public function participantCredentials(VideoSession $session, User $user, string $role, CarbonImmutable $expiresAt): ParticipantCredentials
    {
        $this->guard();

        $payload = base64_encode(json_encode([
            'room' => $session->provider_room_id,
            'sub' => $user->id,
            'role' => $role,
            'exp' => $expiresAt->getTimestamp(),
            'nonce' => Str::random(16),
        ], JSON_THROW_ON_ERROR));

        return new ParticipantCredentials(
            token: $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key')),
            expiresAt: $expiresAt,
            connection: ['provider' => 'mock', 'room' => $session->provider_room_id, 'recording' => false, 'media' => 'none'],
        );
    }

    public function endRoom(VideoSession $session): void
    {
        $this->guard();
    }

    /**
     * Validates a mock token (used by tests and local signalling stubs).
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode($payload, true), true);

        return is_array($data) && ($data['exp'] ?? 0) > time() ? $data : null;
    }

    private function guard(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The mock video provider must not be used in production.');
        }
    }
}

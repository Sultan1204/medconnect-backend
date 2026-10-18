<?php

namespace Tests\Feature\Video;

use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Video\Models\VideoSession;
use App\Modules\Video\Providers\MockVideoProvider;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class VideoSessionTest extends TestCase
{
    use ConsultationFixtures;

    public function test_participants_receive_short_lived_credentials_for_the_same_room(): void
    {
        $consultation = $this->activeConsultation('video');

        Sanctum::actingAs($consultation->doctor->user, ['*']);
        $doctor = $this->postJson("/api/v1/consultations/{$consultation->id}/video-token")->assertOk()
            ->assertJsonPath('data.connection.recording', false)->json('data');

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $patient = $this->postJson("/api/v1/consultations/{$consultation->id}/video-token")->assertOk()->json('data');

        $doctorClaims = MockVideoProvider::decode($doctor['token']);
        $patientClaims = MockVideoProvider::decode($patient['token']);

        $this->assertSame('doctor', $doctorClaims['role']);
        $this->assertSame('patient', $patientClaims['role']);
        $this->assertSame($doctorClaims['room'], $patientClaims['room']);
        $this->assertLessThanOrEqual(now()->addMinutes(10)->timestamp, $patientClaims['exp']);
        $this->assertSame(1, VideoSession::count());

        // Tampered tokens are invalid.
        $this->assertNull(MockVideoProvider::decode($patient['token'].'x'));
    }

    public function test_credentials_require_an_active_video_consultation(): void
    {
        $scheduled = $this->scheduledConsultation('video');
        Sanctum::actingAs($scheduled->patient->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$scheduled->id}/video-token"), 409, 'consultation_not_active');

        $chat = $this->activeConsultation('chat');
        Sanctum::actingAs($chat->patient->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$chat->id}/video-token"), 422, 'not_a_video_consultation');
    }

    public function test_non_participants_cannot_join(): void
    {
        $consultation = $this->activeConsultation('video');

        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::Admin] as $role) {
            $this->actingAsRole($role);
            $this->assertErrorCode($this->postJson("/api/v1/consultations/{$consultation->id}/video-token"), 403, 'forbidden');
        }
    }

    public function test_session_events_are_tracked_and_the_room_closes_with_the_consultation(): void
    {
        $consultation = $this->activeConsultation('video');
        Sanctum::actingAs($consultation->patient->user, ['*']);
        $sessionId = $this->postJson("/api/v1/consultations/{$consultation->id}/video-token")->json('data.session_id');

        $this->postJson("/api/v1/video-sessions/{$sessionId}/events", ['type' => 'joined'])->assertStatus(202);
        $this->postJson("/api/v1/video-sessions/{$sessionId}/events", ['type' => 'connection_failed', 'metadata' => ['code' => 'ICE_TIMEOUT']])->assertStatus(202);

        $session = VideoSession::findOrFail($sessionId);
        $this->assertSame('active', $session->status);
        $this->assertSame('ICE_TIMEOUT', $session->last_error);

        app(ConsultationLifecycle::class)->complete($consultation, $consultation->doctor->user);

        $this->assertSame('ended', $session->fresh()->status);
        $this->assertErrorCode($this->postJson("/api/v1/video-sessions/{$sessionId}/events", ['type' => 'joined']), 409, 'video_session_closed');
    }

    public function test_credentials_never_outlive_the_consultation(): void
    {
        $consultation = $this->activeConsultation('video');
        $this->travel(30 + 10 + 5 - 3)->minutes(); // 3 minutes before the hard end

        Sanctum::actingAs($consultation->patient->user, ['*']);
        $token = $this->postJson("/api/v1/consultations/{$consultation->id}/video-token")->assertOk()->json('data.token');

        $this->assertLessThanOrEqual($consultation->hardEndsAt()->timestamp, json_decode(base64_decode(explode('.', $token)[0]), true)['exp']);
    }
}

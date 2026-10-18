<?php

namespace Tests\Feature\Messaging;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Events\MessageSent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use ConsultationFixtures;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->consultation = $this->activeConsultation('chat');
    }

    private function send(string $body, ?string $clientId = null): TestResponse
    {
        return $this->postJson("/api/v1/consultations/{$this->consultation->id}/messages", array_filter(['body' => $body, 'client_message_id' => $clientId]));
    }

    private function asPatient(): void
    {
        Sanctum::actingAs($this->consultation->patient->user, ['*']);
    }

    private function asDoctor(): void
    {
        Sanctum::actingAs($this->consultation->doctor->user, ['*']);
    }

    public function test_participants_exchange_messages_and_bodies_are_encrypted_at_rest(): void
    {
        $this->asPatient();
        $this->send('I have had a fever since yesterday')->assertCreated()->assertJsonPath('data.sender_role', 'patient')->assertJsonPath('data.is_mine', true);

        $this->asDoctor();
        $this->send('Please measure your temperature')->assertCreated()->assertJsonPath('data.sender_role', 'doctor');

        $messages = $this->getJson("/api/v1/consultations/{$this->consultation->id}/messages")->assertOk()->json('data');
        $this->assertSame(['Please measure your temperature', 'I have had a fever since yesterday'], array_column($messages, 'body'));

        foreach (DB::table('messages')->pluck('body') as $stored) {
            $this->assertStringNotContainsString('fever', $stored);
            $this->assertStringNotContainsString('temperature', $stored);
        }
    }

    public function test_broadcast_payload_never_contains_message_content(): void
    {
        Event::fake([MessageSent::class]);
        $this->asPatient();
        $this->send('Sensitive symptom description')->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
            $payload = json_encode($event->broadcastWith());
            $this->assertStringNotContainsString('Sensitive', $payload);
            $this->assertSame(['private-consultation.'.$this->consultation->id], array_map('strval', $event->broadcastOn()));

            return true;
        });
    }

    public function test_message_bodies_are_not_written_to_the_audit_log(): void
    {
        $this->asPatient();
        $this->send('Confidential detail XYZ')->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'messages.sent']);
        $this->assertStringNotContainsString('XYZ', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_retries_with_the_same_client_id_are_deduplicated(): void
    {
        $this->asPatient();
        $first = $this->send('Hello', 'client-msg-1')->assertCreated()->json('data.id');
        $second = $this->send('Hello', 'client-msg-1')->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('messages')->count());
    }

    public function test_cursor_pagination_and_reconnect_recovery(): void
    {
        $this->asPatient();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->send("Message {$i}")->json('data.id');
        }

        $page = $this->getJson("/api/v1/consultations/{$this->consultation->id}/messages?limit=2")->assertJsonPath('meta.has_more', true)->json('data');
        $this->assertSame(['Message 5', 'Message 4'], array_column($page, 'body'));

        $older = $this->getJson("/api/v1/consultations/{$this->consultation->id}/messages?limit=2&before={$ids[3]}")->json('data');
        $this->assertSame(['Message 3', 'Message 2'], array_column($older, 'body'));

        $missed = $this->getJson("/api/v1/consultations/{$this->consultation->id}/messages?after={$ids[2]}")->json('data');
        $this->assertSame(['Message 4', 'Message 5'], array_column($missed, 'body'));
    }

    public function test_read_receipts_mark_only_the_other_partys_messages(): void
    {
        $this->asPatient();
        $a = $this->send('One')->json('data.id');
        $b = $this->send('Two')->json('data.id');

        $this->asDoctor();
        $mine = $this->send('Reply')->json('data.id');
        $this->postJson("/api/v1/consultations/{$this->consultation->id}/messages/ack", ['status' => 'read', 'up_to' => $mine])
            ->assertOk()->assertJsonPath('data.acknowledged', 2);

        $this->assertNotNull(DB::table('messages')->where('id', $a)->value('read_at'));
        $this->assertNotNull(DB::table('messages')->where('id', $b)->value('delivered_at'));
        $this->assertNull(DB::table('messages')->where('id', $mine)->value('read_at'));
    }

    public function test_attachments_are_scanned_stored_privately_and_shared_via_short_links(): void
    {
        $this->asPatient();
        $response = $this->post("/api/v1/consultations/{$this->consultation->id}/messages", [
            'file' => UploadedFile::fake()->create('lab-result.pdf', 300, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.type', 'attachment');

        $attachmentId = $response->json('data.attachments.0.id');
        $this->assertCount(1, Storage::disk('private')->allFiles("consultations/{$this->consultation->id}/attachments"));

        $this->asDoctor();
        $url = $this->getJson("/api/v1/consultations/{$this->consultation->id}/attachments/{$attachmentId}")->assertOk()->json('data.url');
        $this->get($url)->assertOk();

        Sanctum::actingAs(User::factory()->patient()->create(), ['*']);
        $this->assertErrorCode($this->getJson("/api/v1/consultations/{$this->consultation->id}/attachments/{$attachmentId}"), 403, 'forbidden');
    }

    public function test_executable_attachments_are_rejected(): void
    {
        $this->asPatient();
        $this->post("/api/v1/consultations/{$this->consultation->id}/messages", [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_messaging_is_closed_before_start_and_after_the_follow_up_window(): void
    {
        $scheduled = $this->scheduledConsultation('chat');
        Sanctum::actingAs($scheduled->patient->user, ['*']);
        $this->assertErrorCode($this->postJson("/api/v1/consultations/{$scheduled->id}/messages", ['body' => 'Hi']), 409, 'messaging_closed');

        app(ConsultationLifecycle::class)->complete($this->consultation, $this->consultation->doctor->user);
        $this->asPatient();
        $this->send('Follow-up question')->assertCreated();

        $this->travel(25)->hours();
        $this->assertErrorCode($this->send('Too late'), 409, 'messaging_closed');
    }

    public function test_non_participants_including_admins_cannot_read_or_send(): void
    {
        foreach ([RoleName::Patient, RoleName::Doctor, RoleName::Admin, RoleName::SuperAdmin] as $role) {
            $this->actingAsRole($role);
            $this->assertErrorCode($this->getJson("/api/v1/consultations/{$this->consultation->id}/messages"), 403, 'forbidden');
            $this->assertErrorCode($this->send('Intrusion'), 403, 'forbidden');
        }
    }

    public function test_private_channel_authorisation_admits_only_participants(): void
    {
        $channels = Broadcast::driver()->getChannels();
        $private = $channels['consultation.{consultationId}'];
        $presence = $channels['consultation.{consultationId}.presence'];
        $id = $this->consultation->id;

        $this->assertTrue($private($this->consultation->patient->user, $id));
        $this->assertTrue($private($this->consultation->doctor->user, $id));
        $this->assertFalse($private(User::factory()->admin()->create(), $id));
        $this->assertFalse($private(User::factory()->patient()->create(), $id));

        $this->assertSame('doctor', $presence($this->consultation->doctor->user, $id)['role']);
        $this->assertFalse($presence(User::factory()->patient()->create(), $id));

        // The auth endpoint itself requires a bearer token.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => "private-consultation.{$id}", 'socket_id' => '1.1'])->assertUnauthorized();
    }
}

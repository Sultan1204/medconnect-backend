<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Models\ContactMessage;
use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactMessageTest extends TestCase
{
    private function body(array $over = []): array
    {
        return $over + ['name' => 'Sara', 'email' => 'sara@example.test', 'subject' => 'Question', 'message' => 'How do I book a video consultation?'];
    }

    public function test_anyone_can_send_a_message_and_it_is_stored_encrypted(): void
    {
        $this->postJson('/api/v1/contact', $this->body())->assertStatus(202)->assertJsonPath('data.status', 'received');

        $raw = DB::table('contact_messages')->first();
        $this->assertStringNotContainsString('video consultation', $raw->message);
        $this->assertStringNotContainsString('sara@example.test', $raw->email);
        $this->assertSame('How do I book a video consultation?', ContactMessage::first()->message);
    }

    public function test_validation_and_rate_limit(): void
    {
        $this->postJson('/api/v1/contact', $this->body(['email' => 'nope']))->assertUnprocessable();
        $this->postJson('/api/v1/contact', $this->body(['message' => 'short']))->assertUnprocessable();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/contact', $this->body());
        }
        $this->postJson('/api/v1/contact', $this->body())->assertStatus(429);
    }

    public function test_only_complaint_handlers_can_read_and_handle_messages(): void
    {
        $this->postJson('/api/v1/contact', $this->body())->assertStatus(202);
        $id = ContactMessage::first()->id;

        $this->actingAsRole(RoleName::Patient);
        $this->getJson('/api/v1/admin/contact-messages')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->withMfa()->create(), ['*']);
        $this->getJson('/api/v1/admin/contact-messages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'sara@example.test');
        $this->postJson("/api/v1/admin/contact-messages/{$id}/handle")->assertOk();
        $this->getJson('/api/v1/admin/contact-messages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/contact-messages?handled=1')->assertOk()->assertJsonCount(1, 'data');
    }
}

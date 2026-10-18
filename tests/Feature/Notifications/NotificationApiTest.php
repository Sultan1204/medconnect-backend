<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Enums\RoleName;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Services\NotificationService;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->actingAsRole(RoleName::Patient, ['preferred_locale' => 'en']);
    }

    private function notify(User $user, NotificationType $type = NotificationType::AppointmentConfirmed, array $params = ['starts_at' => '2026-11-02T07:00:00Z']): Notification
    {
        return app(NotificationService::class)->notify($user, $type, $params) ?? throw new \LogicException('not created');
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->putJson('/api/v1/notification-preferences', [])->assertUnauthorized();
        $this->postJson('/api/v1/push-devices', [])->assertUnauthorized();
    }

    public function test_the_feed_lists_only_my_notifications_in_my_language(): void
    {
        $mine = $this->notify($this->user);
        $this->notify(User::factory()->patient()->create());

        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.type', 'appointment.confirmed')
            ->assertJsonPath('data.0.title', 'Appointment confirmed');
        $this->assertStringContainsString('10:00', $response->json('data.0.body'));

        $this->user->forceFill(['preferred_locale' => 'ar'])->save();
        $this->assertSame('تم تأكيد الموعد', $this->getJson('/api/v1/notifications')->json('data.0.title'));
    }

    public function test_reading_and_counting(): void
    {
        $first = $this->notify($this->user);
        $this->notify($this->user, NotificationType::PaymentConfirmed, ['amount' => 15000, 'currency' => 'SAR']);

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);

        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk()->assertJsonPath('data.read_at', fn ($v) => $v !== null);
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/notifications?category=payments')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'We received your payment of 150.00 SAR. Thank you.');

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_other_users_notifications_are_not_found(): void
    {
        $theirs = $this->notify(User::factory()->patient()->create());

        $this->assertErrorCode($this->postJson("/api/v1/notifications/{$theirs->id}/read"), 404, 'not_found');
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_feed_filters_are_validated(): void
    {
        $this->getJson('/api/v1/notifications?category=nonsense')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_preferences_show_defaults_and_can_be_changed(): void
    {
        $find = fn (array $matrix, string $category, string $channel) => collect($matrix)->first(fn ($r) => $r['category'] === $category && $r['channel'] === $channel);
        $matrix = $this->getJson('/api/v1/notification-preferences')->assertOk()->json('data');

        $this->assertTrue($find($matrix, 'appointments', 'sms')['enabled']);
        $this->assertFalse($find($matrix, 'messages', 'sms')['enabled']);
        $this->assertTrue($find($matrix, 'security', 'sms')['mandatory']);

        $this->putJson('/api/v1/notification-preferences', ['preferences' => [
            ['category' => 'appointments', 'channel' => 'sms', 'enabled' => false],
            ['category' => 'messages', 'channel' => 'email', 'enabled' => true],
        ]])->assertOk();

        $this->assertFalse(NotificationPreference::where('user_id', $this->user->id)->where('category', 'appointments')->where('channel', 'sms')->value('enabled'));
        $matrix = $this->getJson('/api/v1/notification-preferences')->json('data');
        $this->assertFalse($find($matrix, 'appointments', 'sms')['enabled']);
        $this->assertTrue($find($matrix, 'messages', 'email')['enabled']);
    }

    public function test_updating_is_idempotent_and_validated(): void
    {
        $body = ['preferences' => [['category' => 'payments', 'channel' => 'email', 'enabled' => false]]];

        $this->putJson('/api/v1/notification-preferences', $body)->assertOk();
        $this->putJson('/api/v1/notification-preferences', $body)->assertOk();
        $this->assertSame(1, NotificationPreference::where('user_id', $this->user->id)->count());

        $this->putJson('/api/v1/notification-preferences', ['preferences' => [['category' => 'payments', 'channel' => 'carrier_pigeon', 'enabled' => true]]])->assertUnprocessable();
        $this->putJson('/api/v1/notification-preferences', ['preferences' => []])->assertUnprocessable();
    }

    public function test_security_notices_cannot_be_disabled(): void
    {
        $this->assertErrorCode(
            $this->putJson('/api/v1/notification-preferences', ['preferences' => [['category' => 'security', 'channel' => 'sms', 'enabled' => false]]]),
            422,
            'notification_category_mandatory',
        );
        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_push_devices_register_refresh_and_move_between_accounts(): void
    {
        $token = 'fcm-token-abcdefghij';

        $created = $this->postJson('/api/v1/push-devices', ['platform' => 'android', 'token' => $token, 'device_name' => 'Pixel'])
            ->assertCreated()->assertJsonMissingPath('data.token');
        $this->postJson('/api/v1/push-devices', ['platform' => 'android', 'token' => $token])->assertOk();
        $this->assertSame(1, PushDevice::count());

        // The token is stored encrypted, not in plaintext.
        $this->assertStringNotContainsString($token, (string) \DB::table('push_devices')->value('token'));

        // The same phone now signs in as someone else.
        $other = $this->actingAsRole(RoleName::Patient);
        $this->postJson('/api/v1/push-devices', ['platform' => 'android', 'token' => $token])->assertOk();
        $this->assertSame($other->id, PushDevice::firstOrFail()->user_id);

        // The previous owner can no longer remove (or see) it.
        $this->actingAs($this->user, 'sanctum');
        $this->deleteJson('/api/v1/push-devices/'.$created->json('data.id'))->assertNotFound();
    }

    public function test_push_device_removal(): void
    {
        $id = $this->postJson('/api/v1/push-devices', ['platform' => 'ios', 'token' => 'apns-token-1234567'])->json('data.id');

        $this->deleteJson("/api/v1/push-devices/{$id}")->assertNoContent();
        $this->assertSame(0, PushDevice::count());
        $this->postJson('/api/v1/push-devices', ['platform' => 'toaster', 'token' => 'x'])->assertUnprocessable();
    }
}

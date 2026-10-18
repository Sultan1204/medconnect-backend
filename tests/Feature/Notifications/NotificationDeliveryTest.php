<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Contracts\PushProvider;
use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Push\FakePushProvider;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    private function push(): FakePushProvider
    {
        /** @var FakePushProvider */
        return app(PushProvider::class);
    }

    private function device(User $user, string $token): PushDevice
    {
        return PushDevice::create(['user_id' => $user->id, 'platform' => 'ios', 'token' => $token, 'token_hash' => PushDevice::hashToken($token)]);
    }

    private function deliveryFor(User $user, string $channel): NotificationDelivery
    {
        return NotificationDelivery::whereHas('notification', fn ($q) => $q->where('user_id', $user->id))->where('channel', $channel)->firstOrFail();
    }

    private function send(User $user, NotificationType $type = NotificationType::AppointmentConfirmed): void
    {
        app(NotificationService::class)->notify($user, $type, ['starts_at' => '2026-11-02T07:00:00Z']);
    }

    public function test_successful_deliveries_record_outcome_without_message_text_or_address(): void
    {
        Mail::fake();
        $user = User::factory()->patient()->create(['phone' => '+966500000222']);
        $this->device($user, 'good-token-1234567');

        $this->send($user);

        foreach (['sms', 'email', 'push'] as $channel) {
            $delivery = $this->deliveryFor($user, $channel);
            $this->assertSame('sent', $delivery->status, $channel);
            $this->assertSame(1, $delivery->attempts);
            $this->assertNotNull($delivery->sent_at);
        }

        $dump = json_encode(\DB::table('notification_deliveries')->get());
        $this->assertStringNotContainsString('966500000222', $dump);
        $this->assertStringNotContainsString($user->email, $dump);
    }

    public function test_channels_without_a_verified_destination_are_skipped_not_failed(): void
    {
        $user = User::factory()->patient()->unverified()->create();

        $this->send($user);

        $this->assertSame('skipped', $this->deliveryFor($user, 'sms')->status);
        $this->assertSame('no_verified_phone', $this->deliveryFor($user, 'sms')->error_code);
        $this->assertSame('no_verified_email', $this->deliveryFor($user, 'email')->error_code);
        $this->assertSame('no_devices', $this->deliveryFor($user, 'push')->error_code);
        $this->assertSame([], $this->sms()->sent);
    }

    public function test_provider_failures_are_tracked_by_error_code_only(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966500000333']);
        $this->app->instance(SmsProvider::class, new class implements SmsProvider
        {
            public function send(string $to, string $message): string
            {
                throw new RuntimeException("cannot reach {$to}");
            }
        });

        $this->send($user); // must not throw: failures are recorded, not propagated

        $delivery = $this->deliveryFor($user, 'sms');
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('RuntimeException', $delivery->error_code); // never the message: it echoes the number
        $this->assertNotNull($delivery->failed_at);
        $this->assertStringNotContainsString('966500000333', json_encode(\DB::table('notification_deliveries')->get()));
    }

    public function test_a_failed_channel_does_not_block_the_others(): void
    {
        Mail::fake();
        $user = User::factory()->patient()->create(['phone' => '+966500000444']);
        $this->device($user, 'token-for-push-1234');
        $this->push()->failing = true;

        $this->send($user);

        $this->assertSame('failed', $this->deliveryFor($user, 'push')->status);
        $this->assertSame('sent', $this->deliveryFor($user, 'sms')->status);
        $this->assertSame('sent', $this->deliveryFor($user, 'email')->status);
    }

    public function test_stale_push_tokens_are_removed_and_valid_ones_still_receive(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user, 'stale-token-1234567');
        $this->device($user, 'fresh-token-1234567');
        $this->push()->invalidTokens = ['stale-token-1234567'];

        $this->send($user);

        $this->assertSame('sent', $this->deliveryFor($user, 'push')->status);
        $this->assertSame(['fresh-token-1234567'], array_column($this->push()->sent, 'token'));
        $this->assertSame(1, PushDevice::where('user_id', $user->id)->count());
    }

    public function test_only_stale_tokens_means_skipped(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user, 'stale-token-1234567');
        $this->push()->invalidTokens = ['stale-token-1234567'];

        $this->send($user);

        $delivery = $this->deliveryFor($user, 'push');
        $this->assertSame('skipped', $delivery->status);
        $this->assertSame('no_valid_devices', $delivery->error_code);
    }

    public function test_the_job_is_idempotent_once_settled(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966500000555']);
        $this->send($user);
        $delivery = $this->deliveryFor($user, 'sms');

        DeliverNotification::dispatchSync($delivery->id);
        DeliverNotification::dispatchSync($delivery->id);

        $this->assertCount(1, $this->sms()->sentTo('+966500000555'));
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_a_user_suspended_before_delivery_is_skipped(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966500000666']);
        $this->app->bind(\Illuminate\Contracts\Bus\Dispatcher::class, fn ($app) => new class($app) extends Dispatcher
        {
            public function dispatch($command)
            {
                return null; // hold the jobs back
            }
        });
        $this->send($user);
        $user->forceFill(['status' => 'suspended'])->save();

        DeliverNotification::dispatchSync($this->deliveryFor($user, 'sms')->id);

        $this->assertSame('user_inactive', $this->deliveryFor($user, 'sms')->error_code);
        $this->assertSame([], $this->sms()->sent);
    }

    public function test_on_a_real_queue_failures_retry_until_the_last_attempt_then_settle(): void
    {
        $user = User::factory()->patient()->create(['phone' => '+966500000777']);
        $this->send($user);
        $delivery = $this->deliveryFor($user, 'sms');
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0, 'sent_at' => null])->save();
        $this->sms()->sent = [];

        $this->app->instance(SmsProvider::class, new class implements SmsProvider
        {
            public function send(string $to, string $message): string
            {
                throw new RuntimeException('gateway down');
            }
        });

        $queued = function (int $attempt): DeliverNotification {
            $job = new DeliverNotification($this->deliveryFor(User::where('phone', '+966500000777')->firstOrFail(), 'sms')->id);
            $queueJob = \Mockery::mock(Job::class);
            $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $job->setJob($queueJob);

            return $job;
        };

        // Attempts 1..4: the exception goes back to the queue (which applies the backoff);
        // the delivery stays pending so it will be picked up again.
        try {
            $this->app->call([$queued(1), 'handle']);
            $this->fail('The job should rethrow so the queue can retry it.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertSame('RuntimeException', $delivery->fresh()->error_code);
        }

        // Attempt 5 (= $tries): settle as failed and let the job finish normally.
        $this->app->call([$queued(5), 'handle']);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->failed_at);
    }
}

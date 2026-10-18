<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\PushProvider;
use App\Modules\Notifications\Contracts\SmsProvider;
use App\Modules\Notifications\Exceptions\InvalidPushToken;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Services\NotificationRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one notification over one outbound channel and records the outcome.
 *
 * The delivery row keeps only status, attempts, a provider reference and an
 * error *code* - never the message text or the destination address.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(SmsProvider $sms, PushProvider $push, NotificationRenderer $renderer): void
    {
        $delivery = NotificationDelivery::with('notification.user')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NotificationDelivery::PENDING) {
            return;
        }

        $notification = $delivery->notification;
        $user = $notification->user;

        if (! $user->isActive()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, error: 'user_inactive');

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();
        $text = $renderer->render($notification, $user->preferred_locale);

        try {
            [$status, $reference, $error] = match ($delivery->channel) {
                'sms' => $this->viaSms($user->phone, $user->isPhoneVerified(), $text['body'], $sms),
                'email' => $this->viaEmail($user->email, $user->email_verified_at !== null, $user->preferred_locale, $text),
                default => $this->viaPush($user->id, $text, ['type' => $notification->type->value, 'notification_id' => $notification->id], $push),
            };
        } catch (Throwable $e) {
            $code = $this->errorCode($e);

            // Retry with backoff while attempts remain. On the last attempt (or when running
            // inline, e.g. the sync driver) settle the delivery here instead of throwing, so one
            // broken channel can never stop the other channels or the caller's business action.
            if (! $this->isFinalAttempt()) {
                $delivery->forceFill(['error_code' => $code])->save();

                throw $e;
            }

            $this->finish($delivery, NotificationDelivery::FAILED, error: $code);
            // Only the class is logged: provider messages can echo phone numbers or tokens.
            Log::warning('Notification delivery failed.', ['delivery_id' => $delivery->id, 'channel' => $delivery->channel, 'error' => $code]);

            return;
        }

        $this->finish($delivery, $status, $reference, $error);
    }

    public function failed(Throwable $e): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);

        if ($delivery !== null && $delivery->status === NotificationDelivery::PENDING) {
            $this->finish($delivery, NotificationDelivery::FAILED, error: $this->errorCode($e));
        }
    }

    private function isFinalAttempt(): bool
    {
        return $this->job === null
            || $this->job->getConnectionName() === 'sync'
            || $this->attempts() >= $this->tries;
    }

    /** @return array{string, string|null, string|null} */
    private function viaSms(?string $phone, bool $verified, string $body, SmsProvider $sms): array
    {
        if ($phone === null || ! $verified) {
            return [NotificationDelivery::SKIPPED, null, 'no_verified_phone'];
        }

        return [NotificationDelivery::SENT, $sms->send($phone, $body), null];
    }

    /**
     * @param  array{title: string, body: string}  $text
     * @return array{string, string|null, string|null}
     */
    private function viaEmail(?string $email, bool $verified, string $locale, array $text): array
    {
        if ($email === null || ! $verified) {
            return [NotificationDelivery::SKIPPED, null, 'no_verified_email'];
        }

        Mail::to($email)->locale($locale)->send(new NotificationMail($text['title'], $text['body'], __('notifications.email_footer', [], $locale)));

        return [NotificationDelivery::SENT, null, null];
    }

    /**
     * @param  array{title: string, body: string}  $text
     * @param  array<string, string>  $data
     * @return array{string, string|null, string|null}
     */
    private function viaPush(string $userId, array $text, array $data, PushProvider $push): array
    {
        $devices = PushDevice::where('user_id', $userId)->get();

        if ($devices->isEmpty()) {
            return [NotificationDelivery::SKIPPED, null, 'no_devices'];
        }

        $reference = null;
        $lastError = null;

        foreach ($devices as $device) {
            try {
                $reference = $push->send($device->token, $text['title'], $text['body'], $data);
                $device->forceFill(['last_used_at' => now()])->save();
            } catch (InvalidPushToken) {
                $device->delete(); // stale registration
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if ($reference !== null) {
            return [NotificationDelivery::SENT, $reference, null];
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        return [NotificationDelivery::SKIPPED, null, 'no_valid_devices'];
    }

    private function finish(NotificationDelivery $delivery, string $status, ?string $reference = null, ?string $error = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'provider_reference' => $reference,
            'error_code' => $error,
            'sent_at' => $status === NotificationDelivery::SENT ? now() : null,
            'failed_at' => $status === NotificationDelivery::FAILED ? now() : null,
        ])->save();
    }

    /** Exception class only: provider messages can echo phone numbers or tokens. */
    private function errorCode(Throwable $e): string
    {
        return mb_substr(class_basename($e), 0, 100);
    }
}

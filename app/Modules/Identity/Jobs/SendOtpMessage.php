<?php

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Mail\OtpCodeMail;
use App\Modules\Notifications\Contracts\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a one-time code. The queued payload is encrypted because it
 * carries the plaintext code until delivery.
 */
class SendOtpMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(
        public readonly string $channel,
        public readonly string $destination,
        public readonly OtpPurpose $purpose,
        public readonly ?string $code,
        public readonly string $locale,
        // Text-only notice (e.g. "this number is already registered") instead of a code.
        public readonly ?string $noticeKey = null,
    ) {
        $this->onQueue('otp');
    }

    public function handle(SmsProvider $sms): void
    {
        $text = $this->noticeKey !== null
            ? __("medconnect.notices.{$this->noticeKey}", [], $this->locale)
            : __('medconnect.otp.message', ['code' => $this->code, 'minutes' => (int) ceil(config('medconnect.otp.ttl_seconds') / 60)], $this->locale);

        if ($this->channel === 'email') {
            Mail::to($this->destination)->locale($this->locale)->send(new OtpCodeMail($text));

            return;
        }

        $sms->send($this->destination, $text);
    }
}

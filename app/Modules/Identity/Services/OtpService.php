<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Jobs\SendOtpMessage;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues and verifies one-time passcodes.
 *
 * Codes and destinations are stored only as keyed HMAC digests. Verification
 * is attempt-limited per code, and a cooldown limits resends per destination.
 */
class OtpService
{
    public function __construct(private readonly Request $request) {}

    /**
     * Issues a code unless one was sent to this destination within the cooldown.
     * Returns false when suppressed by the cooldown (callers must not reveal this).
     */
    public function issue(OtpPurpose $purpose, string $channel, string $destination, ?User $user = null): bool
    {
        $destinationHash = $this->destinationHash($destination);

        $recent = OtpCode::query()
            ->where('destination_hash', $destinationHash)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds((int) config('medconnect.otp.resend_cooldown_seconds')))
            ->exists();

        if ($recent) {
            return false;
        }

        $code = $this->generateCode();

        DB::transaction(function () use ($purpose, $channel, $destinationHash, $code, $user) {
            // Only the newest code for a destination/purpose is ever valid.
            OtpCode::query()
                ->where('destination_hash', $destinationHash)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            OtpCode::create([
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'channel' => $channel,
                'destination_hash' => $destinationHash,
                'code_hash' => $this->codeHash($purpose, $destinationHash, $code),
                'expires_at' => now()->addSeconds((int) config('medconnect.otp.ttl_seconds')),
                'ip_address' => $this->request->ip(),
            ]);
        });

        SendOtpMessage::dispatch($channel, $destination, $purpose, $code, $user->preferred_locale ?? app()->getLocale());

        return true;
    }

    /**
     * Sends a plain notice (no code) to a destination, at most once per cooldown
     * window so the endpoint cannot be used to flood a number with messages.
     */
    public function notice(OtpPurpose $purpose, string $channel, string $destination, string $noticeKey, string $locale): void
    {
        $key = 'otp-notice:'.$noticeKey.':'.$this->destinationHash($destination);

        if (! Cache::add($key, true, (int) config('medconnect.otp.resend_cooldown_seconds'))) {
            return;
        }

        SendOtpMessage::dispatch($channel, $destination, $purpose, null, $locale, $noticeKey);
    }

    /**
     * Consumes a matching code. Returns null for wrong, expired, exhausted or
     * unknown codes - callers must respond identically in every case.
     */
    public function verify(OtpPurpose $purpose, string $destination, string $code): ?OtpCode
    {
        $destinationHash = $this->destinationHash($destination);

        return DB::transaction(function () use ($purpose, $destinationHash, $code) {
            /** @var OtpCode|null $otp */
            $otp = OtpCode::query()
                ->where('destination_hash', $destinationHash)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! $otp->isUsable()) {
                return null;
            }

            $fixed = config('medconnect.demo_fixed_code');
            $matches = (is_string($fixed) && $fixed !== '' && hash_equals($fixed, $code))
                || hash_equals($otp->code_hash, $this->codeHash($purpose, $destinationHash, $code));

            if (! $matches) {
                $otp->increment('attempts');

                return null;
            }

            $otp->forceFill(['consumed_at' => now(), 'attempts' => $otp->attempts + 1])->save();

            return $otp;
        });
    }

    public function destinationHash(string $destination): string
    {
        return hash_hmac('sha256', 'dest|'.mb_strtolower($destination), $this->key());
    }

    private function codeHash(OtpPurpose $purpose, string $destinationHash, string $code): string
    {
        return hash_hmac('sha256', $purpose->value.'|'.$destinationHash.'|'.$code, $this->key());
    }

    private function generateCode(): string
    {
        $length = (int) config('medconnect.otp.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function key(): string
    {
        $key = (string) config('medconnect.otp.hash_key');

        if ($key === '') {
            throw new RuntimeException('OTP_HASH_KEY is not configured.');
        }

        return $key;
    }
}

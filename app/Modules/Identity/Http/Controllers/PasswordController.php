<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Actions\AuthenticateWithPassword;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @tags Authentication
 */
class PasswordController extends Controller
{
    /**
     * Request a password reset code.
     *
     * Always responds 202 with the same body, whether or not an account exists.
     * The code is sent by SMS (phone identifier) or email (email identifier).
     *
     * @unauthenticated
     */
    public function forgot(ForgotPasswordRequest $request, OtpService $otp): JsonResponse
    {
        $identifier = $request->validated('identifier');
        $user = AuthenticateWithPassword::findByIdentifier($identifier);

        if ($user !== null && $user->status === UserStatus::Active) {
            [$channel, $destination] = $this->channelFor($user, $identifier);
            $otp->issue(OtpPurpose::PasswordReset, $channel, $destination, $user);
        }

        return response()->json(['data' => [
            'status' => 'reset_code_sent',
            'message' => 'If an account matches, a reset code has been sent.',
        ]], 202);
    }

    /**
     * Reset the password with the emailed / SMS code.
     *
     * Signs out every device. Unknown accounts and wrong codes return the
     * same `invalid_otp` error.
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request, OtpService $otp, AuditLogger $audit): JsonResponse
    {
        $identifier = $request->validated('identifier');
        $user = AuthenticateWithPassword::findByIdentifier($identifier);

        if ($user === null || $user->status !== UserStatus::Active) {
            throw ApiException::make('invalid_otp', 'The code is invalid or has expired.');
        }

        [$channel, $destination] = $this->channelFor($user, $identifier);

        if ($otp->verify(OtpPurpose::PasswordReset, $destination, $request->validated('code')) === null) {
            throw ApiException::make('invalid_otp', 'The code is invalid or has expired.');
        }

        DB::transaction(function () use ($user, $request, $channel, $audit) {
            $user->password = $request->validated('password');
            $user->forceFill([
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                // Receiving the code proves control of that channel.
                'phone_verified_at' => $channel === 'sms' ? ($user->phone_verified_at ?? now()) : $user->phone_verified_at,
                'email_verified_at' => $channel === 'email' ? ($user->email_verified_at ?? now()) : $user->email_verified_at,
            ])->save();

            $user->tokens()->delete();
            $audit->record('auth.password_reset', $user, ['actor' => $user, 'metadata' => ['channel' => $channel]]);
        });

        return response()->json(['data' => ['status' => 'password_reset']]);
    }

    /**
     * Change the password (signed in).
     *
     * Signs out every other device.
     */
    public function change(ChangePasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ApiException::make('invalid_password', 'The current password is incorrect.');
        }

        DB::transaction(function () use ($user, $request, $audit) {
            $user->password = $request->validated('password');
            $user->forceFill(['password_changed_at' => now()])->save();

            $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
            $audit->record('auth.password_changed', $user);
        });

        return response()->json(['data' => ['status' => 'password_changed']]);
    }

    /**
     * @return array{string, string}
     */
    private function channelFor(User $user, string $identifier): array
    {
        return str_contains($identifier, '@') ? ['email', (string) $user->email] : ['sms', (string) $user->phone];
    }
}

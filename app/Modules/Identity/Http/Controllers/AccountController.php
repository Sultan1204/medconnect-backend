<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\CodeRequest;
use App\Modules\Identity\Http\Requests\PasswordConfirmationRequest;
use App\Modules\Identity\Http\Requests\UpdateAccountRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The signed-in user's own account.
 *
 * @tags Account
 */
class AccountController extends Controller
{
    /**
     * Current account.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Update account details.
     *
     * Changing the email address marks it unverified and sends a code to the new address.
     */
    public function update(UpdateAccountRequest $request, OtpService $otp, AuditLogger $audit): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $emailChanged = array_key_exists('email', $data) && mb_strtolower((string) $data['email']) !== (string) $user->email;

        if ($emailChanged && $data['email'] !== null
            && User::withTrashed()->where('email', mb_strtolower($data['email']))->whereKeyNot($user->id)->exists()) {
            throw ApiException::make('email_unavailable', 'This email address cannot be used.');
        }

        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        $audit->record('account.updated', $user, ['changes' => AuditLogger::changedFields($user)]);

        if ($emailChanged && $user->email !== null) {
            $otp->issue(OtpPurpose::EmailVerification, 'email', $user->email, $user);
        }

        return new UserResource($user);
    }

    /**
     * Send an email verification code.
     */
    public function sendEmailVerification(Request $request, OtpService $otp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email === null || $user->email_verified_at !== null) {
            throw ApiException::make('email_not_pending_verification', 'There is no unverified email address on this account.');
        }

        $otp->issue(OtpPurpose::EmailVerification, 'email', $user->email, $user);

        return response()->json(['data' => ['status' => 'verification_pending']], 202);
    }

    /**
     * Verify the email address with the emailed code.
     */
    public function verifyEmail(CodeRequest $request, OtpService $otp, AuditLogger $audit): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email === null || $otp->verify(OtpPurpose::EmailVerification, $user->email, $request->validated('code')) === null) {
            throw ApiException::make('invalid_otp', 'The code is invalid or has expired.');
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $audit->record('account.email_verified', $user);

        return new UserResource($user);
    }

    /**
     * Deactivate the account.
     *
     * Signs out every device. Clinical and financial records are retained as
     * required by law; the account can be reactivated by support.
     */
    public function deactivate(PasswordConfirmationRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->validated('password'), $user->password)) {
            throw ApiException::make('invalid_password', 'The password is incorrect.');
        }

        DB::transaction(function () use ($user, $audit) {
            $user->forceFill([
                'status' => UserStatus::Deactivated,
                'status_changed_at' => now(),
                'status_reason' => 'self_deactivated',
            ])->save();
            $user->tokens()->delete();
            $audit->record('account.deactivated', $user, ['actor' => $user]);
        });

        return response()->json(['data' => ['status' => 'deactivated']]);
    }
}

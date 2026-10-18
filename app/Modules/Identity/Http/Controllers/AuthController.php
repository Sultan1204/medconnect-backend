<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Actions\AuthenticateWithPassword;
use App\Modules\Identity\Actions\RegisterUser;
use App\Modules\Identity\Data\DeviceInfo;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\ResendOtpRequest;
use App\Modules\Identity\Http\Requests\VerifyOtpRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\TokenIssuer;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Authentication
 */
class AuthController extends Controller
{
    /**
     * Register a patient or doctor account.
     *
     * Always responds 202 - whether or not the number is already registered -
     * and sends a 6-digit code by SMS. Complete registration with `POST /auth/verify-otp`.
     * Doctors must additionally complete profile verification before consulting.
     *
     * @unauthenticated
     */
    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $register->handle($request->validated());

        return $this->verificationPending();
    }

    /**
     * Verify the registration code.
     *
     * Marks the phone number as verified and signs the user in. Privileged roles
     * (e.g. doctors) receive an MFA enrolment token instead of a full token.
     *
     * @unauthenticated
     */
    public function verifyOtp(VerifyOtpRequest $request, OtpService $otp, TokenIssuer $tokens, AuditLogger $audit): JsonResponse
    {
        $phone = $request->validated('phone');

        $user = User::where('phone', $phone)->whereNull('phone_verified_at')->first();
        $consumed = $user ? $otp->verify(OtpPurpose::Registration, $phone, $request->validated('code')) : null;

        if ($user === null || $consumed === null) {
            throw $this->invalidOtp();
        }

        $user->forceFill(['phone_verified_at' => now(), 'last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->record('auth.phone_verified', $user, ['actor' => $user]);

        return response()->json(['data' => $tokens->afterPrimaryAuthentication($user, DeviceInfo::fromRequest($request))]);
    }

    /**
     * Resend the registration code.
     *
     * Always responds 202. Codes are rate limited per number.
     *
     * @unauthenticated
     */
    public function resendOtp(ResendOtpRequest $request, OtpService $otp): JsonResponse
    {
        $phone = $request->validated('phone');
        $user = User::where('phone', $phone)->whereNull('phone_verified_at')->first();

        if ($user !== null) {
            $otp->issue(OtpPurpose::Registration, 'sms', $phone, $user);
        }

        return $this->verificationPending();
    }

    /**
     * Sign in with phone/email and password.
     *
     * `status` is `authenticated` (full token), `mfa_required` (complete
     * `POST /auth/mfa/challenge` with `mfa_token`) or `mfa_enrollment_required`
     * (set up MFA via `POST /auth/mfa/setup` using `mfa_token`).
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, AuthenticateWithPassword $authenticate): JsonResponse
    {
        $result = $authenticate->handle(
            $request->validated('identifier'),
            $request->validated('password'),
            DeviceInfo::fromRequest($request),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Sign out the current device.
     */
    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $audit->record('auth.logout', $request->user());

        return response()->json(['data' => ['status' => 'signed_out']]);
    }

    /**
     * Sign out every device.
     */
    public function logoutAll(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->user()->tokens()->delete();
        $audit->record('auth.logout_all', $request->user());

        return response()->json(['data' => ['status' => 'signed_out']]);
    }

    private function verificationPending(): JsonResponse
    {
        return response()->json(['data' => [
            'status' => 'verification_pending',
            'message' => 'If the details are valid, a verification code has been sent.',
            'code_expires_in' => (int) config('medconnect.otp.ttl_seconds'),
        ]], 202);
    }

    private function invalidOtp(): ApiException
    {
        return ApiException::make('invalid_otp', 'The code is invalid or has expired.', 422);
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Data\DeviceInfo;
use App\Modules\Identity\Http\Requests\MfaCodeRequest;
use App\Modules\Identity\Http\Requests\PasswordConfirmationRequest;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Modules\Identity\Services\TokenIssuer;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * TOTP multi-factor authentication. Mandatory for doctors, pharmacy staff
 * and administrators; optional for patients.
 *
 * @tags Authentication - MFA
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Begin MFA enrolment.
     *
     * Returns a TOTP secret and `otpauth://` URL to render as a QR code.
     * Accepts an enrolment token (`mfa_enrollment_required`) or a full token.
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
            throw ApiException::conflict('mfa_already_enabled', 'Multi-factor authentication is already enabled.');
        }

        $this->audit->record('auth.mfa.enrollment_started', $user);

        return response()->json(['data' => $this->mfa->beginEnrollment($user)]);
    }

    /**
     * Confirm MFA enrolment.
     *
     * Enables MFA and returns single-use recovery codes (shown only once).
     * When called with an enrolment token, that token is replaced by a full token.
     */
    public function confirm(MfaCodeRequest $request, TokenIssuer $tokens): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $codes = $this->mfa->confirmEnrollment($user, (string) $request->validated('code'));

        if ($codes === null) {
            $this->audit->failure('auth.mfa.enrollment_confirmed', $user);

            throw ApiException::make('invalid_mfa_code', 'The authentication code is invalid.');
        }

        $this->audit->record('auth.mfa.enabled', $user);

        $data = ['status' => 'mfa_enabled', 'recovery_codes' => $codes];

        if (! $user->tokenCan(AccessToken::ABILITY_FULL)) {
            $user->currentAccessToken()->delete();
            $data += ['session' => $tokens->full($user, DeviceInfo::fromRequest($request))];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Complete sign-in with an MFA code.
     *
     * Requires the `mfa_token` from login. Provide either `code` or a `recovery_code`.
     */
    public function challenge(MfaCodeRequest $request, TokenIssuer $tokens): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Only challenge tokens may be exchanged here.
        if ($user->tokenCan(AccessToken::ABILITY_FULL)) {
            throw ApiException::forbidden('This session is already fully authenticated.', 'already_authenticated');
        }

        if (! $this->mfa->verify($user, $request->validated('code'), $request->validated('recovery_code'))) {
            $this->audit->failure('auth.mfa.challenge', $user);

            throw ApiException::make('invalid_mfa_code', 'The authentication code is invalid.');
        }

        $user->currentAccessToken()->delete();
        $this->audit->record('auth.mfa.challenge', $user, [
            'metadata' => ['method' => $request->filled('code') ? 'totp' : 'recovery_code'],
        ]);

        return response()->json(['data' => $tokens->full($user, DeviceInfo::fromRequest($request))]);
    }

    /**
     * Regenerate recovery codes.
     *
     * Requires the account password and a current TOTP code. Old codes stop working.
     */
    public function regenerateRecoveryCodes(PasswordConfirmationRequest $request): JsonResponse
    {
        $user = $this->confirmedUser($request);

        $codes = $this->mfa->regenerateRecoveryCodes($user);
        $this->audit->record('auth.mfa.recovery_codes_regenerated', $user);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /**
     * Disable MFA.
     *
     * Only allowed for accounts whose role does not require MFA.
     */
    public function disable(PasswordConfirmationRequest $request): JsonResponse
    {
        $user = $this->confirmedUser($request);

        if ($user->requiresMfa()) {
            throw ApiException::forbidden('Multi-factor authentication is mandatory for this account.', 'mfa_mandatory');
        }

        $this->mfa->disable($user);
        $this->audit->record('auth.mfa.disabled', $user);

        return response()->json(['data' => ['status' => 'mfa_disabled']]);
    }

    private function confirmedUser(PasswordConfirmationRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasMfaEnabled()) {
            throw ApiException::make('mfa_not_enabled', 'Multi-factor authentication is not enabled.');
        }

        if (! Hash::check($request->validated('password'), $user->password)
            || ! $this->mfa->verify($user, (string) $request->validated('code'))) {
            throw ApiException::make('invalid_credentials', 'The password or authentication code is incorrect.', 401);
        }

        return $user;
    }
}

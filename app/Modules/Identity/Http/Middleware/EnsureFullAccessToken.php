<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\AccessToken;
use App\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects restricted tokens (pending MFA challenge or enrolment) on every
 * route except the MFA endpoints themselves.
 */
class EnsureFullAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tokenCan(AccessToken::ABILITY_FULL)) {
            return $next($request);
        }

        if ($user->tokenCan(AccessToken::ABILITY_MFA_ENROLL)) {
            throw ApiException::forbidden('Multi-factor authentication must be set up before continuing.', 'mfa_enrollment_required');
        }

        throw ApiException::forbidden('Multi-factor authentication must be completed before continuing.', 'mfa_required');
    }
}

<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Enums\UserStatus;
use App\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended or deactivated accounts lose access immediately, even with a
 * still-valid token.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== UserStatus::Active) {
            throw ApiException::forbidden('This account is not active.', 'account_inactive');
        }

        return $next($request);
    }
}

<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\MfaService;
use App\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Additional authorisation for high-risk operations (approving doctors,
 * refunds, role changes...). The caller must send a fresh TOTP code in the
 * `X-MFA-Code` header; a short grace window avoids re-prompting for each
 * action in a batch.
 */
class RequireStepUpMfa
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $graceKey = 'step-up:'.$user->id.':'.$user->currentAccessToken()?->getKey();

        if (Cache::has($graceKey)) {
            return $next($request);
        }

        if (! $user->hasMfaEnabled()) {
            throw ApiException::forbidden('Multi-factor authentication must be enabled for this action.', 'mfa_enrollment_required');
        }

        $code = (string) $request->header('X-MFA-Code');

        if ($code === '' || ! $this->mfa->verify($user, $code)) {
            $this->audit->failure('auth.step_up', $user, ['metadata' => ['route' => $request->route()?->getName() ?? $request->path()]]);

            throw ApiException::forbidden('A current authentication code is required for this action (X-MFA-Code header).', 'step_up_required');
        }

        Cache::put($graceKey, true, (int) config('medconnect.step_up.grace_seconds'));

        return $next($request);
    }
}

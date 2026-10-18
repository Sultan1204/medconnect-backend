<?php

use App\Modules\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Modules\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Modules\Identity\Http\Middleware\RequireStepUpMfa;
use App\Support\Exceptions\ApiException;
use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Http\Middleware\AssignRequestId;
use App\Support\Http\Middleware\ForceJsonResponse;
use App\Support\Http\Middleware\SecurityHeaders;
use App\Support\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private/presence channel authorisation: POST /api/v1/broadcasting/auth
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'account.active', 'token.full']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        $middleware->api(prepend: [ForceJsonResponse::class, SetLocaleFromHeader::class], append: [SecurityHeaders::class]);
        $middleware->throttleApi('api');

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'token.full' => EnsureFullAccessToken::class,
            'mfa.step-up' => RequireStepUpMfa::class,
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // API clients are token based - never redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected domain errors are not application faults.
        $exceptions->dontReport([ApiException::class]);

        // Never attach request input (may hold credentials or clinical data) to logs.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'token']);

        $exceptions->render(new ApiExceptionRenderer);
    })->create();

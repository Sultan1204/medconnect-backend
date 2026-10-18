<?php

namespace App\Support\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converts every exception raised under /api into the platform's error envelope.
 * Messages for unexpected errors are generic so internals and clinical data
 * never leak to clients.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $details, $headers] = $this->describe($e);

        $body = ['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'details' => $details ?: null,
            'request_id' => $request->attributes->get('request_id'),
        ], fn ($v) => $v !== null)];

        if ($status >= 500 && config('app.debug')) {
            $body['error']['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ];
        }

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * @return array{int, string, string, array<string, mixed>, array<string, string>}
     */
    private function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details, []],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', ['fields' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.', [], []],
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException,
            $e instanceof PermissionUnauthorizedException => [403, 'forbidden', 'You are not allowed to perform this action.', [], []],
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [404, 'not_found', 'The requested resource was not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'The HTTP method is not supported for this resource.', [], []],
            $e instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Too many requests. Please try again later.', [], $this->retryHeaders($e)],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_error', $e->getStatusCode() < 500 ? ($e->getMessage() ?: 'Request could not be processed.') : 'Service unavailable.', [], $e->getHeaders()],
            default => [500, 'server_error', 'An unexpected error occurred.', [], []],
        };
    }

    /**
     * @return array<string, string>
     */
    private function retryHeaders(ThrottleRequestsException $e): array
    {
        return array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']));
    }
}

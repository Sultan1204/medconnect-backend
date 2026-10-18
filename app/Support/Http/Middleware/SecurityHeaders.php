<?php

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers for JSON API responses. API payloads may contain personal
 * or clinical data, so they must never be cached by browsers or proxies.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if (! $headers->has('Cache-Control') || ! str_contains((string) $headers->get('Cache-Control'), 'public')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

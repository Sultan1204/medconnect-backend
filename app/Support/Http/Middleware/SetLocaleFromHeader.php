<?php

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects Arabic or English for messages and localized fields from the
 * Accept-Language header.
 */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->getPreferredLanguage(config('medconnect.supported_locales'));

        if ($locale !== null && $request->headers->has('Accept-Language')) {
            app()->setLocale($locale);
        }

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}

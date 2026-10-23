<?php

/*
| Browser clients (Vue SPA) authenticate with bearer tokens, so credentials
| (cookies) are never needed cross-origin. Mobile apps are unaffected by CORS.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Accept-Language', 'X-Request-Id', 'Idempotency-Key', 'X-MFA-Code'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    'supports_credentials' => false,

];

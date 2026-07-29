<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|------------------------------------------------------------------------------
| The frontend is served from its own origin, so every API call is cross-origin.
|
| Origins are read from `FRONTEND_URL` (comma separated for staging and preview
| deployments) rather than wildcarded — `*` cannot be combined with credentials
| and would let any site drive a signed-in staff session.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
)));

return [

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    // Preview deployments get a generated subdomain, so allow a pattern when
    // one is configured.
    'allowed_origins_patterns' => array_values(array_filter([
        env('FRONTEND_ORIGIN_PATTERN'),
    ])),

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Requested-With',
        'X-Guest-Token', 'X-Locale', 'X-Socket-Id', 'X-XSRF-TOKEN',
    ],

    // Lets the client read pagination and rate-limit metadata.
    'exposed_headers' => [
        'Content-Language', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];

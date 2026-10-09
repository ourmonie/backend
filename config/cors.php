<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | The React client and this API are deployed on different origins (Vercel /
    | WhoGoHost), so the browser enforces CORS on every request. The production
    | origin is injected with FRONTEND_URL in .env.
    |
    | Credentials are enabled, so the response must echo one exact origin - a
    | wildcard would be rejected by the browser. FRONTEND_URL therefore has to
    | match the deployed frontend domain character for character.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://localhost:4173',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    // Preflight results are cached for a day so a mobile browser does not pay
    // an OPTIONS round-trip on every write request (PRD 40: fast on Nigerian
    // networks). Raise only if the allowed origins change frequently.
    'max_age' => 86400,

    'supports_credentials' => true,

];

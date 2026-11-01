<?php

/*
 | The frontend is a static site on a different domain from this API, so the browser needs permission to call it.
 | Set CORS_ALLOWED_ORIGINS in .env to the site's address, comma separated, e.g.
 |   CORS_ALLOWED_ORIGINS=https://app.example.com,https://staging.example.com
 | Requests are authenticated with a bearer token, not cookies, so credentials are not enabled.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'X-Store-Id', 'X-Branch-Id', 'X-Warehouse-Id'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];
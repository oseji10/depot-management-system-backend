<?php

return [
    // Shown in email subjects and footers.
    'app_name' => env('DEPOT_APP_NAME', 'Depot Pro'),

    // Where the web app lives. Links in emails point here (no trailing slash).
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    // Brand colour used in emails.
    'brand_color' => '#F40009',

    // Mixed crates are switched off for now. Set DEPOT_MIXED_CRATES=true to bring them back.
    'mixed_crates' => (bool) env('DEPOT_MIXED_CRATES', false),
];

<?php

return [
    // Shown in email subjects and footers.
    'app_name' => env('DEPOT_APP_NAME', 'Depot Pro'),

    // Where the web app lives. Links in emails point here (no trailing slash).
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    // Brand colour used in emails.
    'brand_color' => '#BC0F5B',
];

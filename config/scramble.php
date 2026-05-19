<?php

return [
    'api_path' => 'api',
    'api_domain' => null,
    'export_path' => 'api.json',
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Cryptocurrency portfolio and trading platform API.',
    ],
    'servers' => null,
    'middleware' => [
        'web',
        \Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess::class,
    ],
    'exclude_routes_without_response_types' => false,
    'extensions' => [],
];

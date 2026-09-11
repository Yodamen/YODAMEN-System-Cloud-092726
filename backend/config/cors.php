<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 0,
    'supports_credentials' => true,
];

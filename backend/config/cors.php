<?php

$localOrigins = implode(',', [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost',
    'capacitor://localhost',
]);

$configuredOrigins = env(
    'CORS_ALLOWED_ORIGINS',
    env('APP_ENV', 'production') === 'local' ? $localOrigins : '',
);

$allowedOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) $configuredOrigins)),
));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [
    '#^http://localhost:\d+$#',
    '#^http://127\.0\.0\.1:\d+$#',
],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];

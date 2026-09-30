<?php

$localOrigins = implode(',', [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost',
    'capacitor://localhost',
    'null', // Electron loads the packaged renderer from file:// in local development.
]);
$isLocal = env('APP_ENV', 'production') === 'local';

$configuredOrigins = env(
    'CORS_ALLOWED_ORIGINS',
    $isLocal ? $localOrigins : '',
);

$allowedOrigins = array_values(array_filter(
    array_map('trim', explode(',', (string) $configuredOrigins)),
));

if ($isLocal) {
    $allowedOrigins[] = 'null';
    $allowedOrigins = array_values(array_unique($allowedOrigins));
}

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $isLocal ? [
        '#^http://localhost:\d+$#',
        '#^http://127\.0\.0\.1:\d+$#',
    ] : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];

<?php

return [
    'enabled' => filter_var(env('PRODUCTION_BOOTSTRAP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'admin' => [
        'username' => env('PRODUCTION_ADMIN_USERNAME'),
        'password' => env('PRODUCTION_ADMIN_PASSWORD'),
        'email' => env('PRODUCTION_ADMIN_EMAIL'),
        'first_name' => env('PRODUCTION_ADMIN_FIRST_NAME'),
        'last_name' => env('PRODUCTION_ADMIN_LAST_NAME'),
    ],
    'registrar' => [
        'username' => env('PRODUCTION_REGISTRAR_USERNAME'),
        'password' => env('PRODUCTION_REGISTRAR_PASSWORD'),
        'email' => env('PRODUCTION_REGISTRAR_EMAIL'),
        'first_name' => env('PRODUCTION_REGISTRAR_FIRST_NAME'),
        'last_name' => env('PRODUCTION_REGISTRAR_LAST_NAME'),
        'employee_number' => env('PRODUCTION_REGISTRAR_EMPLOYEE_NUMBER'),
    ],
];

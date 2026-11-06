<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'document_analysis' => [
        'driver' => env('AI_DOCUMENT_ANALYZER', 'mock'),
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        ],
    ],

    'campus_weather' => [
        'enabled' => filter_var(env('CAMPUS_WEATHER_ENABLED', false), FILTER_VALIDATE_BOOL),
        'provider' => env('CAMPUS_WEATHER_PROVIDER', 'open_meteo'),
        'base_url' => env('CAMPUS_WEATHER_BASE_URL', 'https://api.open-meteo.com/v1/forecast'),
        'latitude' => (float) env('CAMPUS_WEATHER_LATITUDE', 14.7314),
        'longitude' => (float) env('CAMPUS_WEATHER_LONGITUDE', 121.1447),
        'location_name' => env('CAMPUS_WEATHER_LOCATION', 'Rodriguez, Rizal'),
        'cache_minutes' => (int) env('CAMPUS_WEATHER_CACHE_MINUTES', 45),
        'timeout' => (int) env('CAMPUS_WEATHER_TIMEOUT', 3),
    ],

];

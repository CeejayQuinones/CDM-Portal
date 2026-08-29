<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monitoring AI Help
    |--------------------------------------------------------------------------
    |
    | Supported providers: gemini, openai, ollama
    | Leave AI_API_KEY empty to use the built-in CDM coach fallback.
    |
    */
    'provider' => env('AI_PROVIDER', 'gemini'),
    'api_key' => env('AI_API_KEY', env('GEMINI_API_KEY', env('OPENAI_API_KEY'))),
    'model' => env('AI_MODEL', 'gemini-2.5-flash'),
    'openai_base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'ollama_base_url' => env('AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    'timeout' => (int) env('AI_TIMEOUT', 45),
    // Windows local PHP often lacks a CA bundle; keep true in production.
    'verify_ssl' => filter_var(env('AI_VERIFY_SSL', env('APP_ENV') === 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOL),
];

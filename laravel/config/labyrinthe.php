<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | Created in the client area (Developers > API keys). Keys starting with
    | "lsk_test_" use the sandbox: nothing is sent, no credit is used.
    | Keep it in .env, never in the browser or a mobile app.
    |
    */

    'api_key' => env('LABYRINTHE_API_KEY'),

    // API root. "http://localhost:8000" is completed with /api/public/v1.
    'base_url' => env('LABYRINTHE_BASE_URL', 'https://api.labyrinthe-rdc.com/api/public/v1'),

    // HTTP timeouts (seconds) and automatic retries, only when safe (SMS and
    // OTP sends carry an Idempotency-Key): never a second SMS, never a second OTP.
    'timeout' => (float) env('LABYRINTHE_TIMEOUT', 30),
    'connect_timeout' => (float) env('LABYRINTHE_CONNECT_TIMEOUT', 10),
    'max_retries' => (int) env('LABYRINTHE_MAX_RETRIES', 2),

    // Country code added to local numbers ("0812345678" -> "243812345678").
    'default_country_code' => (string) env('LABYRINTHE_DEFAULT_COUNTRY_CODE', '243'),

    // Approved Sender ID used when none is given. Null: the platform default.
    'sender' => env('LABYRINTHE_SENDER'),

    // Defaults of Labyrinthe::otp()->send(). Null: the account's OTP settings.
    // A template must contain {code}; optional tokens: {app_name}, {minutes}.
    'otp' => [
        'template' => env('LABYRINTHE_OTP_TEMPLATE'),
        'locale' => env('LABYRINTHE_OTP_LOCALE'),

        // Web OTP API: host name of the site that reads the code (e.g. "app.example.com"),
        // adds the line "@app.example.com #123456" for autofill (Chrome, Android). Null: none.
        'web_otp_domain' => env('LABYRINTHE_OTP_WEB_OTP_DOMAIN'),

        // Replace accents by ASCII in the OTP message (GSM-7, cheaper).
        'strip_accents' => (bool) env('LABYRINTHE_OTP_STRIP_ACCENTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Register the endpoint with Route::labyrintheWebhooks() (routes/api.php),
    | then enter its URL in the client area (Developers > Webhooks) and copy
    | the endpoint secret here.
    |
    */

    'webhook' => [
        'secret' => env('LABYRINTHE_WEBHOOK_SECRET'),

        // URI used by Route::labyrintheWebhooks() when called without argument.
        'path' => env('LABYRINTHE_WEBHOOK_PATH', 'webhooks/labyrinthe'),

        // Max age (seconds) of a delivery's signed timestamp (replay protection).
        'tolerance' => (int) env('LABYRINTHE_WEBHOOK_TOLERANCE', 300),

        // Ignore a delivery whose `id` was already processed (deliveries can repeat).
        'deduplicate' => (bool) env('LABYRINTHE_WEBHOOK_DEDUPLICATE', true),

        // Cache store for deduplication (null: default store) and retention in seconds.
        'cache_store' => env('LABYRINTHE_WEBHOOK_CACHE_STORE'),
        'cache_ttl' => (int) env('LABYRINTHE_WEBHOOK_CACHE_TTL', 86400),
    ],

    // Log channel for SDK logs (requests, retries). Null disables them.
    // The API key, OTP codes and full phone numbers are never logged.
    'log_channel' => env('LABYRINTHE_LOG_CHANNEL'),
];

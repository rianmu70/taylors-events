<?php

declare(strict_types=1);

/*
 * All settings in one place. Values come from .env (see .env.example).
 * Read them anywhere with config('db.host').
 */

$bool = static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);

return [
    'app' => [
        'name' => (string) env('APP_NAME', "Taylor's Events"),
        'env' => (string) env('APP_ENV', 'production'),
        'debug' => $bool(env('APP_DEBUG', false)),
        'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
        'timezone' => (string) env('APP_TIMEZONE', 'Asia/Kuala_Lumpur'),
        'locale' => 'en',
        'key' => (string) env('APP_KEY', ''),
        'dev_toolbar' => $bool(env('DEV_TOOLBAR', false)),
        // Secret behind the HMAC signature on QR tickets.
        'ticket_secret' => (string) (.env.example) ?? env('TICKET_SIGNING_KEY', '')),
    ],

    'session' => [
        // Longest gap between two requests before the session is dropped.
        'idle' => (int) env('SESSION_IDLE_MINUTES', env('SESSION_LIFETIME_MIN', 30)) * 60,
        // Longest life of a session after sign-in, however active the user is.
        'absolute' => (int) env('SESSION_ABSOLUTE_HOURS', 8) * 3600,
    ],

    'db' => [
        'host' => (string) env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => (string) env('DB_NAME', 'taylors_events'),
        'test_name' => (string) env('DB_TEST_NAME', 'taylors_events_test'),
        'user' => (string) env('DB_USER', 'taylors_app'),
        'pass' => (string) env('DB_PASS', ''),
    ],

    'security' => [
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),
    ],

    'uploads' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 5),
    ],
];

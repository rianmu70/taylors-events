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
        'ticket_signing_key' => (string) env('TICKET_SIGNING_KEY', ''),
        'session_lifetime_min' => (int) env('SESSION_LIFETIME_MIN', 120),
        'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'login_lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),
    ],

    'uploads' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 5),
    ],
];

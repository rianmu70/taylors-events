<?php

declare(strict_types=1);

/**
 * Application settings. Secrets and anything that differs per machine come
 * from .env; everything else is decided here, in one place.
 *
 * Read a value anywhere with config('events.per_page').
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
        'ticket_secret' => (string) env('TICKET_SECRET', ''),
    ],

    'db' => [
        'host' => (string) env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => (string) env('DB_NAME', 'taylors_events'),
        'user' => (string) env('DB_USER', 'taylors_app'),
        'pass' => (string) env('DB_PASS', ''),
    ],

    // What the browse and listing pages show.
    'events' => [
        'per_page' => 8,              // events listed per page on Browse
        'low_seats' => 5,             // at or below this many seats left, the meter turns red
        'filling_percent' => 70,      // at or above this fill, the meter turns amber
        'max_hours' => 12,            // longest event an organiser can create
        'clock_seconds' => 20,        // how often published events are moved to ongoing / completed
    ],

    'tickets' => [
        'checkin_opens_minutes' => 60, // the door opens this long before the start time
    ],

    'admin' => [
        'audit_per_page' => 40,
        'users_per_page' => 25,
    ],

    'session' => [
        'idle' => (int) env('SESSION_IDLE_MINUTES', 30) * 60,
        'absolute' => (int) env('SESSION_ABSOLUTE_HOURS', 8) * 3600,
    ],

    'security' => [
        'password_min' => 12,
        'reset_minutes' => 30,                 // life of a password reset link
        'login_max_fails' => 5,                // wrong passwords before the account locks
        'login_lock_minutes' => 15,
        'login_per_ip' => [20, 900],           // [attempts, seconds]
        'login_per_account' => [8, 900],
        'signup_per_ip' => [10, 3600],
        'reset_per_ip' => [5, 900],
        // Accounts can only be created with a university address.
        'email_domains' => ['taylors.edu.my', 'sd.taylors.edu.my'],
    ],
];

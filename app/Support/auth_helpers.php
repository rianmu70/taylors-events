<?php

declare(strict_types=1);

// Template helpers. Require this file wherever your other helper functions are loaded.

use App\Auth\Auth;
use App\Security\Csrf;

if (!function_exists('csrf_field')) {
    /** <form method="post" ...><?= csrf_field() ?> */
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    /** For JavaScript: <meta name="csrf-token" content="<?= e(csrf_token()) ?>"> then send it as the X-CSRF-Token header. */
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('auth_user')) {
    /** @return array{id:int, email:string, full_name:string, role:string, faculty_id:?int}|null */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('auth_role')) {
    function auth_role(string ...$roles): bool
    {
        return Auth::hasRole(...$roles);
    }
}

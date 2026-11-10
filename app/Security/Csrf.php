<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Request;

final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    /** Hidden input for every <form method="post">. Use the csrf_field() helper in templates. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Token from the form field, or from the X-CSRF-Token header for fetch() calls. */
    public static function verify(Request $request): bool
    {
        $sent = $request->input(self::FIELD) ?? $request->header('X-CSRF-Token') ?? '';
        $stored = $_SESSION['_csrf'] ?? '';
        return is_string($sent) && $sent !== '' && is_string($stored) && $stored !== '' && hash_equals($stored, $sent);
    }

    /** Defence in depth: when the browser sends Origin, it must match this host. */
    public static function originOk(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return $origin !== 'null';
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        if (!is_string($host)) {
            return false;
        }
        $originHost = strtolower($host . ($port !== null ? ':' . $port : ''));
        return hash_equals(strtolower((string) $request->header('Host')), $originHost);
    }
}

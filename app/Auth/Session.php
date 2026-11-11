<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Logger;
use Throwable;

/**
 * Starts the database-backed session with safe cookie settings.
 * Called by SessionMiddleware, so controllers never start sessions themselves.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Command line (tests, scripts): keep $_SESSION as a plain in-memory array.
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            return;
        }

        $idle     = (int) config('session.idle', 1800);      // 30 minutes without a request
        $absolute = (int) config('session.absolute', 28800); // 8 hours since sign-in, whatever happens
        $https    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';

        ini_set('session.use_strict_mode', '1');   // unknown IDs are discarded, which stops session fixation
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        // The __Host- prefix demands Secure + Path=/, so use it only on HTTPS (plain-HTTP localhost cannot).
        session_name($https ? '__Host-tev_sid' : 'tev_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_set_save_handler(new DbSessionHandler($idle), true);
        try {
            session_start();
        } catch (Throwable $e) {
            // The session store (the database) is unavailable. Carry on as a guest so pages that need no
            // database, such as /api/health, can still answer. Nobody is signed in and nothing is saved.
            Logger::exception($e);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            $_SESSION = [];
            return;
        }

        $now = time();
        if (!isset($_SESSION['_born'])) {
            $_SESSION['_born'] = $now;
        } elseif ($now - (int) $_SESSION['_born'] > $absolute) {
            self::reset();
        }
    }

    /** New session ID, same data. Call on sign-in and on any privilege change. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Throws away all session data and issues a new ID (logout, expiry, deleted user). */
    public static function reset(): void
    {
        $_SESSION = [];
        self::regenerate();
        $_SESSION['_born'] = time();
    }
}

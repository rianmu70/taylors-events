<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Security\RateLimiter;
use PDOException;

/**
 * Sign-up, sign-in, sign-out and "who is the current user".
 * The current user lives on the Request ('user' attribute), set by SessionMiddleware.
 */
final class Auth
{
    public const ROLES = ['student', 'organizer', 'admin'];

    private const MAX_FAILS = 5;     // wrong passwords before the account locks
    private const LOCK_MINUTES = 15;
    private const GENERIC_FAIL = 'auth.error.login_failed';

    private static ?string $dummyHash = null;

    // ---------------------------------------------------------- current user

    /** @return array{id:int, email:string, full_name:string, role:string, faculty_id:?int}|null */
    public static function user(): ?array
    {
        $user = App::request()?->attribute('user');
        return is_array($user) ? $user : null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], $roles, true);
    }

    /**
     * Called by SessionMiddleware on every request. Reads the user fresh from the
     * database, so a role change or a deleted account takes effect immediately,
     * and tells the audit triggers who is acting.
     */
    public static function resolve(Request $request): void
    {
        $request->setAttribute('user', null);

        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $row = Database::fetchOne(
                'SELECT id, email, full_name, role, faculty_id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
                [$uid],
            );
            if ($row === null) {
                Session::reset();
            } else {
                $row['id'] = (int) $row['id'];
                $request->setAttribute('user', $row);
            }
        }

        $user = $request->attribute('user');
        Database::setAuditContext(is_array($user) ? $user['id'] : null, $request->ip());
    }

    // -------------------------------------------------------------- register

    /** @return array{user_id?:int, errors?:array<string,string>} errors hold lang keys: print with t($key) */
    public static function register(Request $request, string $name, string $email, string $password, ?int $facultyId = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = self::normaliseEmail($email);
        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'auth.error.name';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'auth.error.email';
        }
        $length = mb_strlen($password);
        if ($length < 12 || $length > 128) {   // the interface text promises "at least 12 characters"
            $errors['password'] = 'auth.error.password_length';
        } elseif (mb_strtolower($password) === $email) {
            $errors['password'] = 'auth.error.password_is_email';
        }
        if ($facultyId !== null && Database::fetchValue('SELECT 1 FROM faculties WHERE id = ?', [$facultyId]) === null) {
            $errors['faculty'] = 'auth.error.faculty';
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        if (RateLimiter::hit('register:ip:' . $request->ip(), 10, 3600) > 0) {
            return ['errors' => ['form' => 'auth.error.signup_limited']];
        }

        try {
            // The role never comes from the request: public sign-up always creates a student.
            $id = Database::insert(
                "INSERT INTO users (faculty_id, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'student')",
                [$facultyId, $name, $email, password_hash($password, PASSWORD_ARGON2ID)],
            );
        } catch (PDOException $e) {
            if (Database::violatedRule($e) === 'DUPLICATE') {
                return ['errors' => ['email' => 'auth.error.email_taken']];
            }
            throw $e;
        }

        self::signIn($request, $id);
        return ['user_id' => $id];
    }

    // ----------------------------------------------------------------- login

    /** @return array{ok:bool, error?:string, retry_after?:int} error is a lang key: print with t($key) */
    public static function attempt(Request $request, string $email, string $password): array
    {
        $email = self::normaliseEmail($email);
        $accountKey = 'login:acct:' . hash('sha256', $email);

        $wait = max(
            RateLimiter::hit('login:ip:' . $request->ip(), 30, 900),
            RateLimiter::hit($accountKey, 8, 900),
        );
        if ($wait > 0) {
            return ['ok' => false, 'error' => 'auth.error.throttled', 'retry_after' => $wait];
        }

        $row = Database::fetchOne(
            'SELECT id, password_hash, (locked_until IS NOT NULL AND locked_until > NOW()) AS locked
               FROM users WHERE email = ? AND deleted_at IS NULL AND is_active = 1',
            [$email],
        );

        // One Argon2 check always runs, so unknown emails take as long as real ones.
        $valid = password_verify($password, $row['password_hash'] ?? self::dummyHash());

        if ($row === null || (int) $row['locked'] === 1) {
            return ['ok' => false, 'error' => self::GENERIC_FAIL];
        }
        if (!$valid) {
            self::recordFailure((int) $row['id']);
            return ['ok' => false, 'error' => self::GENERIC_FAIL];
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $row['id']]);
        }
        Database::execute('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$row['id']]);
        RateLimiter::reset($accountKey);

        self::signIn($request, (int) $row['id']);
        return ['ok' => true];
    }

    public static function logout(Request $request): void
    {
        Session::reset();
        $request->setAttribute('user', null);
        Database::setAuditContext(null, $request->ip());
    }

    // --------------------------------------------------------------- helpers

    private static function signIn(Request $request, int $userId): void
    {
        Session::regenerate();            // new ID on sign-in blocks session fixation
        $_SESSION['uid'] = $userId;
        unset($_SESSION['_csrf']);        // fresh CSRF token for the signed-in session
        self::resolve($request);
    }

    private static function recordFailure(int $userId): void
    {
        // MySQL assigns left to right: locked_until sees the OLD count, then the count updates.
        Database::execute(
            'UPDATE users SET
               locked_until       = IF(failed_login_count + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until),
               failed_login_count = IF(failed_login_count + 1 >= ?, 0, failed_login_count + 1)
             WHERE id = ?',
            [self::MAX_FAILS, self::LOCK_MINUTES, self::MAX_FAILS, $userId],
        );
    }

    private static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash('not-a-real-password', PASSWORD_ARGON2ID);
    }
}

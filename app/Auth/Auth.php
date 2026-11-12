<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Security\Audit;
use App\Security\RateLimiter;
use PDOException;

/**
 * Who is signed in, and the only code that creates or checks passwords.
 *
 * Passwords are hashed with Argon2id. Sign-in answers the same way for an
 * unknown address, a wrong password and a locked account, and spends the same
 * time on each, so the form cannot be used to discover who has an account.
 */
final class Auth
{
    public const ROLES = ['student', 'organizer', 'admin'];

    private static ?string $dummyHash = null;

    /** @return array{id:int,email:string,full_name:string,role:string,faculty_id:?int}|null */
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

    /** Load the signed-in user for this request. Called once by SessionMiddleware. */
    public static function resolve(Request $request): void
    {
        $request->setAttribute('user', null);
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $row = Database::fetchOne(
                'SELECT id, email, full_name, role, faculty_id FROM users
                  WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
                [$uid],
            );
            if ($row === null) {
                Session::reset();   // account removed or deactivated while signed in
            } else {
                $request->setAttribute('user', $row);
            }
        }
        Database::setAuditContext(self::id(), $request->ip());
    }

    /**
     * Create a student account and sign it in.
     *
     * @return array{errors: array<string,string>}|array{user_id:int}
     */
    public static function register(Request $request, string $name, string $email, string $password, ?int $facultyId, ?string $studentNumber): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = self::normaliseEmail($email);
        $studentNumber = $studentNumber === null || trim($studentNumber) === '' ? null : trim($studentNumber);
        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['full_name'] = t('auth.error.name');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $errors['email'] = t('auth.error.email');
        } elseif (!in_array(substr($email, (int) strrpos($email, '@') + 1), (array) config('security.email_domains'), true)) {
            $errors['email'] = t('auth.error.email_domain');
        }
        if ($error = self::passwordProblem($password, $email)) {
            $errors['password'] = $error;
        }
        if ($studentNumber !== null && preg_match('/^[0-9]{6,12}$/', $studentNumber) !== 1) {
            $errors['student_number'] = t('auth.error.student_number');
        }
        if ($facultyId === null || Database::fetchValue('SELECT 1 FROM faculties WHERE id = ?', [$facultyId]) === null) {
            $errors['faculty_id'] = t('auth.error.faculty');
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        [$max, $window] = (array) config('security.signup_per_ip');
        if (RateLimiter::hit('signup:ip:' . $request->ip(), (int) $max, (int) $window) > 0) {
            return ['errors' => ['form' => t('auth.error.too_many')]];
        }

        try {
            $id = Database::insert(
                "INSERT INTO users (faculty_id, student_number, full_name, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, 'student')",
                [$facultyId, $studentNumber, $name, $email, password_hash($password, PASSWORD_ARGON2ID)],
            );
        } catch (PDOException $e) {
            if (Database::violatedRule($e) === 'DUPLICATE') {
                return ['errors' => ['email' => t('auth.error.taken')]];
            }
            throw $e;
        }

        Audit::record('auth.register', 'user', $id, $id);
        self::signIn($request, $id);
        return ['user_id' => $id];
    }

    /** @return array{ok:bool, retry_after?:int} */
    public static function attempt(Request $request, string $email, string $password): array
    {
        $email = self::normaliseEmail($email);
        $accountKey = 'login:acct:' . hash('sha256', $email);
        [$ipMax, $ipWindow] = (array) config('security.login_per_ip');
        [$acctMax, $acctWindow] = (array) config('security.login_per_account');

        $wait = max(
            RateLimiter::hit('login:ip:' . $request->ip(), (int) $ipMax, (int) $ipWindow),
            RateLimiter::hit($accountKey, (int) $acctMax, (int) $acctWindow),
        );
        if ($wait > 0) {
            return ['ok' => false, 'retry_after' => $wait];
        }

        $row = Database::fetchOne(
            'SELECT id, password_hash, (locked_until IS NOT NULL AND locked_until > NOW()) AS locked
               FROM users WHERE email = ? AND deleted_at IS NULL AND is_active = 1',
            [$email],
        );
        // Always verify against some hash, so an unknown address costs the same time.
        $valid = password_verify($password, $row['password_hash'] ?? self::dummyHash());

        if ($row === null || (int) $row['locked'] === 1) {
            Audit::record('auth.login_failed', 'user', $row['id'] ?? null, null, null, ['reason' => $row === null ? 'unknown' : 'locked']);
            return ['ok' => false];
        }
        if (!$valid) {
            self::recordFailure((int) $row['id']);
            return ['ok' => false];
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $row['id']]);
        }
        Database::execute('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$row['id']]);
        RateLimiter::reset($accountKey);
        Audit::record('auth.login', 'user', (int) $row['id'], (int) $row['id']);
        self::signIn($request, (int) $row['id']);
        return ['ok' => true];
    }

    public static function logout(Request $request): void
    {
        if (($id = self::id()) !== null) {
            Audit::record('auth.logout', 'user', $id, $id);
        }
        Session::reset();
        $request->setAttribute('user', null);
        Database::setAuditContext(null, $request->ip());
    }

    /** Why a new password is not acceptable, or null when it is. */
    public static function passwordProblem(string $password, string $email): ?string
    {
        $min = (int) config('security.password_min', 10);
        $length = mb_strlen($password);
        if ($length < $min || $length > 128) {
            return t('auth.error.password_length', ['min' => $min]);
        }
        if (mb_strtolower($password) === mb_strtolower($email)) {
            return t('auth.error.password_is_email');
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    private static function signIn(Request $request, int $userId): void
    {
        Session::regenerate();          // a new session id at sign-in stops session fixation
        $_SESSION['uid'] = $userId;
        unset($_SESSION['_csrf']);      // and a new CSRF token with it
        self::resolve($request);
    }

    private static function recordFailure(int $userId): void
    {
        $max = (int) config('security.login_max_fails', 5);
        Database::execute(
            'UPDATE users SET
               locked_until       = IF(failed_login_count + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until),
               failed_login_count = IF(failed_login_count + 1 >= ?, 0, failed_login_count + 1)
             WHERE id = ?',
            [$max, (int) config('security.login_lock_minutes', 15), $max, $userId],
        );
        Audit::record('auth.login_failed', 'user', $userId, null, null, ['reason' => 'password']);
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

<?php

declare(strict_types=1);

/*
 * Tests for sign-in, sessions, roles, CSRF and rate limiting. No web server needed.
 *
 *   php scripts/test_auth.php
 *
 * Same style as test_core.php. Database tests create their own users inside
 * a transaction that is always rolled back, so no data changes.
 * Exit code 0 = all passed.
 */

use App\Auth\Auth;
use App\Auth\DbSessionHandler;
use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\ThrottleMiddleware;
use App\Security\Csrf;
use App\Security\RateLimiter;
use App\Security\SafeRedirect;

require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $result = $test();
        if ($result === true) {
            $passed++;
            echo "  PASS  $name\n";
            return;
        }
        $failed++;
        echo "  FAIL  $name\n        got: " . var_export($result, true) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name\n        " . $e::class . ': ' . $e->getMessage() . "\n";
    }
}

function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

/** @param array<string,mixed> $body @param array<string,mixed> $server */
function req(string $method, string $path, array $body = [], array $server = []): Request
{
    return new Request($method, $path, '', [], $body, array_merge(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'localhost'], $server));
}

function freshSession(): void
{
    $_SESSION = [];
    $_SERVER['HTTP_USER_AGENT'] = 'test-agent';
    Database::setAuditContext(null, null);
}

function rolledBack(callable $fn): mixed
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        freshSession();
        return $fn();
    } finally {
        $pdo->rollBack();
        freshSession();
    }
}

/** @return array{id:int,email:string,password:string} */
function makeUser(string $role = 'student', string $password = 'Correct-Horse-91'): array
{
    static $n = 0;
    $n++;
    $email = 'authtest' . $n . '.' . bin2hex(random_bytes(3)) . '@example.test';
    $faculty = Database::fetchValue('SELECT id FROM faculties ORDER BY id LIMIT 1');
    $id = Database::insert(
        'INSERT INTO users (faculty_id, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)',
        [$faculty, 'Auth Test', $email, password_hash($password, PASSWORD_ARGON2ID), $role],
    );
    return ['id' => $id, 'email' => $email, 'password' => $password];
}

function testApp(): App
{
    $router = new Router();
    $router->alias('auth', AuthMiddleware::class);
    $router->alias('role', RoleMiddleware::class);
    $router->alias('throttle', ThrottleMiddleware::class);
    $router->middleware([SessionMiddleware::class, CsrfMiddleware::class]);
    $router->get('/secret', fn (Request $r) => 'secret', ['auth']);
    $router->get('/api/secret', fn (Request $r) => ['ok' => true], ['auth']);
    $router->get('/admin', fn (Request $r) => 'admin', ['role:admin']);
    $router->get('/staff', fn (Request $r) => 'staff', ['role:organizer,admin']);
    $router->get('/hot', fn (Request $r) => 'ok', ['throttle:test,2,60']);
    $router->post('/post', fn (Request $r) => 'posted');
    return new App($router);
}

// ---------------------------------------------------------------------------
echo "Safe redirect\n";

check('same-site path is kept', fn () => SafeRedirect::path('/events/5?x=1') === '/events/5?x=1');
check('protocol-relative URL refused', fn () => SafeRedirect::path('//evil.example') === '/');
check('backslash trick refused', fn () => SafeRedirect::path('/\\evil.example') === '/');
check('absolute URL refused', fn () => SafeRedirect::path('https://evil.example') === '/');
check('line break refused', fn () => SafeRedirect::path("/ok\r\nSet-Cookie: a=b") === '/');
check('null and non-strings use the fallback', fn () => SafeRedirect::path(null, '/home') === '/home' && SafeRedirect::path(['x'], '/home') === '/home');

// ---------------------------------------------------------------------------
echo "CSRF\n";

freshSession();
$_SESSION['_csrf'] = 'tok-123';
check('token is stable inside one session', fn () => Csrf::token() === 'tok-123');
check('field() prints a hidden input with the token', fn () => str_contains(Csrf::field(), 'name="_csrf" value="tok-123"'));
check('form field token accepted', fn () => Csrf::verify(req('POST', '/x', ['_csrf' => 'tok-123'])) === true);
check('header token accepted (fetch)', fn () => Csrf::verify(req('POST', '/x', [], ['HTTP_X_CSRF_TOKEN' => 'tok-123'])) === true);
check('wrong token refused', fn () => Csrf::verify(req('POST', '/x', ['_csrf' => 'tok-999'])) === false);
check('missing token refused', fn () => Csrf::verify(req('POST', '/x')) === false);
check('same-host Origin accepted', fn () => Csrf::originOk(req('POST', '/x', [], ['HTTP_ORIGIN' => 'http://localhost'])) === true);
check('Origin with port compared with Host port', fn () => Csrf::originOk(req('POST', '/x', [], ['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:8080'])) === true);
check('other-site Origin refused', fn () => Csrf::originOk(req('POST', '/x', [], ['HTTP_ORIGIN' => 'http://evil.example'])) === false);
check('Origin "null" refused', fn () => Csrf::originOk(req('POST', '/x', [], ['HTTP_ORIGIN' => 'null'])) === false);
check('no Origin header is accepted', fn () => Csrf::originOk(req('POST', '/x')) === true);

// ---------------------------------------------------------------------------
echo "Middleware pipeline (no sign-in)\n";

$app = testApp();

check('guest on a protected page is redirected to /login with next', function () use ($app) {
    freshSession();
    $res = $app->handle(req('GET', '/secret'));
    return $res->status() === 302 && str_contains((string) $res->header('Location'), '/login?next=%2Fsecret') ? true : [$res->status(), $res->header('Location')];
});
check('guest on a protected API route gets a 401 envelope', function () use ($app) {
    freshSession();
    $res = $app->handle(req('GET', '/api/secret'));
    $data = json_decode($res->body(), true);
    return $res->status() === 401 && ($data['success'] ?? null) === false;
});
check('POST without a CSRF token is refused (419)', function () use ($app) {
    freshSession();
    return $app->handle(req('POST', '/post'))->status() === 419;
});
check('POST with a valid CSRF token passes', function () use ($app) {
    freshSession();
    $_SESSION['_csrf'] = 'tok-abc';
    return $app->handle(req('POST', '/post', ['_csrf' => 'tok-abc']))->body() === 'posted';
});
check('POST with the right token but a foreign Origin is refused', function () use ($app) {
    freshSession();
    $_SESSION['_csrf'] = 'tok-abc';
    return $app->handle(req('POST', '/post', ['_csrf' => 'tok-abc'], ['HTTP_ORIGIN' => 'http://evil.example']))->status() === 419;
});
check('GET is never CSRF-checked', function () use ($app) {
    freshSession();
    return $app->handle(req('GET', '/api/secret'))->status() === 401; // reached the auth check, not a 419
});
check('role middleware rejects an unknown role name', function () {
    $r = new Router();
    $r->alias('role', RoleMiddleware::class);
    $r->get('/x', fn () => 'x', ['role:amin']);
    return throws(fn () => $r->dispatch(req('GET', '/x')), LogicException::class);
});

// ---------------------------------------------------------------------------
echo "Database (sessions, sign-in, roles)\n";

check('session handler stores, reads and deletes a session', fn () => rolledBack(function () {
    $h = new DbSessionHandler(1800);
    $id = 'test' . bin2hex(random_bytes(8));
    $h->write($id, 'payload-1');
    $read = $h->read($id);
    $h->destroy($id);
    return $read === 'payload-1' && $h->read($id) === '' ? true : [$read];
}));
check('session is unreadable from a different User-Agent', fn () => rolledBack(function () {
    $id = 'test' . bin2hex(random_bytes(8));
    $_SERVER['HTTP_USER_AGENT'] = 'browser-A';
    (new DbSessionHandler(1800))->write($id, 'secret');
    $_SERVER['HTTP_USER_AGENT'] = 'browser-B';
    $other = new DbSessionHandler(1800);
    $stolen = $other->read($id);
    $other->write($id, 'overwritten');           // must not replace the real data
    $_SERVER['HTTP_USER_AGENT'] = 'browser-A';
    return $stolen === '' && (new DbSessionHandler(1800))->read($id) === 'secret' ? true : [$stolen];
}));
check('expired session is not returned', fn () => rolledBack(function () {
    $id = 'test' . bin2hex(random_bytes(8));
    $h = new DbSessionHandler(1800);
    $h->write($id, 'old');
    Database::execute('UPDATE user_sessions SET last_activity = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$id]);
    return $h->read($id) === '';
}));

check('correct password signs in (email is case-insensitive)', fn () => rolledBack(function () {
    $u = makeUser();
    $r = req('POST', '/login');
    $res = Auth::attempt($r, strtoupper($u['email']), $u['password']);
    return $res['ok'] === true && (int) ($_SESSION['uid'] ?? 0) === $u['id'] && ($r->attribute('user')['email'] ?? '') === $u['email'] ? true : [$res, $_SESSION];
}));
check('wrong password and unknown email give the same message', fn () => rolledBack(function () {
    $u = makeUser();
    $a = Auth::attempt(req('POST', '/login'), $u['email'], 'wrong-password-1');
    $b = Auth::attempt(req('POST', '/login'), 'nobody' . bin2hex(random_bytes(3)) . '@example.test', 'whatever-pass-1');
    return $a['ok'] === false && $b['ok'] === false && $a['error'] === $b['error'] && !isset($_SESSION['uid']);
}));
check('5 wrong passwords lock the account, even for the right password', fn () => rolledBack(function () {
    $u = makeUser();
    for ($i = 0; $i < 5; $i++) {
        Auth::attempt(req('POST', '/login'), $u['email'], 'bad-password-' . $i);
    }
    $locked = (int) Database::fetchValue('SELECT locked_until > NOW() FROM users WHERE id = ?', [$u['id']]);
    $res = Auth::attempt(req('POST', '/login'), $u['email'], $u['password']);
    return $locked === 1 && $res['ok'] === false && !isset($_SESSION['uid']);
}));
check('a successful sign-in resets the failed-attempt counter', fn () => rolledBack(function () {
    $u = makeUser();
    Auth::attempt(req('POST', '/login'), $u['email'], 'bad-password-1');
    Auth::attempt(req('POST', '/login'), $u['email'], 'bad-password-2');
    $res = Auth::attempt(req('POST', '/login'), $u['email'], $u['password']);
    return $res['ok'] === true && (int) Database::fetchValue('SELECT failed_login_count FROM users WHERE id = ?', [$u['id']]) === 0;
}));
check('a deactivated account cannot sign in', fn () => rolledBack(function () {
    $u = makeUser();
    Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$u['id']]);
    $res = Auth::attempt(req('POST', '/login'), $u['email'], $u['password']);
    return $res['ok'] === false && !isset($_SESSION['uid']);
}));
check('a deactivated account is signed out on the next request', fn () => rolledBack(function () {
    $app = testApp();
    $u = makeUser();
    $_SESSION = ['uid' => $u['id']];
    Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$u['id']]);
    $res = $app->handle(req('GET', '/secret'));
    return $res->status() === 302 && !isset($_SESSION['uid']);
}));
check('sign-in records last_login_at', fn () => rolledBack(function () {
    $u = makeUser();
    Auth::attempt(req('POST', '/login'), $u['email'], $u['password']);
    return Database::fetchValue('SELECT last_login_at FROM users WHERE id = ?', [$u['id']]) !== null;
}));
check('seeded demo accounts sign in with Demo@12345 and keep their roles', fn () => rolledBack(function () {
    $out = [];
    foreach (['admin@taylors.edu.my' => 'admin', 'organizer@taylors.edu.my' => 'organizer', 'student@sd.taylors.edu.my' => 'student'] as $email => $role) {
        freshSession();
        $r = req('POST', '/login');
        $res = Auth::attempt($r, $email, 'Demo@12345');
        $out[$email] = $res['ok'] === true && ($r->attribute('user')['role'] ?? '') === $role;
    }
    return !in_array(false, $out, true) ? true : $out;
}));
check('too many attempts per account hit the rate limiter (retry_after)', fn () => rolledBack(function () {
    $u = makeUser();
    $last = [];
    for ($i = 0; $i < 9; $i++) {
        $last = Auth::attempt(req('POST', '/login'), $u['email'], 'bad-password-' . $i);
    }
    return ($last['retry_after'] ?? 0) > 0 ? true : $last;
}));
check('logout clears the session data', fn () => rolledBack(function () {
    $u = makeUser();
    $r = req('POST', '/login');
    Auth::attempt($r, $u['email'], $u['password']);
    Auth::logout($r);
    return !isset($_SESSION['uid']) && $r->attribute('user') === null;
}));

check('register creates a student with an Argon2id hash and a lower-case email', fn () => rolledBack(function () {
    $email = 'New.User' . bin2hex(random_bytes(3)) . '@Example.test';
    $res = Auth::register(req('POST', '/register'), 'New Student', $email, 'long-enough-pw-1');
    $row = Database::fetchOne('SELECT role, email, password_hash FROM users WHERE id = ?', [$res['user_id'] ?? 0]);
    return $row !== null && $row['role'] === 'student' && $row['email'] === strtolower($email)
        && str_starts_with($row['password_hash'], '$argon2id$') && (int) ($_SESSION['uid'] ?? 0) === $res['user_id'] ? true : [$res, $row];
}));
check('register refuses a duplicate email', fn () => rolledBack(function () {
    $u = makeUser();
    $res = Auth::register(req('POST', '/register'), 'Someone Else', $u['email'], 'long-enough-pw-1');
    return ($res['errors']['email'] ?? '') === 'auth.error.email_taken';
}));
check('register refuses a short password, a bad email and a short name', fn () => rolledBack(function () {
    $res = Auth::register(req('POST', '/register'), 'A', 'not-an-email', 'short');
    return isset($res['errors']['name'], $res['errors']['email'], $res['errors']['password']) && !isset($res['user_id']);
}));

check('role change applies on the next request, no re-login needed', fn () => rolledBack(function () {
    $app = testApp();
    $u = makeUser('student');
    $_SESSION['uid'] = $u['id'];
    $before = $app->handle(req('GET', '/admin'))->status();
    Database::execute("UPDATE users SET role = 'admin' WHERE id = ?", [$u['id']]);
    $after = $app->handle(req('GET', '/admin'))->status();
    return $before === 403 && $after === 200 ? true : [$before, $after];
}));
check('role list lets organizer and admin in, student out', fn () => rolledBack(function () {
    $app = testApp();
    $out = [];
    foreach (['student' => 403, 'organizer' => 200, 'admin' => 200] as $role => $expected) {
        $u = makeUser($role);
        $_SESSION = ['uid' => $u['id']];
        $out[$role] = $app->handle(req('GET', '/staff'))->status() === $expected;
    }
    return !in_array(false, $out, true) ? true : $out;
}));
check('a deleted user is signed out on the next request', fn () => rolledBack(function () {
    $app = testApp();
    $u = makeUser();
    $_SESSION = ['uid' => $u['id']];
    Database::execute('UPDATE users SET deleted_at = NOW() WHERE id = ?', [$u['id']]);
    $res = $app->handle(req('GET', '/secret'));
    return $res->status() === 302 && !isset($_SESSION['uid']);
}));
check('audit context carries the signed-in user id and IP', fn () => rolledBack(function () {
    $app = testApp();
    $u = makeUser('admin');
    $_SESSION = ['uid' => $u['id']];
    $app->handle(req('GET', '/admin'));
    return (int) Database::fetchValue('SELECT @app_user_id') === $u['id'] && Database::fetchValue('SELECT @app_ip') === '198.51.100.7';
}));
check('guest requests clear the audit user id', fn () => rolledBack(function () {
    $app = testApp();
    $u = makeUser('admin');
    $_SESSION = ['uid' => $u['id']];
    $app->handle(req('GET', '/admin'));
    $_SESSION = [];
    $app->handle(req('GET', '/secret'));
    return Database::fetchValue('SELECT @app_user_id') === null;
}));

// ---------------------------------------------------------------------------
echo "Rate limiting\n";

check('limiter allows up to the maximum, then blocks, then reset reopens', fn () => rolledBack(function () {
    $key = 'test:' . bin2hex(random_bytes(6));
    $r = [RateLimiter::hit($key, 2, 60), RateLimiter::hit($key, 2, 60), RateLimiter::hit($key, 2, 60), RateLimiter::hit($key, 2, 60)];
    RateLimiter::reset($key);
    $reopened = RateLimiter::hit($key, 2, 60);
    return $r[0] === 0 && $r[1] === 0 && $r[2] > 0 && $r[3] > 0 && $reopened === 0 ? true : [$r, $reopened];
}));
check('throttle middleware returns 429 with Retry-After', fn () => rolledBack(function () {
    $app = testApp();
    $app->handle(req('GET', '/hot'));
    $app->handle(req('GET', '/hot'));
    $third = $app->handle(req('GET', '/hot'));
    return $third->status() === 429 && (int) $third->header('Retry-After') > 0 ? true : [$third->status(), $third->headers()];
}));

// ---------------------------------------------------------------------------
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

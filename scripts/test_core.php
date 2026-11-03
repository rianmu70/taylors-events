<?php

declare(strict_types=1);

/*
 * Tests for the PHP foundation (app/Core). No web server needed.
 *
 *   php scripts/test_core.php
 *
 * Database tests use the credentials from .env (or environment variables)
 * and run inside transactions that are rolled back, so no data changes.
 * Exit code 0 = all passed.
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

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

function request(string $method, string $path): Request
{
    return new Request($method, $path, '');
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag = 'x', private string $second = '')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader('X-Tag', $this->tag . $this->second . ($response->header('X-Tag') ?? ''));
    }
}

final class BlockMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::html('blocked', 403);
    }
}

// ---------------------------------------------------------------------------
echo "Env\n";

$envFile = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($envFile, "\xEF\xBB\xBFT_NAME=\"Taylor's Events\"\r\n"
    . "T_MODE=local            # local | production\n"
    . "# comment line\n"
    . "export T_EXPORTED=yes\n"
    . "T_EMPTY=\n"
    . "T_TRUE=true\n"
    . "T_HASH=abc#def\n"
    . "T_SINGLE='a # not a comment'\n"
    . "T_ESCAPED=\"say \\\"hi\\\"\"\n"
    . "PATH=should-not-win\n");
Env::load($envFile);
unlink($envFile);

check('double quotes keep the apostrophe; BOM and CRLF ignored', fn () => Env::get('T_NAME') === "Taylor's Events");
check('inline comment after a value is removed', fn () => Env::get('T_MODE') === 'local');
check('"export KEY=" form is accepted', fn () => Env::get('T_EXPORTED') === 'yes');
check('empty value stays an empty string', fn () => Env::get('T_EMPTY', 'default') === '');
check('"true" becomes a boolean', fn () => Env::get('T_TRUE') === true);
check('# without a space before it is part of the value', fn () => Env::get('T_HASH') === 'abc#def');
check('# inside single quotes is kept', fn () => Env::get('T_SINGLE') === 'a # not a comment');
check('escaped double quotes', fn () => Env::get('T_ESCAPED') === 'say "hi"');
check('real environment variables win over the file', fn () => Env::get('PATH') !== 'should-not-win');
check('missing key returns the default', fn () => Env::get('T_MISSING', 42) === 42);

// ---------------------------------------------------------------------------
echo "Request paths\n";

check('root', fn () => Request::split('/', '/index.php') === ['/', '']);
check('query string removed', fn () => Request::split('/events?x=1', '/index.php') === ['/events', '']);
check('trailing and double slashes normalised', fn () => Request::split('//events//5/', '/index.php') === ['/events/5', '']);
check('sub-folder install (XAMPP)', fn () => Request::split('/te/public/events/5', '/te/public/index.php') === ['/events/5', '/te/public']);
check('sub-folder with public/ hidden by the root .htaccess', fn () => Request::split('/te/events/5', '/te/public/index.php') === ['/events/5', '/te']);
check('index.php in the URL', fn () => Request::split('/te/public/index.php/events', '/te/public/index.php') === ['/events', '/te/public']);
check('prefix only strips a whole segment', fn () => Request::split('/te/publicity', '/te/public/index.php') === ['/publicity', '/te']);
check('percent-encoding decoded', fn () => Request::split('/search/caf%C3%A9', '/index.php') === ['/search/café', '']);
check('base path from SCRIPT_NAME', fn () => Request::basePathFrom('/te/public/index.php') === '/te/public' && Request::basePathFrom('/index.php') === '');

// ---------------------------------------------------------------------------
echo "Router\n";

$router = new Router();
$router->get('/', fn (Request $r) => 'home');
$router->get('/events/{slug}', fn (Request $r, string $slug) => "event:$slug");
$router->get('/events/{id:\d+}/edit', fn (Request $r, string $id) => "edit:$id");
$router->post('/events', fn (Request $r) => 'created');
$router->get('/api/data', fn (Request $r) => ['ok' => true]);
$router->alias('tag', TagMiddleware::class);
$router->get('/tagged', fn (Request $r) => 'ok', ['tag:a,b', new TagMiddleware('c')]);
$router->get('/blocked', function (Request $r): string {
    throw new RuntimeException('controller must not run');
}, [BlockMiddleware::class]);

check('static route', fn () => $router->dispatch(request('GET', '/'))->body() === 'home');
check('path parameter passed by name', fn () => $router->dispatch(request('GET', '/events/cloud-bootcamp'))->body() === 'event:cloud-bootcamp');
check('regex constraint matches digits', fn () => $router->dispatch(request('GET', '/events/12/edit'))->body() === 'edit:12');
check('regex constraint rejects letters (404)', fn () => throws(fn () => $router->dispatch(request('GET', '/events/abc/edit')), HttpException::class));
check('HEAD is answered by the GET route', fn () => $router->dispatch(request('HEAD', '/'))->body() === 'home');
check('array result becomes JSON', fn () => $router->dispatch(request('GET', '/api/data'))->body() === '{"ok":true}');
check('unknown path is 404', function () use ($router) {
    try {
        $router->dispatch(request('GET', '/nowhere'));
    } catch (HttpException $e) {
        return $e->status === 404;
    }
    return false;
});
check('wrong method is 405 with Allow header', function () use ($router) {
    try {
        $router->dispatch(request('DELETE', '/events'));
    } catch (HttpException $e) {
        return $e->status === 405 && $e->headers['Allow'] === 'POST';
    }
    return false;
});
check('middleware runs in order, alias receives arguments', fn () => $router->dispatch(request('GET', '/tagged'))->header('X-Tag') === 'abc');
check('middleware can stop the controller', fn () => $router->dispatch(request('GET', '/blocked'))->status() === 403);
check('unknown middleware is reported', function () {
    $r = new Router();
    $r->get('/', fn () => 'x', ['nope']);
    return throws(fn () => $r->dispatch(request('GET', '/')), LogicException::class);
});

// ---------------------------------------------------------------------------
echo "App error handling\n";

$errRouter = new Router();
$errRouter->get('/boom', fn () => throw new RuntimeException('secret detail'));
$errRouter->get('/forbidden', fn () => abort(403));
$errRouter->get('/api/boom', fn () => throw new RuntimeException('secret detail'));
$app = new App($errRouter);

check('exception becomes a 500 page', fn () => $app->handle(request('GET', '/boom'))->status() === 500);
check('abort(403) becomes a 403 page', fn () => $app->handle(request('GET', '/forbidden'))->status() === 403);
check('API errors use the JSON envelope', function () use ($app) {
    $data = json_decode($app->handle(request('GET', '/api/boom'))->body(), true);
    return $data['success'] === false && $data['error']['code'] === 'HTTP_500';
});
check('405 response carries the Allow header', fn () => $app->handle(request('POST', '/boom'))->header('Allow') === 'GET, HEAD');

// ---------------------------------------------------------------------------
echo "Views and escaping\n";

check('e() escapes HTML and quotes', fn () => e('<script>"x"\'y\'</script>') === '&lt;script&gt;&quot;x&quot;&apos;y&apos;&lt;/script&gt;');
check('e() handles null', fn () => e(null) === '');
check('view names with ".." are refused', fn () => throws(fn () => View::render('../../config/app'), InvalidArgumentException::class));
check('missing view is reported clearly', fn () => throws(fn () => View::render('pages/does-not-exist'), RuntimeException::class));
check('error page renders inside the layout', fn () => str_contains(View::render('errors/error', [
    'title' => 'T', 'status' => 404, 'message' => '<b>m</b>', 'exception' => null,
]), '&lt;b&gt;m&lt;/b&gt;'));

// ---------------------------------------------------------------------------
echo "Lang\n";

Lang::reset();
check('known key', fn () => t('action.save') === 'Save');
check('placeholder replaced', fn () => t('event.seats_left', ['count' => 5]) === '5 seats left');
check('missing key shows the key', fn () => t('nope.key') === 'nope.key');
check('every database rule has a message', function () {
    foreach (['VENUE_CLASH', 'CAPACITY', 'LIFECYCLE', 'RATING', 'AUDIT', 'SCANS', 'DUPLICATE'] as $rule) {
        if (!Lang::has("db.$rule")) {
            return $rule;
        }
    }
    return true;
});

// ---------------------------------------------------------------------------
echo "Database (" . config('db.user') . '@' . config('db.host') . '/' . config('db.name') . ")\n";

/** Runs $fn inside a transaction that is always rolled back. */
function rolledBack(callable $fn): mixed
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        return $fn();
    } finally {
        $pdo->rollBack();
    }
}

function ruleFrom(callable $fn): ?string
{
    try {
        rolledBack($fn);
    } catch (PDOException $e) {
        return Database::violatedRule($e);
    }
    return 'no error';
}

check('connects and reads seed data', fn () => (int) Database::fetchValue('SELECT COUNT(*) FROM users') > 0);
check('MySQL NOW() matches PHP time zone', function () {
    $diff = abs(strtotime((string) Database::fetchValue('SELECT NOW()')) - time());
    return $diff < 5 ? true : "difference of {$diff}s";
});
check('integer bound to LIMIT', fn () => count(Database::fetchAll('SELECT id FROM users ORDER BY id LIMIT ?', [3])) === 3);
check('named parameters', fn () => Database::fetchOne('SELECT role FROM users WHERE email = :email', ['email' => 'admin@taylors.edu.my'])['role'] === 'admin');
check('SQL injection attempt is treated as plain text', fn () => Database::fetchOne('SELECT id FROM users WHERE email = ?', ["' OR '1'='1"]) === null);
check('array parameter refused', fn () => throws(fn () => Database::query('SELECT ?', [[1]]), InvalidArgumentException::class));
check('transaction rolls back on error', function () {
    $before = Database::fetchValue('SELECT title FROM events ORDER BY id LIMIT 1');
    try {
        Database::transaction(function () {
            Database::execute('UPDATE events SET title = ? ORDER BY id LIMIT 1', ['Changed by test']);
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
    }
    return Database::fetchValue('SELECT title FROM events ORDER BY id LIMIT 1') === $before;
});
check('venue clash trigger -> VENUE_CLASH', fn () => ruleFrom(fn () => Database::execute(
    "UPDATE events SET status = 'pending_approval' WHERE title = 'Data Science Study Jam'"
)) === 'VENUE_CLASH');
check('illegal lifecycle jump -> LIFECYCLE', fn () => ruleFrom(fn () => Database::execute(
    "UPDATE events SET status = 'completed' WHERE title = 'Volunteer Orientation: New Intake'"
)) === 'LIFECYCLE');
check('overbooking CHECK -> chk_events_no_overbook', fn () => ruleFrom(fn () => Database::execute(
    "UPDATE events SET confirmed_count = capacity + 1 WHERE title = 'Cloud and DevOps Bootcamp'"
)) === 'chk_events_no_overbook');
check('duplicate email -> DUPLICATE', fn () => ruleFrom(fn () => Database::execute(
    "UPDATE users SET email = 'admin@taylors.edu.my' WHERE email = 'student@sd.taylors.edu.my'"
)) === 'DUPLICATE');
check('audit log records the actor and IP from setAuditContext', function () {
    $adminId = (int) Database::fetchValue("SELECT id FROM users WHERE email = 'admin@taylors.edu.my'");
    $row = rolledBack(function () use ($adminId) {
        Database::setAuditContext($adminId, '10.20.30.40');
        Database::execute("UPDATE events SET title = 'Audit test' WHERE title = 'Design Thinking Sprint'");
        return Database::fetchOne('SELECT actor_id, ip_address, action FROM audit_logs ORDER BY id DESC LIMIT 1');
    });
    Database::setAuditContext(null, null);
    return $row !== null && (int) $row['actor_id'] === $adminId && $row['ip_address'] === '10.20.30.40'
        && $row['action'] === 'event.update' ? true : $row;
});

// ---------------------------------------------------------------------------
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

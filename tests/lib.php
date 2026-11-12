<?php

declare(strict_types=1);

/**
 * A test runner in one file, so the project needs no Composer packages.
 *
 *     test('a seat cannot be sold twice', function () { check($a === $b, 'explanation'); });
 *
 * Each suite file under tests/ calls test() several times. run.php loads the
 * suites that were asked for and prints one line per test.
 */

use App\Core\Database;

final class TestFailure extends RuntimeException
{
}

/** @var array<string, list<array{string, callable}>> $GLOBALS['__tests'] */
$GLOBALS['__tests'] = [];
$GLOBALS['__suite'] = '';

function suite(string $name): void
{
    $GLOBALS['__suite'] = $name;
}

function test(string $name, callable $body): void
{
    $GLOBALS['__tests'][$GLOBALS['__suite']][] = [$name, $body];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new TestFailure(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

/**
 * Run $work and require the database to refuse it for the named rule.
 * $rule is a trigger rule (VENUE_CLASH), a constraint name
 * (chk_events_no_overbook) or DENIED for a missing privilege.
 */
function refused(string $rule, callable $work, string $what): void
{
    try {
        $work();
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $actual = in_array($code, [1142, 1044, 1227, 1370, 1419], true) ? 'DENIED' : (Database::violatedRule($e) ?? 'SQL error ' . $code);
        if ($actual !== $rule) {
            throw new TestFailure("$what: refused, but for $actual instead of $rule (" . $e->getMessage() . ')');
        }
        return;
    }
    throw new TestFailure("$what: the database accepted it, expected $rule");
}

// ------------------------------------------------------------- connections

/** Credentials of a privileged account, used only to build fixtures. */
function root_credentials(): array
{
    return ['user' => (string) (getenv('DB_ROOT_USER') ?: 'root'), 'pass' => (string) (getenv('DB_ROOT_PASS') !== false ? getenv('DB_ROOT_PASS') : 'root')];
}

/** Run $work connected as the privileged account, in seed mode, then switch back to the application account. */
function as_root(callable $work): mixed
{
    Database::useCredentials(root_credentials());
    try {
        Database::execute('SET @seed_mode = 1');
        return $work();
    } finally {
        Database::useCredentials(null);
    }
}

/** Reload schema and demo data: every suite starts from the same known state. */
function reset_database(): void
{
    Database::disconnect();
    $root = root_credentials();
    $base = sprintf(
        'MYSQL_PWD=%s mysql -h%s -P%d -u%s',
        escapeshellarg($root['pass']),
        escapeshellarg((string) config('db.host')),
        (int) config('db.port'),
        escapeshellarg($root['user']),
    );
    foreach (['schema.sql', 'seed.sql'] as $file) {
        exec($base . ' < ' . escapeshellarg(BASE_PATH . '/database/' . $file) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, "Could not load $file:\n" . implode("\n", $output) . "\n");
            exit(2);
        }
    }
    @unlink(BASE_PATH . '/storage/cache/lifecycle.clock');
}

function user_id(string $email): int
{
    return (int) Database::fetchValue('SELECT id FROM users WHERE email = ?', [$email]);
}

function event_id(string $titleStart): int
{
    return (int) Database::fetchValue('SELECT id FROM events WHERE title LIKE ?', [$titleStart . '%']);
}

/** @return list<int> ids of students with no booking of any kind for the event */
function free_students(int $eventId, int $count): array
{
    return array_map('intval', array_column(Database::fetchAll(
        "SELECT u.id FROM users u WHERE u.role = 'student'
            AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.event_id = ?)
          ORDER BY u.id LIMIT ?",
        [$eventId, $count],
    ), 'id'));
}

/**
 * Run $work in $count separate processes that all start at the same instant,
 * each with its own database connection. Returns what each one returned.
 *
 * @param callable(int): string $work receives the process number, returns a short outcome
 * @return list<string>
 */
function in_parallel(int $count, callable $work): array
{
    Database::disconnect();                       // a connection must never be shared across a fork
    $startAt = microtime(true) + 0.6;
    $files = [];
    $pids = [];
    for ($i = 0; $i < $count; $i++) {
        $files[$i] = tempnam(sys_get_temp_dir(), 'tev');
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                Database::connection();           // connect first, so only the work itself races
                time_sleep_until($startAt);
                $outcome = $work($i);
            } catch (Throwable $e) {
                $outcome = 'error:' . $e::class . ':' . $e->getMessage();
            }
            file_put_contents($files[$i], $outcome);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $outcomes = [];
    foreach ($files as $file) {
        $outcomes[] = (string) file_get_contents($file);
        unlink($file);
    }
    return $outcomes;
}

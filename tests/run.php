<?php

declare(strict_types=1);

/**
 * Runs the test suites.
 *
 *     php tests/run.php                 every suite
 *     php tests/run.php unit database   only those
 *
 * Suites: unit, database, concurrency, http.
 * Needs a MySQL account that may load the schema (DB_ROOT_USER / DB_ROOT_PASS,
 * default root / root) and the application account from .env.
 * Exit code 0 means every test passed.
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';

$available = ['unit', 'database', 'concurrency', 'http'];
$wanted = array_slice($argv, 1) ?: $available;
if ($unknown = array_diff($wanted, $available)) {
    fwrite(STDERR, 'Unknown suite: ' . implode(', ', $unknown) . "\n");
    exit(2);
}

$failed = 0;
$passed = 0;
$started = microtime(true);

foreach ($wanted as $suite) {
    suite($suite);
    if ($suite !== 'unit') {
        reset_database();
    }
    require __DIR__ . '/' . ucfirst($suite) . 'Test.php';

    printf("\n%s\n%s\n", strtoupper($suite), str_repeat('-', 72));
    foreach ($GLOBALS['__tests'][$suite] ?? [] as [$name, $body]) {
        $t = microtime(true);
        try {
            $body();
            $passed++;
            printf("  PASS  %s (%d ms)\n", $name, (microtime(true) - $t) * 1000);
        } catch (Throwable $e) {
            $failed++;
            printf("  FAIL  %s\n        %s\n", $name, $e instanceof TestFailure ? $e->getMessage() : $e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
    if (function_exists('suite_teardown_' . $suite)) {
        ('suite_teardown_' . $suite)();
    }
}

if (array_diff($wanted, ['unit'])) {
    reset_database();   // leave the demo data clean for whoever runs the app next
}

printf("\n%s\n%d passed, %d failed, %.1f s\n", str_repeat('=', 72), $passed, $failed, microtime(true) - $started);
exit($failed === 0 ? 0 : 1);

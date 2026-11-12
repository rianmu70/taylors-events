<?php

declare(strict_types=1);

/**
 * Front controller: the only PHP file the web server can reach.
 * The web root is this folder, so app/, config/, database/ and .env are
 * outside it and cannot be requested from a browser.
 */

// PHP's built-in server (php -S) calls this file for every request. Returning
// false tells it to serve an existing static file (CSS, JS, fonts) itself.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file) && $file !== __FILE__
        && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->run();

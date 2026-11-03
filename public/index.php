<?php

declare(strict_types=1);

/*
 * Front controller: every request that is not a real file in /public lands
 * here. It loads the application and hands the request to the router.
 */

// PHP's built-in server (php -S) asks this script first for every request.
// Returning false tells it to serve a real file (CSS, JS, images) itself.
// The realpath check keeps it inside /public.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && $file !== __FILE__) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->run();

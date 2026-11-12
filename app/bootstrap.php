<?php

declare(strict_types=1);

/**
 * Builds the application: autoloader, configuration, error handling,
 * service container and routes. Returns the App, ready to run().
 * Also required by the test suites and the command line scripts.
 */

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit("Taylor's Events needs PHP 8.3 or newer. This server runs PHP " . PHP_VERSION . '.');
}

define('BASE_PATH', dirname(__DIR__));

// PSR-4 style autoloader: App\Services\BookingService -> app/Services/BookingService.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Router;

Env::load(BASE_PATH . '/.env');
Config::load(require BASE_PATH . '/config/app.php');
date_default_timezone_set((string) config('app.timezone', 'Asia/Kuala_Lumpur'));
ErrorHandler::register();

$container = new Container();
(require BASE_PATH . '/config/services.php')($container);

$router = new Router();
(require BASE_PATH . '/config/routes.php')($router);

return new App($router, $container);

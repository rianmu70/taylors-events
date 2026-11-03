<?php

declare(strict_types=1);

/*
 * Application bootstrap. Loaded once per request by public/index.php.
 * Order matters: autoloader -> helpers -> .env -> config -> error handling.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Taylor\'s Events needs PHP 8.2 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

define('BASE_PATH', dirname(__DIR__));

// Autoloader: App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Router;

Env::load(BASE_PATH . '/.env');
Config::load(require BASE_PATH . '/config/app.php');

date_default_timezone_set((string) config('app.timezone', 'Asia/Kuala_Lumpur'));
ErrorHandler::register();

$router = new Router();
(require BASE_PATH . '/config/routes.php')($router);

return new App($router);

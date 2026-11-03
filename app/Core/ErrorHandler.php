<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;

/**
 * Turns PHP warnings and notices into exceptions so mistakes such as an
 * undefined array key fail loudly in development instead of silently.
 * Deprecation notices are logged, not thrown.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect the @ operator.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning("Deprecated: $message in $file:$line");
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // Last resort for fatal errors that cannot be caught (parse errors,
        // out of memory). Prints a plain page instead of a blank screen.
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($error === null || !in_array($error['type'], $fatal, true)) {
                return;
            }
            Logger::error(sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            $detail = config('app.debug') === true
                ? '<pre>' . htmlspecialchars($error['message'] . ' in ' . $error['file'] . ':' . $error['line'], ENT_QUOTES, 'UTF-8') . '</pre>'
                : '';
            echo '<!doctype html><meta charset="utf-8"><title>Server error</title>'
                . '<h1>Something went wrong</h1><p>Please try again in a moment.</p>' . $detail;
        });
    }
}

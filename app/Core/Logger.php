<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Writes to storage/logs/app-YYYY-MM-DD.log (not web-accessible).
 * Falls back to PHP's own error log if the folder is not writable.
 */
final class Logger
{
    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARNING', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function exception(Throwable $e): void
    {
        self::write('ERROR', sprintf(
            "%s: %s in %s:%d\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));
    }

    private static function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $level, $message);
        $dir = BASE_PATH . '/storage/logs';
        $file = $dir . '/app-' . date('Y-m-d') . '.log';

        $written = is_dir($dir) && is_writable($dir)
            && @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
        if (!$written) {
            error_log(rtrim($line));
        }
    }
}

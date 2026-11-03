<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads KEY=VALUE pairs from the .env file.
 *
 * Real environment variables (for example the ones Docker sets) always win
 * over the file, so the same code runs locally and in containers.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === []) {
            return;
        }
        // Windows Notepad may save a byte-order mark before the first key.
        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]) ?? $lines[0];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            if (getenv($key) !== false) {
                continue;
            }
            self::$vars[$key] = self::parseValue(trim(substr($line, $eq + 1)));
        }
    }

    /**
     * Returns the value with "true", "false", "null" and "empty" converted.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$vars[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /** Clears values loaded from the file. Used by the core tests only. */
    public static function reset(): void
    {
        self::$vars = [];
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $out = '';
            $length = strlen($raw);
            for ($i = 1; $i < $length; $i++) {
                $char = $raw[$i];
                if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $next = $raw[++$i];
                    $out .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        default => $next,
                    };
                    continue;
                }
                if ($char === $quote) {
                    return $out;
                }
                $out .= $char;
            }
            // No closing quote: fall through and keep the raw text.
        }

        // Unquoted: anything after " #" is a comment.
        if (preg_match('/\s#/', $raw, $match, PREG_OFFSET_CAPTURE) === 1) {
            $raw = substr($raw, 0, $match[0][1]);
        }
        return trim($raw);
    }
}

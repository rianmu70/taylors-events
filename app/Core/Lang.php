<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Every piece of text the user sees lives in app/Views/lang/en.php.
 * Templates call t('action.save'). Changing a label means editing one line.
 *
 * A missing key is shown as the key itself, so it is easy to spot.
 */
final class Lang
{
    /** @var array<string, string>|null */
    private static ?array $lines = null;

    public static function get(string $key, array $replace = []): string
    {
        self::$lines ??= self::load((string) config('app.locale', 'en'));

        $line = self::$lines[$key] ?? null;
        if (!is_string($line)) {
            return $key;
        }
        if ($replace === []) {
            return $line;
        }

        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }
        return strtr($line, $pairs);
    }

    public static function has(string $key): bool
    {
        self::$lines ??= self::load((string) config('app.locale', 'en'));
        return isset(self::$lines[$key]);
    }

    /** Forgets the loaded file. Used by the core tests only. */
    public static function reset(): void
    {
        self::$lines = null;
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) !== 1) {
            $locale = 'en';
        }
        $file = BASE_PATH . "/app/Views/lang/$locale.php";
        if (!is_file($file)) {
            $file = BASE_PATH . '/app/Views/lang/en.php';
        }
        $lines = require $file;
        return is_array($lines) ? $lines : [];
    }
}

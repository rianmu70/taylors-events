<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

/**
 * Fixed-window limiter stored in rate_limits.
 * The counter is one atomic upsert, so parallel requests cannot undercount.
 */
final class RateLimiter
{
    /** Records one hit. Returns 0 when allowed, otherwise the seconds until the bucket reopens. */
    public static function hit(string $key, int $maxHits, int $windowSeconds): int
    {
        $key = substr($key, 0, 190);

        $wait = Database::fetchValue(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), blocked_until) FROM rate_limits WHERE bucket_key = ? AND blocked_until > NOW()',
            [$key],
        );
        if ($wait !== null) {
            return max(1, (int) $wait);
        }

        // MySQL assigns left to right: hits is decided before window_start is rewritten.
        Database::execute(
            'INSERT INTO rate_limits (bucket_key, hits, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               hits          = IF(window_start < (NOW() - INTERVAL ? SECOND), 1, hits + 1),
               window_start  = IF(window_start < (NOW() - INTERVAL ? SECOND), NOW(), window_start),
               blocked_until = NULL',
            [$key, $windowSeconds, $windowSeconds],
        );

        if ((int) Database::fetchValue('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$key]) > $maxHits) {
            Database::execute('UPDATE rate_limits SET blocked_until = NOW() + INTERVAL ? SECOND WHERE bucket_key = ?', [$windowSeconds, $key]);
            return $windowSeconds;
        }
        return 0;
    }

    /** Clears a bucket, for example after a successful sign-in. */
    public static function reset(string $key): void
    {
        Database::execute('DELETE FROM rate_limits WHERE bucket_key = ?', [substr($key, 0, 190)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

final class SafeRedirect
{
    /**
     * Only same-site paths survive. Blocks open redirects such as //evil.com, /\evil.com and CR/LF tricks.
     * The path is without the base path, because redirect() adds that.
     */
    public static function path(mixed $next, string $fallback = '/'): string
    {
        if (!is_string($next) || preg_match('#^/(?![/\\\\])[^\r\n]*$#', $next) !== 1) {
            return $fallback;
        }
        return $next;
    }
}

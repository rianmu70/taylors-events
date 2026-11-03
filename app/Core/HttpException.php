<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Throw this (or call abort()) to stop a request with an HTTP status,
 * for example 403 when a student opens an admin page.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }
}

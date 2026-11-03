<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * One outgoing HTTP response. Controllers return it; App::run() sends it.
 * Immutable: with...() methods return a changed copy.
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * Standard API envelope used by every /api route:
     * {"success": true, "data": ..., "error": null, "meta": {...}}
     *
     * @param array<string, mixed> $meta
     */
    public static function apiSuccess(mixed $data, array $meta = [], int $status = 200): self
    {
        return self::json(['success' => true, 'data' => $data, 'error' => null, 'meta' => (object) $meta], $status);
    }

    public static function apiError(string $message, int $status = 400, ?string $code = null, mixed $data = null): self
    {
        return self::json([
            'success' => false,
            'data' => $data,
            'error' => ['code' => $code ?? 'HTTP_' . $status, 'message' => $message],
            'meta' => (object) [],
        ], $status);
    }

    /** Redirect to a full URL or to a path that already includes the base path (use redirect() helper). */
    public static function redirect(string $location, int $status = 302): self
    {
        if (preg_match('/[\r\n]/', $location) === 1) {
            throw new InvalidArgumentException('Redirect location must not contain line breaks.');
        }
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new InvalidArgumentException('Header must not contain line breaks.');
        }
        $copy = clone $this;
        $copy->headers[$name] = $value;
        return $copy;
    }

    public function withStatus(int $status): self
    {
        $copy = clone $this;
        $copy->status = $status;
        return $copy;
    }

    public function withoutBody(): self
    {
        $copy = clone $this;
        $copy->body = '';
        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }
}

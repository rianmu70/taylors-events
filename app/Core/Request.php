<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One incoming HTTP request. Built once from PHP's globals.
 *
 * Middleware can attach values with setAttribute(), for example the signed-in
 * user, and controllers read them with attribute().
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query  $_GET
     * @param array<string, mixed> $body   $_POST, or the decoded JSON body
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $files  $_FILES
     * @param array<string, string> $cookies $_COOKIE
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        // HTML forms only send GET and POST. A hidden _method field lets a
        // POST form act as PUT, PATCH or DELETE.
        if ($method === 'POST' && isset($_POST['_method']) && is_string($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $body = $_POST;
        $contentType = strtolower((string) ($server['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        // PHP's built-in server reports the requested path as SCRIPT_NAME when it
        // looks like a file (/.env, /config/app.php). Only the front controller counts.
        if (!str_ends_with($scriptName, '/index.php')) {
            $scriptName = '/index.php';
        }
        [$path, $basePath] = self::split((string) ($server['REQUEST_URI'] ?? '/'), $scriptName);

        return new self($method, $path, $basePath, $_GET, $body, $server, $_FILES, $_COOKIE);
    }

    /**
     * The folder index.php lives in, as a URL: "" at the web root, or
     * "/taylors-events/public" when served from a sub-folder (XAMPP).
     */
    public static function basePathFrom(string $scriptName): string
    {
        $dir = str_replace('\\', '/', dirname($scriptName));
        return rtrim($dir === '.' ? '' : $dir, '/');
    }

    /**
     * Splits the URL into the path the router sees and the prefix links need.
     *
     *   /events/5                          -> ["/events/5", ""]
     *   /te/public/events/5                -> ["/events/5", "/te/public"]
     *   /te/public/index.php/events        -> ["/events",   "/te/public"]
     *   /te/events/5 (root .htaccess hides public/) -> ["/events/5", "/te"]
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $uri, string $scriptName): array
    {
        $path = rawurldecode(explode('?', $uri, 2)[0]);
        $base = self::basePathFrom($scriptName);

        // [prefix to strip, prefix for generated links]
        $candidates = [[$scriptName, $base], [$base, $base]];
        if (str_ends_with($base, '/public')) {
            $parent = substr($base, 0, -strlen('/public'));
            $candidates[] = [$parent, $parent];
        }

        foreach ($candidates as [$prefix, $linkBase]) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return [self::normalise(substr($path, strlen($prefix))), $linkBase];
            }
        }
        return [self::normalise($path), $base];
    }

    private static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');
        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    /** Value from the form/JSON body first, then the query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        if ($value === null && in_array($key, ['HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH'], true)) {
            $value = $this->server[substr($key, 5)] ?? null;
        }
        return is_string($value) ? $value : null;
    }

    /**
     * The client's IP address. Proxy headers such as X-Forwarded-For are
     * ignored on purpose: anyone can fake them.
     */
    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** True for /api/... routes and for clients that ask for JSON. */
    public function wantsJson(): bool
    {
        if ($this->path === '/api' || str_starts_with($this->path, '/api/')) {
            return true;
        }
        return str_contains(strtolower((string) $this->header('Accept')), 'application/json');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** A trimmed string from the body or query. Arrays and missing values become ''. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** A whole number from the body or query, or null when it is not one. */
    public function int(string $key): ?int
    {
        $value = $this->input($key);
        return is_scalar($value) && preg_match('/^-?\d{1,10}$/', (string) $value) === 1 ? (int) $value : null;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}

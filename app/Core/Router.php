<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use LogicException;

/**
 * Maps a method + path to a controller action.
 *
 *     $router->get('/events/{slug}', [EventController::class, 'show']);
 *     $router->post('/events/{id:\d+}/register', [BookingController::class, 'store'], ['auth']);
 *
 * {name} matches one path segment; {name:regex} adds a pattern.
 * Path values are passed to the action by name:
 *
 *     public function show(Request $request, string $slug): Response
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, handler: callable|array{0: class-string, 1: string}, middleware: list<string|MiddlewareInterface>}> */
    private array $routes = [];

    /** @var array<string, class-string<MiddlewareInterface>> */
    private array $aliases = [];

    /** @var list<string|MiddlewareInterface> */
    private array $global = [];

    /** @param list<string|MiddlewareInterface> $middleware */
    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $path = '/' . trim($path, '/');
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => self::compile($path),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** Short name for a middleware class, used in route definitions: 'auth', 'role:admin'. */
    public function alias(string $name, string $class): void
    {
        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw new InvalidArgumentException("$class must implement " . MiddlewareInterface::class);
        }
        $this->aliases[$name] = $class;
    }

    /**
     * Middleware that runs on every request, including 404 and error pages.
     *
     * @param list<string|MiddlewareInterface> $middleware
     */
    public function middleware(array $middleware): void
    {
        $this->global = array_merge($this->global, $middleware);
    }

    /** @return list<MiddlewareInterface> */
    public function globalMiddleware(): array
    {
        return array_map(fn ($m) => $this->resolveMiddleware($m), $this->global);
    }

    /**
     * Finds the route and runs its middleware and action.
     *
     * @throws HttpException 404 when no route matches, 405 when only the method is wrong
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $middleware = array_map(fn ($m) => $this->resolveMiddleware($m), $route['middleware']);
            $handler = $route['handler'];

            return Pipeline::run(
                $middleware,
                $request,
                fn (Request $req): Response => $this->callHandler($handler, $req, $params),
            );
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            throw new HttpException(405, '', ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404);
    }

    /** @return list<array{method: string, path: string}> */
    public function routes(): array
    {
        return array_map(static fn ($r) => ['method' => $r['method'], 'path' => $r['path']], $this->routes);
    }

    private static function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^{}]+))?\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')',
            $path,
        );
        if ($regex === null) {
            throw new InvalidArgumentException("Invalid route path: $path");
        }
        $regex = '#^' . $regex . '$#u';
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException("Invalid route pattern: $path");
        }
        return $regex;
    }

    /** @param array<string, string> $params */
    private function callHandler(callable|array $handler, Request $request, array $params): Response
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            [$class, $action] = $handler;
            if (!class_exists($class)) {
                throw new LogicException("Controller not found: $class");
            }
            $controller = App::container()->get($class);
            if (!method_exists($controller, $action)) {
                throw new LogicException("Action not found: $class::$action");
            }
            $result = $controller->$action($request, ...$params);
        } elseif (is_callable($handler)) {
            $result = $handler($request, ...$params);
        } else {
            throw new LogicException('Route handler must be [Controller::class, "method"] or a callable.');
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        throw new LogicException('A controller action must return a Response, a string or an array.');
    }

    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        [$name, $args] = array_pad(explode(':', $middleware, 2), 2, '');
        $class = $this->aliases[$name] ?? $name;
        if (!class_exists($class) || !is_subclass_of($class, MiddlewareInterface::class)) {
            throw new LogicException("Unknown middleware: $middleware");
        }
        $arguments = $args === '' ? [] : array_map('trim', explode(',', $args));
        return new $class(...$arguments);
    }
}

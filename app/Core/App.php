<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Runs one request: global middleware -> router -> controller -> send.
 * Every error becomes a proper error page (HTML) or error envelope (JSON).
 */
final class App
{
    private static ?Request $current = null;

    public function __construct(private readonly Router $router)
    {
    }

    /** The request being handled right now (used by url() and the layout). */
    public static function request(): ?Request
    {
        return self::$current;
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request);

        if ($request->method === 'HEAD') {
            $response = $response->withoutBody();
        }
        $response->send();
    }

    /** Handles a request and always returns a Response. Public so tests can call it. */
    public function handle(Request $request): Response
    {
        self::$current = $request;

        // Guests still get their IP written to the audit log by the triggers.
        // Auth middleware replaces this with the signed-in user's id.
        Database::setAuditContext(null, $request->ip());

        try {
            return Pipeline::run(
                $this->router->globalMiddleware(),
                $request,
                fn (Request $req): Response => $this->dispatch($req),
            );
        } catch (Throwable $e) {
            // A global middleware failed, so render without it.
            return $this->renderThrowable($e, $request);
        }
    }

    private function dispatch(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return $this->renderThrowable($e, $request);
        }
    }

    private function renderThrowable(Throwable $e, Request $request): Response
    {
        if ($e instanceof HttpException) {
            $response = ErrorRenderer::render($e->status, $request, $e->getMessage());
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            return $response;
        }

        Logger::exception($e);
        return ErrorRenderer::render(500, $request, '', $e);
    }
}

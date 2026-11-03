<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Builds the error page (HTML) or error envelope (JSON) for a status code.
 * Exception details are shown only when APP_DEBUG=true.
 */
final class ErrorRenderer
{
    private const KNOWN = [400, 401, 403, 404, 405, 419, 422, 429, 500, 503];

    public static function render(int $status, Request $request, string $message = '', ?Throwable $e = null): Response
    {
        $key = in_array($status, self::KNOWN, true) ? (string) $status : ($status >= 500 ? '500' : '400');
        $title = t("error.$key.title");
        $message = $message !== '' ? $message : t("error.$key.message");
        $debug = config('app.debug') === true && $e !== null;

        if ($request->wantsJson()) {
            $text = $debug ? $e::class . ': ' . $e->getMessage() : $message;
            return Response::apiError($text, $status);
        }

        try {
            $html = View::render('errors/error', [
                'title' => $title,
                'status' => $status,
                'message' => $message,
                'exception' => $debug ? $e : null,
            ]);
        } catch (Throwable $renderError) {
            // The error page itself failed (for example a broken layout).
            Logger::exception($renderError);
            $html = '<!doctype html><meta charset="utf-8"><title>' . e($title) . '</title>'
                . '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }
}

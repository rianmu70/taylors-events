<?php

declare(strict_types=1);

/*
 * Small global helpers used by controllers and templates.
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Response;
use App\Core\View;

/** Value from .env or the real environment. */
function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

/** Value from config/app.php, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/**
 * Escapes text for HTML. Use it for EVERY value printed in a template:
 * <?= e($event['title']) ?>. This is what stops XSS.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Interface text from app/Views/lang/en.php, e.g. t('action.save'). */
function t(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

/** Absolute path inside the app, including the sub-folder if there is one. */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    $base = App::request()?->basePath ?? '';
    return $base . '/' . ltrim($path, '/');
}

/** URL of a file in public/assets, with a version so browsers reload it after a change. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/public/assets/' . $path;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . $path) . $version;
}

/** The current path, e.g. "/events". Used to highlight the active menu item. */
function current_path(): string
{
    return App::request()?->path ?? '/';
}

/** A full HTML page response. */
function view(string $template, array $data = [], int $status = 200): Response
{
    return Response::html(View::render($template, $data), $status);
}

/** Renders a partial template (no layout) and returns the HTML. */
function partial(string $template, array $data = []): string
{
    return View::partial($template, $data);
}

/** Redirect to a path inside the app, e.g. redirect('/login'). */
function redirect(string $path, int $status = 302): Response
{
    return Response::redirect(url($path), $status);
}

/** Stops the request with an error page, e.g. abort(403). */
function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

/**
 * Formats a MySQL DATETIME for display, e.g. "Sat 24 Oct 2026, 4:00 pm".
 * The format string uses PHP date() letters.
 */
function format_datetime(?string $value, string $format = 'D j M Y, g:i a'): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Exception) {
        return $value;
    }
}

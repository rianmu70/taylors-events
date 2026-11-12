<?php

declare(strict_types=1);

/**
 * Global helper functions used by controllers and templates.
 * Templates: always print with e(), always fetch text with t().
 */

use App\Auth\Auth;
use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\Response;
use App\Core\View;
use App\Security\Csrf;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Escape a value for HTML. Every dynamic value in a template goes through this. */
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

/**
 * Text from app/Views/lang/en.php. Placeholders are written :name.
 *
 * @param array<string, int|string|null> $replace
 */
function t(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

/** Singular or plural text: t_count('seats.left', 3) reads "seats.left.one" or "seats.left.other". */
function t_count(string $key, int $count): string
{
    return Lang::get($key . ($count === 1 ? '.one' : '.other'), ['count' => number_format($count)]);
}

/** A link inside the app, correct even when the app lives in a sub-folder. */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    $base = App::request()?->basePath ?? '';
    return $base . '/' . ltrim($path, '/');
}

/** A full link for emails, where a relative path is no use. */
function absolute_url(string $path): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

/** A static file under public/assets, with a version so browsers refetch after an edit. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/public/assets/' . $path;
    return url('assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function current_path(): string
{
    return App::request()?->path ?? '/';
}

/** The current URL with some query values replaced. Used by filters and pagination. */
function query_url(array $changes): string
{
    $request = App::request();
    $query = [];
    foreach (['q', 'category', 'when', 'role', 'action', 'entity', 'actor', 'status', 'page'] as $key) {
        $value = $request?->query($key);
        if (is_scalar($value) && (string) $value !== '') {
            $query[$key] = (string) $value;
        }
    }
    $query = array_filter(array_merge($query, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return url(current_path()) . ($query === [] ? '' : '?' . http_build_query($query));
}

/** @param array<string, mixed> $data */
function view(string $template, array $data = [], int $status = 200): Response
{
    return Response::html(View::render($template, $data), $status);
}

/** @param array<string, mixed> $data */
function partial(string $template, array $data = []): string
{
    return View::partial($template, $data);
}

function redirect(string $path, int $status = 303): Response
{
    return Response::redirect(url($path), $status);
}

function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

function csrf_field(): string
{
    return Csrf::field();
}

/** @return array{id:int,email:string,full_name:string,role:string,faculty_id:?int}|null */
function auth_user(): ?array
{
    return Auth::user();
}

/** Queue a message for the next page the user sees. $type: success, error or info. */
function flash(string $type, string $text): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'text' => $text];
}

/** @return list<array{type:string,text:string}> */
function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

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

/** "Thu 12 Nov, 2:00 pm to 4:00 pm", or both dates when the event crosses midnight. */
function format_range(string $start, string $end): string
{
    $from = new DateTimeImmutable($start);
    $to = new DateTimeImmutable($end);
    $sameDay = $from->format('Y-m-d') === $to->format('Y-m-d');
    return t('time.range', [
        'from' => $from->format('D j M Y, g:i a'),
        'to' => $to->format($sameDay ? 'g:i a' : 'D j M, g:i a'),
    ]);
}

/**
 * How scarce the seats of an event are. The whole interface keys off this:
 * the meter, the colour of the count and the wording of the button.
 *
 * @return array{state:string,left:int,percent:int}
 *         state is open, filling, last or full
 */
function seat_state(int $capacity, int $confirmed): array
{
    $left = max(0, $capacity - $confirmed);
    $percent = $capacity > 0 ? (int) floor(100 * min($confirmed, $capacity) / $capacity) : 100;
    $state = match (true) {
        $left === 0 => 'full',
        $left <= (int) config('events.low_seats', 5) => 'last',
        $percent >= (int) config('events.filling_percent', 70) => 'filling',
        default => 'open',
    };
    return ['state' => $state, 'left' => $left, 'percent' => $percent];
}

/**
 * Turn a refused action into the right HTTP answer when it is about access,
 * not about data: 404 for something that does not exist, 403 for something
 * that is not yours. Any other rule falls through for the caller to show.
 */
function deny_unless_allowed(\App\Domain\RuleViolation $e): void
{
    if ($e->rule === 'NOT_FOUND') {
        abort(404);
    }
    if (in_array($e->rule, ['CLUB_SCOPE', 'NOT_YOURS'], true)) {
        abort(403);
    }
}

/** Unread notifications of the signed-in user, for the badge in the header. */
function unread_notifications(): int
{
    $id = Auth::id();
    return $id === null ? 0 : App::container()->get(\App\Services\Notifier::class)->unreadCount($id);
}

/** Page number from the query string, never below 1. */
function current_page(): int
{
    return max(1, (int) (App::request()?->int('page') ?? 1));
}

/** Set (from a page template) or read (from the layout) the title of the page being rendered. */
function page_title(?string $title = null): ?string
{
    static $current = null;
    if ($title !== null) {
        $current = $title;
    }
    return $current;
}

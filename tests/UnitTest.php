<?php

declare(strict_types=1);

/**
 * Unit tests: pure PHP, no database.
 */

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use App\Core\Validator;
use App\Domain\EventStatus;
use App\Security\SafeRedirect;
use App\Security\TicketSigner;

test('ticket: a signed ticket verifies and carries its ids', function () {
    $signer = new TicketSigner(str_repeat('k', 40));
    $nonce = TicketSigner::newNonce();
    $ticket = $signer->verify($signer->sign(7, 42, $nonce));
    check($ticket['valid'], 'a fresh ticket must verify');
    same([7, 42, $nonce], [$ticket['event_id'], $ticket['registration_id'], $ticket['nonce']], 'ticket contents');
});

test('ticket: changing any part breaks the signature', function () {
    $signer = new TicketSigner(str_repeat('k', 40));
    $payload = $signer->sign(7, 42, TicketSigner::newNonce());
    $parts = explode('.', $payload);
    foreach ([1 => '8', 2 => '43', 3 => str_repeat('0', 32)] as $index => $replacement) {
        $forged = $parts;
        $forged[$index] = $replacement;
        check(!$signer->verify(implode('.', $forged))['valid'], "part $index was changed but the ticket still verified");
    }
    check(!(new TicketSigner(str_repeat('x', 40)))->verify($payload)['valid'], 'a ticket signed with another key verified');
    check(!$signer->verify('not a ticket')['valid'], 'garbage verified');
});

test('ticket: the signature is not stored anywhere in the schema', function () {
    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    check(preg_match('/^\s+(signature|ticket_signature|hmac)\s/mi', $schema) === 0, 'schema.sql has a signature column');
});

test('lifecycle: the PHP state machine allows exactly the documented moves', function () {
    $allowed = [];
    foreach (EventStatus::cases() as $from) {
        foreach ($from->next() as $to) {
            $allowed[] = $from->value . '>' . $to->value;
        }
    }
    sort($allowed);
    same([
        'completed>archived', 'draft>pending_approval', 'ongoing>completed', 'pending_approval>draft',
        'pending_approval>published', 'published>draft', 'published>ongoing',
    ], $allowed, 'allowed moves');
});

test('seat meter: thresholds come from config and give four states', function () {
    same('open', seat_state(100, 10)['state'], '10 of 100');
    same('filling', seat_state(100, 80)['state'], '80 of 100');
    same('last', seat_state(100, 99)['state'], '99 of 100');
    same('full', seat_state(100, 100)['state'], '100 of 100');
    same(1, seat_state(30, 29)['left'], 'seats left');
});

test('language: every key the code asks for exists in en.php', function () {
    $lines = require BASE_PATH . '/app/Views/lang/en.php';
    $namespaces = array_unique(array_map(static fn (string $k): string => explode('.', $k)[0], array_keys($lines)));
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/lang/')) {
            continue;
        }
        // config('app.url') and audit action names look like keys but are not text.
        $source = preg_replace("/(config|Audit::record)\('[^']+'/", '', (string) file_get_contents($file->getPathname()));
        preg_match_all("/'((?:" . implode('|', $namespaces) . ")\.[A-Za-z0-9_.]+)'/", (string) $source, $found);
        foreach ($found[1] as $key) {
            if (str_ends_with($key, '.')) {
                continue;   // a prefix completed at run time, checked below
            }
            if (!isset($lines[$key]) && !isset($lines[$key . '.one'])) {
                $missing[] = $key . ' (' . basename($file->getPathname()) . ')';
            }
        }
    }
    same([], array_values(array_unique($missing)), 'missing language keys');
});

test('language: keys built at run time exist for every value', function () {
    $lines = require BASE_PATH . '/app/Views/lang/en.php';
    $need = [];
    foreach (EventStatus::cases() as $status) {
        $need[] = 'status.' . $status->value;
    }
    foreach (['admitted', 'duplicate', 'invalid_signature', 'wrong_event', 'not_confirmed', 'event_not_live'] as $result) {
        array_push($need, 'scan.' . $result, 'scan.log.' . $result);
    }
    foreach (App\Repositories\VenueRepository::TYPES as $type) {
        $need[] = 'venue.type.' . $type;
    }
    foreach (['booking_confirmed', 'booking_waitlisted', 'booking_promoted', 'booking_cancelled', 'checked_in', 'event_approved', 'event_rejected', 'approval_needed'] as $type) {
        array_push($need, "notify.$type.title", "notify.$type.body");
    }
    foreach (['booking_confirmed', 'booking_waitlisted', 'booking_promoted', 'event_approved', 'event_rejected', 'password_reset'] as $mail) {
        array_push($need, "mail.$mail.subject", "mail.$mail.body");
    }
    // Every rule a service or a trigger can raise needs a sentence.
    preg_match_all("/RuleViolation\('([A-Z_]+)'/", implode('', array_map('file_get_contents', glob(BASE_PATH . '/app/{Services,Controllers/*,Domain}/*.php', GLOB_BRACE))), $php);
    preg_match_all("/MESSAGE_TEXT = '([A-Z_]+):/", (string) file_get_contents(BASE_PATH . '/database/schema.sql'), $sql);
    foreach (array_unique(array_merge($php[1], $sql[1], ['EVENT_FULL', 'DUPLICATE'])) as $rule) {
        $need[] = 'rule.' . $rule;
    }
    same([], array_values(array_filter($need, static fn (string $k): bool => !isset($lines[$k]))), 'missing language keys');
});

test('language: no template contains hardcoded text', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (str_contains($file->getPathname(), '/lang/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $text = preg_replace('/<\?(php|=).*?\?>/s', '', $source);          // remove PHP
        $text = preg_replace('/<(script|style|svg)\b.*?<\/\1>/s', '', (string) $text);
        $text = strip_tags((string) $text);                                 // remove tags and their attributes
        if (preg_match('/[A-Za-z]{2,}[A-Za-z ]*/', (string) $text, $m) === 1) {
            $offenders[] = substr($file->getPathname(), strlen(BASE_PATH) + 1) . ': "' . trim($m[0]) . '"';
        }
    }
    same([], $offenders, 'templates with literal wording');
});

test('language: renaming a button is a one line change', function () {
    $key = "'booking.book'";
    same(1, substr_count((string) file_get_contents(BASE_PATH . '/app/Views/lang/en.php'), $key . ' =>'), 'definitions of booking.book');
    App\Core\Lang::reset();
    same('Book a seat', t('booking.book'), 'current wording');
});

test('escaping: e() neutralises markup', function () {
    same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'), 'escaped script tag');
    same('', e(null), 'null');
});

test('redirects: only local paths are followed after sign in', function () {
    same('/events/abc', SafeRedirect::path('/events/abc', '/'), 'local path');
    foreach (['https://evil.example', '//evil.example', '/\\evil.example', "/a\nb", 42, null] as $bad) {
        same('/home', SafeRedirect::path($bad, '/home'), 'unsafe target ' . var_export($bad, true));
    }
});

test('router: unknown path is 404, wrong method is 405', function () {
    $router = new Router();
    $router->get('/thing/{id:\d+}', static fn (Request $r, string $id): string => "thing $id");
    $request = static fn (string $method, string $path): Request => new Request($method, $path, '');
    same('thing 12', $router->dispatch($request('GET', '/thing/12'))->body(), 'matched route');
    foreach ([['GET', '/thing/abc', 404], ['GET', '/nothing', 404], ['POST', '/thing/12', 405]] as [$method, $path, $status]) {
        try {
            $router->dispatch($request($method, $path));
            check(false, "$method $path did not raise $status");
        } catch (HttpException $e) {
            same($status, $e->status, "$method $path");
        }
    }
});

test('container: builds a service and its dependencies once', function () {
    $container = new Container();
    $service = $container->get(App\Services\EventService::class);
    check($service instanceof App\Services\EventService, 'EventService was not built');
    check($service === $container->get(App\Services\EventService::class), 'the container built it twice');
});

test('validator: collects one message per field', function () {
    $v = new Validator(['title' => 'ab', 'capacity' => '0', 'starts_at' => '2026-13-40T10:00']);
    $v->required('title')->length('title', 5, 150);
    $v->required('capacity')->integer('capacity', 1, 5000);
    $v->required('starts_at')->dateTime('starts_at');
    $v->required('summary');
    same(['title', 'capacity', 'starts_at', 'summary'], array_keys($v->errors()), 'fields with errors');
    check(Validator::parseDateTime('2026-11-20T14:30') !== null, 'a datetime-local value did not parse');
});

test('assets: every script, style and font is served from this project', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (preg_match('/(src|href)\s*=\s*"(https?:)?\/\//', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }
    same([], $offenders, 'templates loading from another host');
    check(preg_match('/url\(\s*["\']?https?:/', (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css')) === 0, 'the stylesheet loads from another host');
    foreach (['js/vendor/qrcode.min.js', 'js/vendor/jsqr.min.js', 'fonts/bricolage-grotesque.woff2', 'fonts/atkinson-400.woff2'] as $asset) {
        check(is_file(BASE_PATH . '/public/assets/' . $asset), "$asset is not bundled");
    }
});

test('web root: nothing outside public/ is reachable', function () {
    same(['.htaccess', 'assets', 'index.php'], array_values(array_diff(scandir(BASE_PATH . '/public'), ['.', '..'])), 'contents of public/');
    check(!is_file(BASE_PATH . '/public/.env'), '.env is inside the web root');
});

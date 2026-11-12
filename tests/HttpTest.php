<?php

declare(strict_types=1);

/**
 * HTTP tests against the running application: smoke tests for every page,
 * authorisation tests for every role, and the demonstration flows end to end.
 *
 * The suite starts its own PHP server on a spare port, so it does not need
 * (or disturb) one you already have running.
 */

use App\Core\Database;

const TEST_HOST = '127.0.0.1:8765';
const DEMO_PASSWORD = 'Demo@12345';

/** A browser: keeps cookies, follows nothing, remembers the last CSRF token it saw. */
final class Browser
{
    private string $jar;
    public string $body = '';
    public int $status = 0;
    /** @var array<string,string> */
    public array $headers = [];
    private string $csrf = '';

    public function __construct()
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): self
    {
        return $this->send('GET', $path);
    }

    /** @param array<string,string|int> $fields */
    public function post(string $path, array $fields = [], bool $withToken = true): self
    {
        if ($withToken) {
            $fields['_csrf'] = $this->csrf;
        }
        return $this->send('POST', $path, http_build_query($fields));
    }

    /** @param array<string,mixed> $data */
    public function postJson(string $path, array $data): self
    {
        return $this->send('POST', $path, json_encode($data), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $this->csrf]);
    }

    public function signIn(string $email, string $password = DEMO_PASSWORD): self
    {
        $this->get('/login');
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function location(): string
    {
        return $this->headers['location'] ?? '';
    }

    /** @return mixed */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** @param list<string> $headers */
    private function send(string $method, string $path, ?string $body = null, array $headers = []): self
    {
        $curl = curl_init('http://' . TEST_HOST . $path);
        $this->headers = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_USERAGENT => 'TaylorsEventsTests/1.0',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $this->headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $this->body = (string) curl_exec($curl);
        $this->status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $this->body, $m) === 1
            || preg_match('/data-csrf="([0-9a-f]{64})"/', $this->body, $m) === 1) {
            $this->csrf = $m[1];
        }
        return $this;
    }
}

// --------------------------------------------------------------- the server
$server = proc_open(
    [PHP_BINARY, '-S', TEST_HOST, '-t', BASE_PATH . '/public', BASE_PATH . '/public/index.php'],
    [['file', '/dev/null', 'r'], ['file', sys_get_temp_dir() . '/taylors-events-test-server.log', 'w'], ['file', sys_get_temp_dir() . '/taylors-events-test-server.log', 'a']],
    $pipes,
    BASE_PATH,
    ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
);
$GLOBALS['__server'] = $server;
for ($i = 0; $i < 50; $i++) {
    usleep(100_000);
    if (@file_get_contents('http://' . TEST_HOST . '/api/health') !== false) {
        break;
    }
}

function suite_teardown_http(): void
{
    proc_terminate($GLOBALS['__server']);
}

function as_role(string $email): Browser
{
    static $browsers = [];
    if (!isset($browsers[$email])) {
        $browsers[$email] = new Browser();
        $response = $browsers[$email]->signIn($email);
        check($response->status === 303, "signing in as $email answered {$response->status}");
    }
    return $browsers[$email];
}

/** Assertions every HTML page must pass: this is what "one consistent product" means in a test. */
function check_page(Browser $page, string $path): void
{
    same(200, $page->status, "GET $path");
    foreach ([
        'one stylesheet, the shared one' => preg_match_all('/<link rel="stylesheet"/', $page->body) === 1 && str_contains($page->body, '/assets/css/app.css'),
        'exactly one h1' => preg_match_all('/<h1[ >]/', $page->body) === 1,
        'the shared header and footer' => str_contains($page->body, 'class="site-header"') && str_contains($page->body, 'class="site-footer"'),
        'a page title' => preg_match('/<title>[^<]+ \| Taylor&apos;s Events<\/title>/', $page->body) === 1,
        'a language attribute' => str_contains($page->body, '<html lang="en">'),
        // Audit action names (event.published) are data and are shown in <td class="code">.
        'no untranslated key' => preg_match('/>\s*[a-z_]+(\.[a-z_0-9]+)+\s*</', (string) preg_replace('/<td class="code">[^<]*<\/td>/', '', $page->body)) === 0,
        'no PHP warning or notice' => preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Stack trace/', $page->body) === 0,
        'no inline style or script' => preg_match('/\sstyle="|<script(?![^>]*\ssrc=)[^>]*>/', $page->body) === 0,
        'nothing loaded from another host' => preg_match('/(src|href)="https?:\/\/(?!' . preg_quote(TEST_HOST, '/') . ')/', $page->body) === 0,
    ] as $what => $ok) {
        check($ok, "$path is missing: $what");
    }
}

// --------------------------------------------------------------- smoke tests

test('smoke: the application is up and reaches its database', function () {
    $health = (new Browser())->get('/api/health');
    same(200, $health->status, 'GET /api/health');
    same('ok', $health->json()['status'], 'health status');
});

test('smoke: every public page renders', function () {
    $guest = new Browser();
    $slug = Database::fetchValue("SELECT slug FROM events WHERE title LIKE 'Capture the Flag%'");
    $code = Database::fetchValue("SELECT r.certificate_code FROM registrations r JOIN events e ON e.id = r.event_id WHERE e.status = 'completed' AND r.certificate_code IS NOT NULL LIMIT 1");
    foreach (['/', '/events', '/events?q=chess', '/events?category=workshops&when=week', '/events?page=2', "/events/$slug", '/login', '/register', '/forgot-password', "/verify/$code", '/reset-password/' . str_repeat('a', 64)] as $path) {
        check_page($guest->get($path), $path);
    }
    check(str_contains($guest->get("/events/$slug")->body, '1 seat left'), 'the last seat event does not say "1 seat left"');
    check(str_contains($guest->get('/events?q=chess')->body, 'Rapid Chess Open'), 'search did not find the chess event');
    check(!str_contains($guest->get('/events?q=chess')->body, 'Movie Night'), 'search returned unrelated events');
});

test('smoke: every student page renders', function () {
    $student = as_role('student@sd.taylors.edu.my');
    $me = user_id('student@sd.taylors.edu.my');
    $ticket = Database::fetchValue("SELECT r.id FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND e.status = 'ongoing'", [$me]);
    $certificate = Database::fetchValue('SELECT id FROM registrations WHERE user_id = ? AND certificate_code IS NOT NULL LIMIT 1', [$me]);
    foreach (['/events', '/my/registrations', "/my/tickets/$ticket", "/my/certificates/$certificate", '/profile', '/notifications'] as $path) {
        check_page($student->get($path), $path);
    }
    check(preg_match('/data-qr="v1\.\d+\.\d+\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}"/', $student->get("/my/tickets/$ticket")->body) === 1, 'the ticket page has no signed QR payload');
});

test('smoke: every organiser page renders', function () {
    $organiser = as_role('organizer@taylors.edu.my');
    $draft = event_id('Git and GitHub');
    $live = event_id('Build Night');
    foreach (['/organise/events', '/organise/events/new', "/organise/events/$draft/edit", "/organise/events/$live/check-in", '/profile', '/events'] as $path) {
        check_page($organiser->get($path), $path);
    }
    check(str_contains($organiser->get("/organise/events/$draft/edit")->body, 'This room is taken at that time.'), 'the clashing draft shows no clash warning');
    $csv = $organiser->get("/organise/events/$live/attendees.csv");
    same(200, $csv->status, 'attendee export');
    check(str_starts_with($csv->headers['content-type'] ?? '', 'text/csv'), 'the export is not CSV');
    same(35, substr_count(trim($csv->body), "\n") + 1, 'lines in the export (header + 34 attendees)');
});

test('smoke: every administrator page renders', function () {
    $admin = as_role('admin@taylors.edu.my');
    $venue = (int) Database::fetchValue('SELECT id FROM venues LIMIT 1');
    foreach (['/admin', '/admin/approvals', '/admin/venues', '/admin/venues/new', "/admin/venues/$venue/edit", '/admin/users', '/admin/users?q=tan&role=student', '/admin/audit', '/admin/audit?action=event.&entity=event', '/admin/outbox'] as $path) {
        check_page($admin->get($path), $path);
    }
});

test('smoke: error pages are part of the same product', function () {
    $guest = new Browser();
    $missing = $guest->get('/no-such-page');
    same(404, $missing->status, 'unknown URL');
    check(str_contains($missing->body, 'class="site-header"') && str_contains($missing->body, 'Page not found'), 'the 404 page is not the styled one');
    same(404, $guest->get('/events/no-such-event')->status, 'unknown event');
    same(405, $guest->get('/logout')->status, 'GET on a POST route');
    $forbidden = as_role('student@sd.taylors.edu.my')->get('/admin');
    check(str_contains($forbidden->body, 'class="error-page"') && str_contains($forbidden->body, 'You do not have access to this page'), 'the 403 page is not the styled one');
    // A real 500: render it the way the application does when a controller throws.
    $page = App\Core\ErrorRenderer::render(500, new App\Core\Request('GET', '/x', ''), '', new RuntimeException('boom'))->body();
    check(str_contains($page, 'Something went wrong on our side'), 'the 500 page has no message');
});

test('smoke: security headers are sent and nothing is loaded from the internet', function () {
    $page = (new Browser())->get('/events');
    check(str_contains($page->headers['content-security-policy'] ?? '', "default-src 'self'"), 'no content security policy');
    same('nosniff', $page->headers['x-content-type-options'] ?? '', 'X-Content-Type-Options');
    same('DENY', $page->headers['x-frame-options'] ?? '', 'X-Frame-Options');
    check(str_contains($page->headers['set-cookie'] ?? '', 'HttpOnly') && stripos($page->headers['set-cookie'] ?? '', 'SameSite=Lax') !== false, 'the session cookie is not HttpOnly and SameSite');
    foreach (['/assets/css/app.css', '/assets/js/app.js', '/assets/js/vendor/qrcode.min.js', '/assets/js/vendor/jsqr.min.js', '/assets/fonts/atkinson-400.woff2'] as $asset) {
        same(200, (new Browser())->get($asset)->status, $asset);
    }
    foreach (['/.env', '/../.env', '/config/app.php', '/database/schema.sql', '/app/bootstrap.php', '/storage/logs/'] as $private) {
        same(404, (new Browser())->get($private)->status, "$private must not be reachable");
    }
});

// --------------------------------------------------------- authorisation

test('authorisation: a guest is sent to sign in, never shown a private page', function () {
    $guest = new Browser();
    foreach (['/my/registrations', '/my/tickets/1', '/profile', '/notifications', '/organise/events', '/organise/events/new', '/admin', '/admin/users', '/admin/audit'] as $path) {
        $response = $guest->get($path);
        same(303, $response->status, "guest GET $path");
        check(str_starts_with($response->location(), '/login'), "guest GET $path redirects to {$response->location()}");
    }
});

test('authorisation: a student gets 403 on every organiser and administrator URL', function () {
    $student = as_role('student@sd.taylors.edu.my');
    $live = event_id('Build Night');
    foreach (['/admin', '/admin/approvals', '/admin/venues', '/admin/venues/new', '/admin/venues/1/edit', '/admin/users', '/admin/audit', '/admin/outbox',
              '/organise/events', '/organise/events/new', "/organise/events/$live/edit", "/organise/events/$live/check-in", "/organise/events/$live/attendees.csv"] as $path) {
        same(403, $student->get($path)->status, "student GET $path");
    }
});

test('authorisation: a student cannot perform organiser or administrator actions', function () {
    $student = as_role('student@sd.taylors.edu.my');
    $pending = event_id('Machine Learning Study Jam');
    $draft = event_id('Git and GitHub');
    $student->get('/profile');   // pick up a valid CSRF token: the refusal must be about the role
    foreach ([
        "/admin/approvals/$pending/approve" => [],
        "/admin/approvals/$pending/reject" => ['reason' => 'A reason that is long enough.'],
        '/admin/users/' . user_id('student@sd.taylors.edu.my') . '/role' => ['role' => 'admin'],
        '/admin/venues' => ['code' => 'X-1', 'name' => 'Injected room', 'building' => 'B', 'capacity' => 10, 'venue_type' => 'lab'],
        '/admin/venues/1/toggle' => [],
        '/organise/events' => ['title' => 'Injected event'],
        "/organise/events/$draft/submit" => [],
        "/organise/events/$draft/delete" => [],
        '/organise/events/' . event_id('Build Night') . '/check-in' => ['code' => 'AAAA-BBBB'],
    ] as $path => $fields) {
        same(403, $student->post($path, $fields)->status, "student POST $path");
    }
    same('pending_approval', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$pending]), 'the pending event');
    same('student', Database::fetchValue("SELECT role FROM users WHERE email = 'student@sd.taylors.edu.my'"), 'the student role');
    same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM venues WHERE code = 'X-1'"), 'injected venues');
});

test('authorisation: a student cannot read or cancel another student\'s ticket', function () {
    $student = as_role('student@sd.taylors.edu.my');
    $other = user_id('student2@sd.taylors.edu.my');
    $theirs = (int) Database::fetchValue("SELECT id FROM registrations WHERE user_id = ? AND status = 'confirmed' LIMIT 1", [$other]);
    $theirCertificate = (int) Database::fetchValue('SELECT id FROM registrations WHERE user_id <> ? AND certificate_code IS NOT NULL LIMIT 1', [user_id('student@sd.taylors.edu.my')]);

    $response = $student->get("/my/tickets/$theirs");
    same(403, $response->status, "GET someone else's ticket");
    check(!str_contains($response->body, 'data-qr='), 'the 403 page leaked a ticket');
    same(403, $student->get("/my/certificates/$theirCertificate")->status, "GET someone else's certificate");
    same(403, $student->get('/my/tickets/999999')->status, 'GET a ticket that does not exist');
    $student->get('/my/registrations');
    same(403, $student->post("/my/registrations/$theirs/cancel")->status, "POST cancel on someone else's booking");
    same('confirmed', Database::fetchValue('SELECT status FROM registrations WHERE id = ?', [$theirs]), 'their booking afterwards');
});

test('authorisation: an organiser is limited to their own club', function () {
    $tech = as_role('organizer@taylors.edu.my');
    $music = as_role('music@taylors.edu.my');
    $techDraft = event_id('Git and GitHub');
    $techLive = event_id('Build Night');

    foreach (["/organise/events/$techDraft/edit", "/organise/events/$techLive/check-in", "/organise/events/$techLive/attendees.csv"] as $path) {
        same(200, $tech->get($path)->status, "own club GET $path");
        same(403, $music->get($path)->status, "other club GET $path");
    }
    $music->get('/organise/events');
    same(403, $music->post("/organise/events/$techDraft/submit")->status, 'other club POST submit');
    same(403, $music->post("/organise/events/$techDraft/delete")->status, 'other club POST delete');
    same(403, $music->postJson("/organise/events/$techLive/check-in", ['code' => 'AAAA-BBBB'])->status, 'other club POST check-in');
    same(1, (int) Database::fetchValue('SELECT COUNT(*) FROM events WHERE id = ? AND deleted_at IS NULL', [$techDraft]), 'the draft still exists');

    // Creating an event for a club you do not run is refused by validation, and by the trigger behind it.
    $tech->get('/organise/events/new');
    $response = $tech->post('/organise/events', ['title' => 'Stolen stage', 'summary' => 'An event for a club I do not run.', 'description' => str_repeat('Not my club. ', 6),
        'club_id' => 6, 'category_id' => 3, 'venue_id' => 4, 'starts_at' => date('Y-m-d\TH:i', strtotime('+20 days 10:00')), 'ends_at' => date('Y-m-d\TH:i', strtotime('+20 days 12:00')), 'capacity' => 50]);
    same(422, $response->status, 'creating an event for another club');
    same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM events WHERE title = 'Stolen stage'"), 'events created for another club');

    foreach (['/admin', '/admin/approvals', '/admin/users', '/admin/audit'] as $path) {
        same(403, $tech->get($path)->status, "organiser GET $path");
    }
    same(403, $tech->post('/admin/approvals/' . event_id('Machine Learning Study Jam') . '/approve')->status, 'an organiser approving their own event');
    same(403, as_role('admin@taylors.edu.my')->get('/organise/events')->status, 'administrator GET /organise/events');
});

test('authorisation: draft and pending events are hidden from the public', function () {
    $draftSlug = Database::fetchValue('SELECT slug FROM events WHERE id = ?', [event_id('Git and GitHub')]);
    $pendingSlug = Database::fetchValue('SELECT slug FROM events WHERE id = ?', [event_id('Acoustic Night')]);
    same(404, (new Browser())->get("/events/$draftSlug")->status, 'guest GET a draft');
    same(404, as_role('student@sd.taylors.edu.my')->get("/events/$pendingSlug")->status, 'student GET a pending event');
    same(404, as_role('organizer@taylors.edu.my')->get("/events/$pendingSlug")->status, 'another club GET a pending event');
    same(200, as_role('music@taylors.edu.my')->get("/events/$pendingSlug")->status, 'the hosting club GET its pending event');
    same(200, as_role('admin@taylors.edu.my')->get("/events/$pendingSlug")->status, 'administrator GET a pending event');
    check(!str_contains((new Browser())->get('/events?q=git')->body, 'Git and GitHub'), 'a draft appears in search results');
});

test('security: a POST without a valid CSRF token is refused with 419', function () {
    $guest = new Browser();
    $guest->get('/login');
    same(419, $guest->post('/login', ['email' => 'admin@taylors.edu.my', 'password' => DEMO_PASSWORD], withToken: false)->status, 'sign in without a token');
    $student = new Browser();
    $student->signIn('student2@sd.taylors.edu.my');
    $slug = Database::fetchValue('SELECT slug FROM events WHERE id = ?', [event_id('Badminton')]);
    same(419, $student->post("/events/$slug/book", ['_csrf' => str_repeat('0', 64)], withToken: false)->status, 'booking with a forged token');
    same(419, $student->post('/logout', [], withToken: false)->status, 'sign out without a token');
    same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM registrations WHERE event_id = ? AND user_id = ?', [event_id('Badminton'), user_id('student2@sd.taylors.edu.my')]), 'bookings made without a token');
});

test('security: output is escaped, so stored markup cannot run', function () {
    $student = new Browser();
    $student->signIn('student2@sd.taylors.edu.my');
    $student->get('/profile');
    same(303, $student->post('/profile', ['full_name' => 'Marcus <script>alert(1)</script>', 'faculty_id' => 2, 'bio' => '"><img src=x onerror=alert(1)>'])->status, 'saving the profile');
    $page = $student->get('/profile');
    check(!str_contains($page->body, '<script>alert(1)</script>') && !str_contains($page->body, '<img src=x'), 'markup from the profile was printed unescaped');
    check(str_contains($page->body, 'Marcus &lt;script&gt;alert(1)&lt;/script&gt;'), 'the name was not shown escaped');
    as_root(fn () => Database::execute("UPDATE users SET full_name = 'Marcus Lee', bio = NULL WHERE email = 'student2@sd.taylors.edu.my'"));
});

test('security: sign in does not reveal which accounts exist, and is rate limited', function () {
    $guest = new Browser();
    $unknown = $guest->signIn('nobody@sd.taylors.edu.my', 'wrong-password-1');
    $wrong = $guest->signIn('admin@taylors.edu.my', 'wrong-password-1');
    same([422, 422], [$unknown->status, $wrong->status], 'status for unknown address and wrong password');
    preg_match('/class="flash flash--error"[^>]*>([^<]+)</', $unknown->body, $a);
    preg_match('/class="flash flash--error"[^>]*>([^<]+)</', $wrong->body, $b);
    same($a[1] ?? 'a', $b[1] ?? 'b', 'the two error messages');

    $limited = false;
    for ($i = 0; $i < 12 && !$limited; $i++) {
        $limited = $guest->signIn('grace.fernandez@taylors.edu.my', 'wrong-password-' . $i)->status === 429;
    }
    check($limited, 'twelve wrong passwords in a row were never rate limited');
    check(isset($guest->headers['retry-after']), 'the 429 has no Retry-After header');
    same(429, $guest->signIn('grace.fernandez@taylors.edu.my')->status, 'the right password while the account is limited');
    as_root(function () {
        Database::execute('DELETE FROM rate_limits');
        Database::execute('UPDATE users SET failed_login_count = 0, locked_until = NULL');
    });
});

test('accounts: create account validates, hashes with Argon2id and signs in', function () {
    $guest = new Browser();
    $guest->get('/register');
    $bad = $guest->post('/register', ['full_name' => 'Outside Person', 'email' => 'someone@gmail.com', 'password' => 'short', 'faculty_id' => 1]);
    same(422, $bad->status, 'an outside address and a short password');
    check(str_contains($bad->body, 'ending in taylors.edu.my') && str_contains($bad->body, 'at least 12 characters'), 'the form did not explain both problems');

    $ok = $guest->post('/register', ['full_name' => 'New Student', 'email' => 'new.student@sd.taylors.edu.my', 'password' => 'a-long-passphrase', 'faculty_id' => 1, 'student_number' => '0399999']);
    same(303, $ok->status, 'a valid registration');
    same(200, $guest->get('/my/registrations')->status, 'the new account is signed in as a student');
    $hash = (string) Database::fetchValue("SELECT password_hash FROM users WHERE email = 'new.student@sd.taylors.edu.my'");
    check(str_starts_with($hash, '$argon2id$') && !str_contains($hash, 'a-long-passphrase'), 'the password is not stored as an Argon2id hash');

    $guest2 = new Browser();
    $guest2->get('/register');
    same(422, $guest2->post('/register', ['full_name' => 'Copy Cat', 'email' => 'new.student@sd.taylors.edu.my', 'password' => 'another-long-one', 'faculty_id' => 1])->status, 'registering the same address twice');
});

test('accounts: password reset link is hashed at rest, works once, and expires', function () {
    $email = 'new.student@sd.taylors.edu.my';
    $guest = new Browser();
    $guest->get('/forgot-password');
    $known = $guest->post('/forgot-password', ['email' => $email]);
    $unknown = $guest->post('/forgot-password', ['email' => 'nobody@sd.taylors.edu.my']);
    same([200, 200], [$known->status, $unknown->status], 'status for a known and an unknown address');
    check(str_contains($known->body, 'If that address has an account') && str_contains($unknown->body, 'If that address has an account'), 'the answer differs between known and unknown addresses');

    $mail = (string) Database::fetchValue("SELECT body_text FROM mail_outbox WHERE to_email = ? AND template = 'password_reset' ORDER BY id DESC LIMIT 1", [$email]);
    check(preg_match('#/reset-password/([0-9a-f]{64})#', $mail, $m) === 1, 'no reset link in the outbox');
    $token = $m[1];
    $stored = Database::fetchOne('SELECT token_hash, TIMESTAMPDIFF(MINUTE, created_at, expires_at) AS life FROM password_resets ORDER BY id DESC LIMIT 1');
    same(hash('sha256', $token), $stored['token_hash'], 'what is stored is the SHA-256 of the token');
    check($stored['token_hash'] !== $token, 'the token itself is stored');
    same(30, (int) $stored['life'], 'minutes until the link expires');

    same(200, $guest->get("/reset-password/$token")->status, 'opening the link');
    same(422, $guest->post("/reset-password/$token", ['password' => 'brand-new-passphrase', 'password_confirm' => 'different'])->status, 'two different passwords');
    same(303, $guest->post("/reset-password/$token", ['password' => 'brand-new-passphrase', 'password_confirm' => 'brand-new-passphrase'])->status, 'setting the new password');
    check(str_contains($guest->get("/reset-password/$token")->body, 'has been used or has expired'), 'the link still works after being used');
    same(422, $guest->post("/reset-password/$token", ['password' => 'third-passphrase-x', 'password_confirm' => 'third-passphrase-x'])->status, 'using the link a second time');
    same(303, (new Browser())->signIn($email, 'brand-new-passphrase')->status, 'signing in with the new password');
    same(422, (new Browser())->signIn($email, 'a-long-passphrase')->status, 'signing in with the old password');

    // An expired link is refused even though it was never used.
    $guest->get('/forgot-password');
    $guest->post('/forgot-password', ['email' => $email]);
    preg_match('#/reset-password/([0-9a-f]{64})#', (string) Database::fetchValue("SELECT body_text FROM mail_outbox WHERE to_email = ? ORDER BY id DESC LIMIT 1", [$email]), $m);
    as_root(fn () => Database::execute('UPDATE password_resets SET created_at = NOW() - INTERVAL 40 MINUTE, expires_at = NOW() - INTERVAL 10 MINUTE WHERE used_at IS NULL'));
    check(str_contains($guest->get('/reset-password/' . $m[1])->body, 'has been used or has expired'), 'an expired link was accepted');
});

// ------------------------------------------------- the demonstration flows

test('flow: booking the last seat, then the waitlist, then promotion on cancel', function () {
    $event = event_id('Capture the Flag');
    $slug = Database::fetchValue('SELECT slug FROM events WHERE id = ?', [$event]);
    $first = as_role('student@sd.taylors.edu.my');
    $second = as_role('student2@sd.taylors.edu.my');

    $page = $first->get("/events/$slug");
    check(str_contains($page->body, 'seats--last') && str_contains($page->body, 'Book a seat'), 'the page does not offer the last seat');
    $booked = $first->post("/events/$slug/book");
    same(303, $booked->status, 'booking the last seat');
    check(preg_match('#^/my/tickets/(\d+)$#', $booked->location(), $m) === 1, "booking redirected to {$booked->location()}, not a ticket");
    $ticketId = (int) $m[1];
    check_page($first->get($booked->location()), 'the new ticket');

    $page = $second->get("/events/$slug");
    check(str_contains($page->body, 'seats--full') && str_contains($page->body, 'Join the waitlist'), 'the full event does not offer the waitlist');
    same("/events/$slug", $second->post("/events/$slug/book")->location(), 'joining the waitlist');
    check(str_contains($second->get("/events/$slug")->body, 'You are number 1 on the waitlist.'), 'the waitlist position is not shown');
    same(30, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'seats taken while full');

    $first->get('/my/registrations');
    same(303, $first->post("/my/registrations/$ticketId/cancel")->status, 'cancelling the seat');
    same('confirmed', Database::fetchValue('SELECT status FROM registrations WHERE event_id = ? AND user_id = ?', [$event, user_id('student2@sd.taylors.edu.my')]), 'the waiting student after the cancellation');
    same(30, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'seats taken after the hand-over');
    check(str_contains($second->get('/notifications')->body, 'A seat is yours'), 'the promoted student was not told');
    check(str_contains(as_role('admin@taylors.edu.my')->get('/admin/outbox')->body, 'A seat has opened at Capture the Flag'), 'no promotion email in the outbox');
});

test('flow: a clashing draft is refused with the name of the event in the way', function () {
    $organiser = as_role('organizer@taylors.edu.my');
    $draft = event_id('Git and GitHub');
    $organiser->get('/organise/events');
    $response = $organiser->post("/organise/events/$draft/submit");
    same("/organise/events/$draft/edit", $response->location(), 'where a refused submission lands');
    $page = $organiser->get($response->location());
    check(str_contains($page->body, 'already held by') && str_contains($page->body, 'Capture the Flag'), 'the refusal does not name the clashing event');
    same('draft', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$draft]), 'the draft afterwards');
});

test('flow: create, submit, reject with a reason, fix, approve, publish', function () {
    $organiser = as_role('organizer@taylors.edu.my');
    $admin = as_role('admin@taylors.edu.my');
    $start = strtotime('+15 days 10:00');
    $fields = ['title' => 'Linux Command Line Basics', 'summary' => 'Two hours at the terminal for complete beginners.', 'description' => str_repeat('Learn to move around, edit files and pipe commands together. ', 2),
        'club_id' => 3, 'category_id' => 5, 'venue_id' => 8, 'starts_at' => date('Y-m-d\TH:i', $start), 'ends_at' => date('Y-m-d\TH:i', $start + 7200), 'capacity' => 41];

    $organiser->get('/organise/events/new');
    $tooBig = $organiser->post('/organise/events', $fields);
    same(422, $tooBig->status, '41 seats in a lab for 40');
    check(str_contains($tooBig->body, 'more seats than the room holds'), 'the form does not explain the capacity problem');
    $created = $organiser->post('/organise/events', ['capacity' => 30] + $fields);
    same(303, $created->status, 'saving the draft');
    $id = event_id('Linux Command Line');
    same("/organise/events/$id/edit", $created->location(), 'where saving lands');

    $organiser->get("/organise/events/$id/edit");
    same('/organise/events', $organiser->post("/organise/events/$id/submit")->location(), 'submitting for approval');
    same('pending_approval', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$id]), 'status after submitting');
    check(!str_contains((new Browser())->get('/events?q=linux')->body, 'Linux Command Line'), 'a pending event is already public');

    $admin->get('/admin/approvals');
    $admin->post("/admin/approvals/$id/reject", ['reason' => 'too short']);
    same('pending_approval', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$id]), 'status after a rejection with a 9 character reason');
    $admin->get('/admin/approvals');
    $admin->post("/admin/approvals/$id/reject", ['reason' => 'Please say which laptops students should bring.']);
    same('draft', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$id]), 'status after a rejection with a reason');
    check(str_contains($organiser->get("/organise/events/$id/edit")->body, 'Please say which laptops students should bring.'), 'the organiser cannot see the reason');

    $organiser->post("/organise/events/$id", ['capacity' => 32, 'description' => $fields['description'] . ' Bring any laptop.'] + $fields);
    $organiser->get("/organise/events/$id/edit");
    $organiser->post("/organise/events/$id/submit");
    $admin->get('/admin/approvals');
    same(303, $admin->post("/admin/approvals/$id/approve")->status, 'approving');
    $event = Database::fetchOne('SELECT status, approved_by, approved_at FROM events WHERE id = ?', [$id]);
    same(['published', user_id('admin@taylors.edu.my')], [$event['status'], (int) $event['approved_by']], 'status and approver');
    check(str_contains((new Browser())->get('/events?q=linux')->body, 'Linux Command Line Basics'), 'the approved event is not on the browse page');

    $trail = array_column(Database::fetchAll("SELECT action FROM audit_logs WHERE entity_type = 'event' AND entity_id = ? ORDER BY id", [$id]), 'action');
    same(['event.create', 'event.pending_approval', 'event.rejected', 'event.update', 'event.pending_approval', 'event.published'], $trail, 'the audit trail of the event');
    check(str_contains($admin->get('/admin/audit?action=event.published')->body, 'event.published'), 'the audit log page does not show the approval');
});

test('flow: check in by QR code, and a second scan of the same ticket is refused', function () {
    $event = event_id('Build Night');
    $student = as_role('student@sd.taylors.edu.my');
    $organiser = as_role('organizer@taylors.edu.my');
    $registration = Database::fetchOne('SELECT id, ticket_nonce FROM registrations WHERE event_id = ? AND user_id = ?', [$event, user_id('student@sd.taylors.edu.my')]);
    preg_match('/data-qr="([^"]+)"/', $student->get('/my/tickets/' . $registration['id'])->body, $m);
    $payload = $m[1];

    $organiser->get("/organise/events/$event/check-in");
    $before = $organiser->postJson("/organise/events/$event/check-in", ['payload' => 'v1.1.1.' . str_repeat('a', 32) . '.' . str_repeat('A', 43)])->json();
    same('invalid_signature', $before['result'], 'a forged ticket');
    $tampered = $organiser->postJson("/organise/events/$event/check-in", ['payload' => preg_replace('/^v1\.(\d+)\.(\d+)\./', 'v1.$1.' . ($registration['id'] + 1) . '.', $payload)])->json();
    same('invalid_signature', $tampered['result'], 'a real ticket edited to another registration');

    $first = $organiser->postJson("/organise/events/$event/check-in", ['payload' => $payload])->json();
    same([true, 'admitted', 'Aina Sofea Rahman'], [$first['admitted'], $first['result'], $first['name']], 'the first scan');
    same(21, $first['counts']['checked_in'], 'the door count after the scan');
    $second = $organiser->postJson("/organise/events/$event/check-in", ['payload' => $payload])->json();
    same([false, 'duplicate'], [$second['admitted'], $second['result']], 'the same ticket scanned again (a screenshot)');

    $otherEvent = event_id('Capture the Flag');
    $otherTicket = Database::fetchOne("SELECT * FROM registrations WHERE event_id = ? AND status = 'confirmed' LIMIT 1", [$otherEvent]);
    $wrong = $organiser->postJson("/organise/events/$event/check-in", ['payload' => App\Core\App::container()->get(App\Services\CheckInService::class)->payloadFor($otherTicket)])->json();
    same('wrong_event', $wrong['result'], 'a genuine ticket for a different event');
    $early = $organiser->postJson("/organise/events/$otherEvent/check-in", ['payload' => App\Core\App::container()->get(App\Services\CheckInService::class)->payloadFor($otherTicket)])->json();
    same('event_not_live', $early['result'], 'a genuine ticket two days before its event');

    $manual = Database::fetchOne('SELECT r.ticket_nonce, u.full_name FROM registrations r JOIN users u ON u.id = r.user_id WHERE r.event_id = ? AND r.checked_in_at IS NULL AND r.status = \'confirmed\' LIMIT 1', [$event]);
    $typed = $organiser->postJson("/organise/events/$event/check-in", ['code' => App\Services\CheckInService::doorCode((string) $manual['ticket_nonce'])])->json();
    same(['admitted', $manual['full_name']], [$typed['result'], $typed['name']], 'check in with the typed door code');

    same(7, (int) Database::fetchValue('SELECT COUNT(*) FROM ticket_scans WHERE event_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$event]) + (int) Database::fetchValue('SELECT COUNT(*) FROM ticket_scans WHERE event_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$otherEvent]), 'scans written to the door log, refused ones included');
    check(str_contains($student->get('/my/tickets/' . $registration['id'])->body, 'Checked in at'), 'the ticket does not show it was used');
    same(303, $student->post('/my/registrations/' . $registration['id'] . '/cancel')->status, 'trying to cancel after check-in');
    same('confirmed', Database::fetchValue('SELECT status FROM registrations WHERE id = ?', [$registration['id']]), 'a checked-in booking cannot be cancelled');
});

test('flow: the clock moves events on, then certificate and rating', function () {
    $event = event_id('Build Night');
    $student = as_role('student@sd.taylors.edu.my');
    $registration = (int) Database::fetchValue('SELECT id FROM registrations WHERE event_id = ? AND user_id = ?', [$event, user_id('student@sd.taylors.edu.my')]);
    $slug = Database::fetchValue('SELECT slug FROM events WHERE id = ?', [$event]);

    same(303, $student->get("/my/certificates/$registration")->status, 'the certificate before the event has ended');
    // End the event an hour ago and let the application notice on its own.
    as_root(fn () => Database::execute('UPDATE events SET starts_at = NOW() - INTERVAL 4 HOUR, ends_at = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$event]));
    @unlink(BASE_PATH . '/storage/cache/lifecycle.clock');
    $student->get('/events');
    same('completed', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$event]), 'the status after the end time has passed');
    $entry = Database::fetchOne("SELECT actor_id FROM audit_logs WHERE entity_id = ? AND action = 'event.completed' ORDER BY id DESC LIMIT 1", [$event]);
    check($entry !== null && $entry['actor_id'] === null, 'the automatic change is not recorded as made by the system');

    $certificate = $student->get("/my/certificates/$registration");
    check_page($certificate, 'the certificate');
    check(str_contains($certificate->body, 'Aina Sofea Rahman') && str_contains($certificate->body, 'Build Night'), 'the certificate is missing the name or the event');
    preg_match('#/verify/([A-Z0-9]{12})#', $certificate->body, $m);
    check(str_contains((new Browser())->get('/verify/' . $m[1])->body, 'This certificate is genuine'), 'the public verification page does not confirm it');
    same(404, (new Browser())->get('/verify/AAAAAAAAAAAA')->status, 'verifying a made-up code');

    $page = $student->get("/events/$slug");
    check(str_contains($page->body, 'Send rating'), 'an attendee is not offered the rating form');
    same(303, $student->post("/events/$slug/rate", ['rating' => 5, 'comment' => 'Shipped my first app.'])->status, 'rating the event');
    check(str_contains($student->get("/events/$slug")->body, 'Shipped my first app.') && !str_contains($student->body, 'Send rating'), 'the rating is not shown, or can be given twice');

    $outsider = as_role('student2@sd.taylors.edu.my');
    $outsider->get("/events/$slug");
    $outsider->post("/events/$slug/rate", ['rating' => 1, 'comment' => 'I was not there.']);
    same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM event_ratings WHERE event_id = ? AND user_id = ?', [$event, user_id('student2@sd.taylors.edu.my')]), 'ratings from someone who did not attend');
});

test('flow: an administrator manages venues and roles, and both are audited', function () {
    $admin = as_role('admin@taylors.edu.my');
    $admin->get('/admin/venues/new');
    same(422, $admin->post('/admin/venues', ['code' => 'LT-7', 'name' => 'Duplicate theatre', 'building' => 'Block D', 'capacity' => 100, 'venue_type' => 'lecture_theatre'])->status, 'a venue code that already exists');
    same(303, $admin->post('/admin/venues', ['code' => 'lt-9', 'name' => 'Lecture Theatre 9', 'building' => 'Block D', 'capacity' => 220, 'venue_type' => 'lecture_theatre', 'has_projector' => 1])->status, 'adding a venue');
    $venue = (int) Database::fetchValue("SELECT id FROM venues WHERE code = 'LT-9'");
    check($venue > 0, 'the venue was not saved with its code in capitals');
    $cyber = (int) Database::fetchValue("SELECT id FROM venues WHERE code = 'CYBER-LAB'");
    $admin->get("/admin/venues/$cyber/edit");
    same(422, $admin->post("/admin/venues/$cyber", ['code' => 'CYBER-LAB', 'name' => 'Cybersecurity Lab', 'building' => 'Block C', 'floor_label' => '7', 'capacity' => 20, 'venue_type' => 'lab'])->status, 'shrinking a lab below an event planned in it');
    $admin->get('/admin/venues');
    $admin->post("/admin/venues/$venue/toggle");
    check(!str_contains(as_role('organizer@taylors.edu.my')->get('/organise/events/new')->body, 'Lecture Theatre 9'), 'a closed venue can still be chosen');

    $target = user_id('new.student@sd.taylors.edu.my');
    $admin->get('/admin/users');
    $admin->post("/admin/users/$target/role", ['role' => 'organizer']);
    same('student', Database::fetchValue('SELECT role FROM users WHERE id = ?', [$target]), 'making an organiser without choosing a club');
    $admin->get('/admin/users');
    $admin->post("/admin/users/$target/role", ['role' => 'organizer', 'club_id' => 13]);
    same('organizer', Database::fetchValue('SELECT role FROM users WHERE id = ?', [$target]), 'the new role');
    same('committee', Database::fetchValue('SELECT role FROM club_members WHERE user_id = ? AND club_id = 13', [$target]), 'the club membership');
    $entry = Database::fetchOne("SELECT actor_id, before_data, after_data FROM audit_logs WHERE entity_type = 'user' AND entity_id = ? AND action = 'user.update' ORDER BY id DESC LIMIT 1", [$target]);
    same([user_id('admin@taylors.edu.my'), 'student', 'organizer'], [(int) $entry['actor_id'], json_decode((string) $entry['before_data'], true)['role'], json_decode((string) $entry['after_data'], true)['role']], 'the audit entry for the role change');

    $self = user_id('admin@taylors.edu.my');
    $admin->get('/admin/users');
    $admin->post("/admin/users/$self/role", ['role' => 'student']);
    $admin->get('/admin/users');
    $admin->post("/admin/users/$self/toggle");
    same(['admin', 1], array_values(Database::fetchOne('SELECT role, is_active FROM users WHERE id = ?', [$self])), 'an administrator changing their own role or access');

    $admin->get('/admin/users');
    $admin->post("/admin/users/$target/toggle");
    same(422, (new Browser())->signIn('new.student@sd.taylors.edu.my', 'brand-new-passphrase')->status, 'a deactivated account signing in');
});

test('sessions live in the database and end at sign out', function () {
    $browser = new Browser();
    $browser->signIn('student2@sd.taylors.edu.my');
    $me = user_id('student2@sd.taylors.edu.my');
    check((int) Database::fetchValue('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$me]) >= 1, 'no session row for the signed-in user');
    same(200, $browser->get('/my/registrations')->status, 'a private page while signed in');
    same(303, $browser->post('/logout')->status, 'signing out');
    same(303, $browser->get('/my/registrations')->status, 'the same page after signing out');
});

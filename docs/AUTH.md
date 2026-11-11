# Auth and security (Member 2)

Unzip over the repo root. This adds `app/Auth`, `app/Security`, `app/Middleware`, `app/Controllers/Web/AuthController.php`,
`app/Views/pages/auth`, `app/Support/auth_helpers.php`, `scripts/test_auth.php`, and REPLACES two files with edited copies:

| File | What changed |
|---|---|
| `config/routes.php` | aliases `auth`, `role`, `throttle`; global middleware; login, register, logout routes |
| `app/bootstrap.php` | one added line: `require BASE_PATH . '/app/Support/auth_helpers.php';` |

If either file changed since you sent it to me, copy those lines in by hand instead of overwriting.

Also merge `docs/lang_auth_en.php` into `app/Views/lang/en.php` (flat dotted keys; convert if the file is nested).

Nothing has been run (no PHP in my sandbox). First: `php -l` on every file, then `php scripts/test_auth.php`.

## Protecting routes

```php
$router->get('/my/tickets', [TicketController::class, 'index'], ['auth']);
$router->post('/events',    [EventController::class, 'store'],  ['role:organizer,admin']);
$router->get('/admin/users',[AdminController::class, 'users'],  ['role:admin']);
$router->get('/api/search', [SearchController::class, 'q'],     ['throttle:search,60,60']);
```

Roles are explicit: `role:admin` does not let organizers in, and `role:organizer` does not let admins in unless listed.

## For Member 3 (templates and JavaScript)

- Every `<form method="post">` needs `<?= csrf_field() ?>`. Logout is a POST form, not a link.
- `fetch()` calls that change data: add `<meta name="csrf-token" content="<?= e(csrf_token()) ?>">` and send its value as the `X-CSRF-Token` header. The camera scanner needs this.
- `auth_user()` gives `['id','email','full_name','role','faculty_id']` or `null`. `auth_role('admin')` tests the role.
- In controllers: `Auth::user()`, `Auth::id()`, `Auth::check()`, `Auth::hasRole('admin')`, or `$request->attribute('user')`.

## How it behaves

- Sessions live in `user_sessions` (30 min idle, 8 h absolute, bound to a User-Agent hash). Optional `config('session.idle')` and `config('session.absolute')` in `config/app.php`.
- `SessionMiddleware` calls `Database::setAuditContext()` with the real user id on every request.
- Public sign-up always makes a `student`. Organizer and admin are granted by an admin only.
- 5 wrong passwords lock the account for 15 minutes. Unknown email, wrong password and locked account show the same message.
- Role is re-read every request, so a demoted or deleted user is affected immediately.
- Guests on a page go to `/login?next=...`; API routes get 401. Wrong role is 403, bad CSRF is 419, rate limited is 429 with `Retry-After`. All use your `ErrorRenderer`.
- The per-account limiter (8 tries per 15 min) lets someone deliberately slow a victim's sign-ins. Accepted for this scope.

## Schema (checked against database/schema.sql)

All column names now match the real tables. Two things the schema forced:
- `user_sessions.ip_address` is NOT NULL, so the session handler writes the client IP.
- `users.is_active` exists, so deactivated accounts cannot sign in and are signed out on their next request. Sign-in also sets `last_login_at`.

Seeded accounts all use the password `Demo@12345` (admin@taylors.edu.my, organizer@taylors.edu.my, student@sd.taylors.edu.my).

## Sessions in detail

- A guest session that holds nothing (no CSRF token, nobody signed in) is not written to `user_sessions`, so crawlers and health checks do not fill the table.
- If the database is down, the session store is skipped and the request carries on as a guest. `/api/health` can then still answer 503. Nobody can sign in or submit forms until the database is back (CSRF fails closed).

## Known side effect of global CSRF checking

The CSRF check runs before routing, so a POST without a token to ANY URL returns 419, even when the URL would have been a 405 or 404.
`scripts/smoke_test.sh` already accounts for this: it checks that an unsigned POST gets 419, then signs one with a token from `/login` and expects the 405.

## Still to write

Password reset (`password_resets` + `mail_outbox`), QR ticket HMAC signing, upload validation, dev bar (`Database::queryCount()` already exists).

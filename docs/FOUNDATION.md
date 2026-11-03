# PHP foundation: how to build on it

Plain PHP 8.2+, no framework. Everything below is already working and tested
(`php scripts/test_core.php`, `bash scripts/smoke_test.sh`).

## How a request flows

```
browser
  -> public/index.php            single entry point
  -> app/bootstrap.php           autoloader, .env, config, error handling
  -> config/routes.php           which controller handles this URL
  -> middleware                  auth, role, CSRF ... (Phase 2)
  -> controller                  app/Controllers/Web or Api
  -> model                       app/Models (SQL with placeholders)
  -> view                        app/Views/pages, inside layouts/main.php
  -> response sent
```

Any exception becomes a proper error page (or JSON for `/api/...`).
Details are shown only when `APP_DEBUG=true`, and every error is logged to
`storage/logs/`.

## Add a page in four steps

**1. Route** in `config/routes.php`:

```php
$router->get('/events/{slug}', [EventController::class, 'show']);
```

`{slug}` matches one URL segment. `{id:\d+}` adds a pattern.

**2. Controller** in `app/Controllers/Web/EventController.php`:

```php
namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Models\Event;

final class EventController
{
    public function show(Request $request, string $slug): Response
    {
        $event = Event::findBySlug($slug) ?? abort(404);
        return view('pages/event', ['title' => $event['title'], 'event' => $event]);
    }
}
```

The parameter name (`$slug`) must match the name in the route.
URL values arrive as strings: cast with `(int)` when you need a number.

**3. Model** method in `app/Models/Event.php`:

```php
public static function findBySlug(string $slug): ?array
{
    return Database::fetchOne(
        'SELECT * FROM events WHERE slug = ? AND deleted_at IS NULL',
        [$slug],
    );
}
```

**4. View** in `app/Views/pages/event.php`:

```php
<h1><?= e($event['title']) ?></h1>
<a class="btn btn--primary" href="<?= e(url('/events/' . $event['slug'] . '/register')) ?>">
    <?= e(t('action.register')) ?>
</a>
```

## Three rules

| Rule | Why |
|---|---|
| Print every value with `e()` | Stops XSS. `<?= $x ?>` without `e()` is a bug. |
| Never put user input inside SQL text, use `?` or `:name` | Stops SQL injection. `Database` binds values for you. |
| Never type interface text in a template, use `t('key')` | All text lives in `app/Views/lang/en.php`. Renaming "Submit" to "Save" everywhere is one line. |

## Database helpers

```php
Database::fetchAll($sql, $params);   // list of rows
Database::fetchOne($sql, $params);   // one row or null
Database::fetchValue($sql, $params); // one value, e.g. COUNT(*)
Database::execute($sql, $params);    // UPDATE/DELETE, returns affected rows
Database::insert($sql, $params);     // INSERT, returns the new id
Database::transaction(fn () => ...); // commit, or roll back on any exception
```

**Booking a seat** must lock the event row first so two people cannot take
the last seat:

```php
Database::transaction(function () use ($eventId, $userId) {
    $event = Database::fetchOne('SELECT capacity, confirmed_count FROM events WHERE id = ? FOR UPDATE', [$eventId]);
    // decide confirmed or waitlisted, insert the registration
});
```

**When the database refuses a change**, show the reason instead of a crash:

```php
try {
    Database::execute("UPDATE events SET status = 'pending_approval' WHERE id = ?", [$id]);
} catch (PDOException $e) {
    $rule = Database::violatedRule($e);   // "VENUE_CLASH", "LIFECYCLE", "CAPACITY", ...
    if ($rule === null) {
        throw $e;                         // a real error: let it become a 500 page
    }
    $error = t('db.' . $rule);            // friendly message from lang/en.php
}
```

**Audit trail:** after sign-in is resolved, tell the triggers who is acting:

```php
Database::setAuditContext($user['id'], $request->ip());
```

## Middleware (Phase 2)

A class in `app/Middleware/` that implements `App\Core\MiddlewareInterface`:

```php
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (/* not signed in */) {
            return redirect('/login');
        }
        return $next($request);
    }
}
```

Register it in `config/routes.php`, then use it on routes:

```php
$router->alias('auth', AuthMiddleware::class);
$router->alias('role', RoleMiddleware::class);       // new RoleMiddleware('admin', 'organizer')
$router->get('/admin', [AdminController::class, 'index'], ['auth', 'role:admin,organizer']);
$router->middleware([SecurityHeadersMiddleware::class]); // every request
```

Middleware can attach data for controllers:
`$request->setAttribute('user', $user)` then `$request->attribute('user')`.

## API routes

Routes under `/api/` return the same envelope:

```php
return Response::apiSuccess($data);                         // {"success":true,"data":...}
return Response::apiError('Event is full', 409, 'EVENT_FULL'); // {"success":false,"error":{...}}
```

## Other helpers

| Helper | Use |
|---|---|
| `url('/events')` | Link inside the app (works in a sub-folder too) |
| `asset('css/app.css')` | File in `public/assets`, auto-refreshed after changes |
| `redirect('/login')` | Redirect response |
| `abort(403)` | Stop with an error page |
| `format_datetime($row['starts_at'])` | "Fri 6 Nov 2026, 2:00 pm" |
| `config('db.name')` | Setting from `config/app.php` |
| `current_path()` | Highlight the active menu item |
| `$request->input('email')` | Form or JSON field |

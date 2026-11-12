# Design decisions

What was decided, what the alternative was, and why. Each section ends with
where to see it in the code and which test proves it.

## 1. Every guarantee is enforced twice

A rule that exists only in PHP holds until someone writes a controller that
forgets to call it, or opens a SQL console. A rule that exists only in the
database gives users a raw SQL error. So each rule lives in both places:
the PHP service checks first and explains the problem in plain language, and
the database refuses anyway if the PHP is wrong.

| Guarantee | In PHP | In MySQL |
|---|---|---|
| A seat cannot be sold twice | `BookingService::book` locks the event row with `SELECT ... FOR UPDATE` before reading the seat count | `CHECK (confirmed_count <= capacity)`, with the count maintained by triggers on `registrations` |
| A venue cannot be double booked | `EventService::submit` and `ApprovalService::approve` lock the venue row, then look for an overlap | `trg_events_bi` and `trg_events_bu` run the overlap test `s1 < e2 AND e1 > s2` as a locking read and raise `VENUE_CLASH` |
| The audit log cannot be altered | No class has a method that updates or deletes it | `trg_audit_no_update`, `trg_audit_no_delete`; the application account has no privilege to drop them |

Both layers raise the same rule names (`VENUE_CLASH`, `SELF_APPROVAL`, ...).
`App\Domain\RuleViolation` turns either into the sentence stored under
`rule.<NAME>` in the language file, so the user sees the same message whichever
layer caught the problem.

Proof: `tests/DatabaseTest.php` bypasses PHP completely and attempts each
forbidden operation as the application account. `tests/ConcurrencyTest.php`
runs each race twice, once through the services and once as raw SQL with no
PHP lock at all.

## 2. Why the lock and the constraint are both needed

They do different jobs. During development the row lock was removed on
purpose and the concurrency test run again: the constraint still allowed only
one booking, but the other 19 requests failed with deadlock errors instead of
being placed on the waitlist. The constraint guarantees the data is right; the
lock guarantees the experience is right, because each request waits its turn
and then sees the true seat count.

The venue check has a subtlety. InnoDB's default isolation level gives a
transaction a snapshot of the data as it was when it first read. A transaction
that waited for the venue lock could then check for clashes against a stale
snapshot and miss the booking that had just been committed. The overlap query
is therefore a locking read (`FOR SHARE`) in both the trigger and
`VenueRepository::clash`, which always sees the latest committed rows.

## 3. The event lifecycle is a state machine

```
draft -> pending_approval -> published -> ongoing -> completed -> archived
            |                    |
            v                    v
          draft                draft
   (rejected, with a reason)   (withdrawn, only with no bookings)
```

The allowed moves are written once in PHP (`App\Domain\EventStatus`) and once
in the trigger `trg_events_bu`. A test walks all 30 pairs of different
statuses and fails if the two ever disagree.

`published -> ongoing` and `ongoing -> completed` are caused by time. Instead
of a cron job, which a marker's laptop will not have, a middleware runs two
indexed UPDATE statements at most every 20 seconds. It runs before the session
is read, so the audit log records these changes as made by the system.

## 4. An organiser cannot approve their own event

Three layers. The route `/admin/approvals/...` requires the admin role.
`ApprovalService` refuses an approver who created the event or sits on the
hosting club's committee. The trigger refuses the UPDATE unless `approved_by`
is an administrator who is neither the organiser nor on that committee.

## 5. Tickets

A ticket is the text `v1.<event>.<registration>.<nonce>.<signature>`, shown as
a QR code.

* The signature is HMAC-SHA256 over the first four parts. The key is derived
  from `TICKET_SECRET` in `.env` and is in no table. The signature is
  recomputed on every scan and never stored, so a full copy of the database is
  not enough to make a ticket.
* The nonce is 128 random bits stored on the registration. A scan is accepted
  only if the nonce matches and `checked_in_at` is empty, and it sets
  `checked_in_at` under a row lock. A screenshot of a used ticket is answered
  "already used". Cancelling a booking replaces the nonce, so a screenshot of a
  cancelled ticket stops verifying.
* Every scan, admitted or refused, goes into `ticket_scans`, which is append
  only like the audit log.
* When a phone camera cannot read the code, the first eight characters of the
  nonce are printed on the ticket as a door code. Typed codes are rate limited.

## 6. Security choices

| Risk | What the application does |
|---|---|
| Stolen passwords | Argon2id via `password_hash`. Hashes are upgraded on sign-in if parameters change. |
| Guessing passwords | Counters per address and per account in `rate_limits`; the account locks after five wrong passwords. |
| Finding out who has an account | Sign-in and forgot-password answer identically for known and unknown addresses, and sign-in spends the same time on both. |
| Session theft and fixation | Sessions are rows in `user_sessions`, tied to a hash of the browser's user agent. The id is regenerated at sign-in. Cookies are HttpOnly and SameSite=Lax. Idle and absolute timeouts. |
| Cross-site request forgery | A token in every state-changing form, checked by a global middleware, plus an Origin header check. |
| SQL injection | PDO prepared statements only, with emulation off. No query is built from input. |
| Cross-site scripting | Every value is printed through `e()`. A Content-Security-Policy allows only this site's own files: no inline scripts or styles. |
| Reaching files | The web root is `public/`. `.env`, `app/`, `config/` and `database/` are outside it. |
| Privilege escalation | Role checked in middleware on every route, then ownership checked in the service. The wrong role gets 403, not a redirect. |
| Reset link abuse | 256-bit token, stored as SHA-256, single use, 30 minutes. Using it signs the account out everywhere. |
| Database compromise through the app | The application account has SELECT, INSERT, UPDATE and DELETE only. |
| Spreadsheet formula injection | The CSV export prefixes cells that start with `=`, `+`, `-` or `@`. |

## 7. Structure

```
Request -> public/index.php -> middleware -> Router -> Controller -> Service -> Repository -> MySQL
                                                           |
                                                           v
                                                         View (template + lang file)
```

* **Controllers** read the request, call one service, choose a page. No SQL, no rules.
* **Services** hold the business rules and the transactions.
* **Repositories** hold every SQL statement.
* **Views** are HTML with `e()` and `t()`. No queries, no wording.
* **The container** (`App\Core\Container`) builds each class by reading its
  constructor, so dependencies are declared, not fetched. Only classes that
  need a configuration value are listed in `config/services.php`.

No framework and no Composer packages are used. The router, middleware
pipeline, container, validator, view renderer and test runner are in this
repository and are short enough to read in one sitting.

## 8. Everything works with no network

Fonts (Bricolage Grotesque, Atkinson Hyperlegible), the QR generator and the
QR scanner are files under `public/assets`. Emails are written to
`mail_outbox` and read on the administrator's Outbox page. The
Content-Security-Policy would block any outside request, and a test fails if a
template or the stylesheet refers to another host.

Third party files bundled, with their licences:

| File | Project | Licence |
|---|---|---|
| `js/vendor/qrcode.min.js` | node-qrcode 1.5.4 | MIT |
| `js/vendor/jsqr.min.js` | jsQR 1.4.0 | Apache 2.0 |
| `fonts/bricolage-grotesque.woff2` | Bricolage Grotesque | SIL Open Font Licence 1.1 |
| `fonts/atkinson-*.woff2` | Atkinson Hyperlegible | SIL Open Font Licence 1.1 |

## 9. Visual identity

The product is about seats running out, so the seat meter is the component
everything else is arranged around. It appears, identical, on the browse list,
the event page, the organiser's list and the administrator's dashboard: twelve
cells that fill as seats are taken, and a count that changes from ink to amber
to red. Red means "nearly gone" or "gone" wherever it appears.

Everything else is deliberately quiet: one neutral background, one surface
colour, thin borders, no shadows, no gradients. The tokens (colour, a type
scale, a 4px spacing scale, two radii) are custom properties at the top of the
single stylesheet, and every page is built from the same dozen components.
The dark theme changes only those tokens.

Typefaces: Bricolage Grotesque for headings and numbers, because it has
character at large sizes; Atkinson Hyperlegible for text, because it was drawn
for legibility and its letterforms are hard to confuse on a phone at arm's
length, which is how a ticket is read at a door.

Accessibility: text contrast of at least 4.5:1 in both themes, a visible focus
ring on every control, a skip link, labels on every field, errors tied to
their fields, status never shown by colour alone, reduced motion respected,
layouts that hold at 390px with no horizontal scrolling.

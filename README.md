# Taylor's Events

[![CI](https://github.com/rianmu70/taylors-events/actions/workflows/ci.yml/badge.svg)](https://github.com/rianmu70/taylors-events/actions/workflows/ci.yml)

One place for every event on Taylor's Lakeside Campus: students find events
and book seats, clubs run them, and Student Development approves them and
sees what happened.

ITS64504 Web Application Programming, Group 4. PHP 8.3 with no framework,
MySQL 8, HTML, CSS and vanilla JavaScript. No internet connection is needed at
run time.

## The problem it solves

| Today | With Taylor's Events |
|---|---|
| Events are announced on scattered club accounts and mailing lists, so students who do not follow a club never hear of its event. | Every approved event from every club is in one searchable list, filtered by category and date. |
| Seats are counted by hand. Two students who claim the last seat at the same moment are both told they have it. | A seat is taken inside a transaction that locks the event, and the database itself refuses a booking beyond capacity. A full event has a waitlist that promotes automatically. |
| Nobody holds a single calendar of rooms, so two clubs book the same lab for the same afternoon. | A database trigger refuses any event that overlaps another in the same room, and names the event already there. |
| Attendance is on paper: slow, easy to fake, useless as proof. | A signed, single-use QR ticket is scanned with a phone. Attendees get a certificate anyone can verify. |

The full proposal, with scope, out-of-scope list, stack justification and
timeline, is in [docs/PROPOSAL.md](docs/PROPOSAL.md).

## Run it in under five minutes

### Requirements

* PHP 8.3 or newer with `pdo_mysql`
* MySQL 8.0.16 or newer (MariaDB is not supported: it handles CHECK constraints and triggers differently)

### Steps

```bash
git clone https://github.com/rianmu70/taylors-events.git
cd taylors-events
bash scripts/setup.sh      # asks for the MySQL root password once
bash scripts/serve.sh
```

Open http://localhost:8000

`setup.sh` creates `.env` with a fresh ticket secret, loads the schema and the
demo data, and creates the database account the application runs as. That
account can read and write rows and nothing else.

### With Docker instead

```bash
docker compose up --build
```

Open http://localhost:8000. This needs no PHP or MySQL on the machine.

### Before a presentation

```bash
bash scripts/reset_demo.sh
```

Reloads the demo data in a few seconds, with every date recalculated from the
current time, and prints the four demonstration scenarios so you can see they
are in place.

## Demo accounts

Every account uses the password **`Demo@12345`**.

| Email | Role | Set up for the demo |
|---|---|---|
| `student@sd.taylors.edu.my` | Student | Holds a seat at the event happening now, first on the Resume Clinic waitlist, has two certificates |
| `student2@sd.taylors.edu.my` | Student | Holds a seat at the Resume Clinic |
| `organizer@taylors.edu.my` | Organiser, Taylor's Agents of Tech | Runs the live event, the last-seat event, and the clashing draft |
| `music@taylors.edu.my` | Organiser, Taylor's Music Club | A second club, to show organisers are limited to their own |
| `admin@taylors.edu.my` | Administrator, Student Development | Three events waiting for approval |

The demo data holds 300 students across 15 schools, 14 venues, 14 clubs and
24 events.

## Demo scenarios

Each one takes under a minute. Use two browser windows (one private) when two
people are involved.

### 1. A seat cannot be sold twice

*Capture the Flag* has one seat left.

1. As `student@`, open the event. The meter is red: **1 seat left**. Click **Book a seat**. The ticket appears.
2. As `student2@`, open the same event. It now says **Full**. Click **Join the waitlist**: **You are number 1 on the waitlist**.
3. As `student@`, go to **My registrations** and click **Cancel my seat**.
4. As `student2@`, open **Notifications**: **A seat is yours**. The seat went to the waitlist inside the same transaction, so it was never on open sale.

Then show that the database refuses on its own, even without the PHP:

```bash
mysql -u taylors_app -p"$(grep ^DB_PASS= .env | cut -d= -f2)" taylors_events \
  -e "INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (3, 17, 'confirmed', MD5(RAND()))"
```

```
ERROR 3819 (HY000): Check constraint 'chk_events_no_overbook' is violated.
```

And the real race, 20 processes fighting for one seat:

```bash
bash scripts/test.sh concurrency
```

### 2. A venue cannot be double booked

1. As `organizer@`, open **Hosted events**. *Git and GitHub Workshop* is a draft in the Cybersecurity Lab, the same afternoon as *Capture the Flag*.
2. Click **Submit for approval**. It is refused: *That room is already held by "Capture the Flag: Beginner Cybersecurity Challenge" (Taylor's Agents of Tech), Fri ... Pick another room or time.*

The same rule, straight to the database:

```bash
mysql -u taylors_app -p"$(grep ^DB_PASS= .env | cut -d= -f2)" taylors_events \
  -e "UPDATE events SET status = 'pending_approval' WHERE slug = 'git-and-github-workshop'"
```

```
ERROR 1644 (45000): VENUE_CLASH: the venue is already booked for an overlapping time
```

### 3. The audit log cannot be altered

1. As `admin@`, open **Audit log**. Every change is there, with who, when, from which address, and the values before and after.
2. Try to change it, as the application's own database account:

```bash
mysql -u taylors_app -p"$(grep ^DB_PASS= .env | cut -d= -f2)" taylors_events \
  -e "DELETE FROM audit_logs WHERE id = 1"
mysql -u taylors_app -p"$(grep ^DB_PASS= .env | cut -d= -f2)" taylors_events \
  -e "DROP TRIGGER trg_audit_no_delete"
```

```
ERROR 1644 (45000): AUDIT_IMMUTABLE: the audit log is append only
ERROR 1142 (42000): TRIGGER command denied to user 'taylors_app'@'localhost' for table 'audit_logs'
```

### 4. Check-in that cannot be faked

1. As `student@`, open **My registrations**, then **Show my ticket** for *Build Night*, which is happening now. Note the door code under the QR code.
2. As `organizer@`, open **Hosted events**, then **Check in guests** for *Build Night*. Click **Start camera** and hold the ticket up, or type the door code. **Welcome, Aina Sofea Rahman. Checked in.** The count goes up by one.
3. Scan or type the same code again: **Already used.** This is what happens to a screenshot passed to a friend.

The camera works on `localhost`. On a phone over the network a browser needs
HTTPS for the camera, so use the door code there.

### 5. Approval, and nobody approves their own event

1. As `admin@`, open **Approvals**. Send *Five-a-side Futsal Mixer* back with a reason, and approve *Machine Learning Study Jam*. It appears on **Browse events** straight away.
2. As `organizer@`, type http://localhost:8000/admin/approvals into the address bar: **403**. The database would refuse it too (rule `SELF_APPROVAL`).

### 6. Every page checks the role on the server

As `student@`, type any of these into the address bar. Each answers **403**,
not a redirect and not a working page:

```
http://localhost:8000/admin
http://localhost:8000/admin/users
http://localhost:8000/organise/events/new
```

Another student's ticket, http://localhost:8000/my/tickets/2, is also 403.

### 7. A change during the presentation

| Asked for | Edit, then reload the page |
|---|---|
| Rename the "Book a seat" button | `app/Views/lang/en.php`, the line `'booking.book' => 'Book a seat',` |
| List 12 events per page instead of 8 | `config/app.php`, the line `'per_page' => 8,` |
| Change the brand colour | `public/assets/css/app.css`, the line `--brand: #c1121f;` |

Every other likely change is listed in [docs/CHANGE_GUIDE.md](docs/CHANGE_GUIDE.md).

## Test results

```bash
bash scripts/test.sh
```

78 tests in four suites, run on every push by
[GitHub Actions](.github/workflows/ci.yml) against a fresh MySQL 8:

| Suite | Tests | What it proves |
|---|---:|---|
| Unit | 16 | Ticket signatures, the state machine, the language file has every key and no template has literal text, nothing loads from another host |
| Database | 30 | Each forbidden operation is refused by MySQL itself, as the application account, with no PHP involved: overbooking, double-booked rooms, illegal status changes, self approval, editing or deleting the audit log, dropping its triggers; plus the database session store |
| Concurrency | 7 | 20 simultaneous bookings for one seat give exactly one seat; the same race with no PHP lock at all still gives one; 8 organisers racing for one room; 10 doors scanning one ticket admit it once |
| HTTP | 25 | Every page renders for every role; guests, students and organisers get 403 where they should; CSRF, escaping, rate limiting, password reset; each demo scenario end to end |

The latest full output is in [docs/test-results.txt](docs/test-results.txt).

Index performance on 200,000 events and 1,000,000 registrations, measured with
`EXPLAIN ANALYZE`:

| Query | With index | Without |
|---|---:|---:|
| Venue clash check | 0.04 ms | 4.1 ms |
| Next person on a waitlist | 0.01 ms | 303 ms |
| My registrations | 0.01 ms | 243 ms |

Method, all results, and one index that was measured and removed:
[docs/PERFORMANCE.md](docs/PERFORMANCE.md).

## How it is built

```
public/            the only folder the web server can reach: index.php, css, js, fonts
app/
  Core/            front controller, router, middleware pipeline, container, request, response, views
  Middleware/      sessions, CSRF, authentication, roles, rate limiting, security headers, lifecycle clock
  Auth/ Security/  Argon2id, database sessions, CSRF tokens, rate limiter, ticket signing, audit
  Controllers/     read the request, call one service, choose a page
  Services/        the business rules and transactions
  Repositories/    every SQL statement
  Domain/          the event state machine and rule violations
  Views/           templates, and lang/en.php with every word the user sees
config/            app.php (settings), routes.php (every URL and who may use it), services.php
database/          schema.sql, seed.sql, app_user.sql
scripts/           setup, serve, reset_demo, test, seed generator, benchmark
tests/             the four suites and a small runner
docs/              proposal, ERD, design decisions, performance, change guide
```

| Document | Contents |
|---|---|
| [docs/PROPOSAL.md](docs/PROPOSAL.md) | Problem, scope and out of scope, stack, timeline, risks |
| [docs/ERD.md](docs/ERD.md) | Entity relationship diagram, schema decisions, every trigger |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Why each guarantee is enforced twice, tickets, security, structure, visual identity |
| [docs/PERFORMANCE.md](docs/PERFORMANCE.md) | Index measurements |
| [docs/CHANGE_GUIDE.md](docs/CHANGE_GUIDE.md) | Which line to edit for each likely change |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Branches, commits, pull requests |

## Real campus data and sources

Venue names, locations, clubs and school names come from Taylor's University's
public website (campus facilities and clubs pages, checked 15 Oct 2026):

https://university.taylors.edu.my/en/student-life/campus-facilities.html
https://university.taylors.edu.my/en/student-life/your-taylors-journey/clubs-societies-and-sports.html

The capacities of The Grand Hall (1,000) and the lecture theatres (300) are
published by the university. All other capacities are reasonable estimates for
the demo. People, student numbers and events are fictional.

## Group 4 module ownership

| Member | Student ID | Ownership |
|---|---|---|
| Mubarak Rian Abdulmuhsen Ahmed | 374215 | Database, infrastructure, PHP foundation (router, database layer, views, language file, error handling, tests) |
| Danah Abdul Rahim Kollochi | 388012 | Sessions, sign-in and registration, roles, security middleware (CSRF, rate limiting, headers) |
| Banda Shalom Z | 386232 | Booking, waitlist, venue clash, event lifecycle, tickets and check-in, front-end pages |

Each member commits through their own branch and pull requests so the Git
history reflects individual contribution. See [CONTRIBUTING.md](CONTRIBUTING.md).

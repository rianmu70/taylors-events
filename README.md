# Taylor's Events: Campus Event Management Platform

ITS64504 Web Application Programming. Group Assignment, Group 4.
Pure PHP 8.2+ (custom OOP micro-framework, no back-end framework), MySQL 8, HTML/CSS/JavaScript.

## Folder structure

```
taylors-events/
├── public/                  # Web root: the ONLY folder exposed by the web server
│   ├── index.php            # Front controller (single entry point)       [done]
│   └── assets/{css,js,img}  # Static files; css/app.css holds the tokens  [Phase 4]
├── app/
│   ├── bootstrap.php        # Autoloader, .env, config, error handling    [done]
│   ├── helpers.php          # e(), t(), url(), asset(), view(), abort()   [done]
│   ├── Core/                # Router, Request, Response, Database, View,
│   │                        # Lang, Env, Config, Logger, error pages      [done]
│   ├── Middleware/          # Auth, Role (RBAC), CSRF, RateLimit, Headers [Phase 2]
│   ├── Controllers/
│   │   ├── Web/             # Return HTML pages                           [Phase 3]
│   │   └── Api/             # Return JSON {success, data, error, meta}    [Phase 3]
│   ├── Models/              # Data access (prepared statements only)      [Phase 3]
│   ├── Services/            # Business logic: Booking, VenueClash,
│   │                        # EventStateMachine, TicketSigner, Audit      [Phase 3]
│   ├── Views/{layouts,partials,pages,errors}  # Templates, escaped output [Phase 4]
│   │   └── lang/en.php      # Every label and message in one file         [done]
│   └── Tests/               # In-app automated test lab                   [Phase 5]
├── config/                  # app.php (settings), routes.php (all URLs)   [done]
├── database/
│   ├── schema.sql           # Tables, constraints, indexes, triggers, views
│   ├── seed.sql             # Realistic demo data (relative dates)
│   └── create_app_user.sql  # Least-privilege DB account
├── storage/                 # NOT web-accessible
│   ├── uploads/             # Event banners (validated, re-encoded)
│   ├── logs/  cache/
├── scripts/
│   ├── verify_database.sh   # 19 automated database checks
│   ├── test_core.php        # 55 tests for the PHP foundation
│   ├── smoke_test.sh        # 16 end-to-end HTTP checks
│   ├── reset_demo.sh        # Fresh demo data in one command
│   └── perf/                # Index performance test (separate database)
├── docs/                    # ERD, performance, foundation guide, proposal notes
├── docker/ + docker-compose.yml  # One-command environment
├── .github/                 # CI workflow and pull request template
├── .htaccess                # Keeps everything outside public/ private on Apache
├── .env.example             # Copy to .env (git-ignored)
└── README.md
```

## Requirements

- PHP 8.2+ with `pdo_mysql`, `gd`, `fileinfo`, `mbstring` (Argon2id support)
- **MySQL 8.0.16+**, tested on 8.0.46. XAMPP ships MariaDB, which is untested
  here; use Laragon (Windows), Homebrew (macOS) or the official MySQL installer.

## Quick start with Docker (recommended)

```bash
docker compose up --build
```

The database is created and seeded automatically. App on
http://localhost:8000, MySQL on port 3307.

## Setup without Docker

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
mysql -u root -p < database/create_app_user.sql
cp .env.example .env        # then fill DB_PASS, APP_KEY, TICKET_SIGNING_KEY
php -S localhost:8000 -t public public/index.php
```

Open http://localhost:8000. To check the setup, open
http://localhost:8000/api/health: `"database":"ok"` means PHP, the
connection and the schema all work.

**XAMPP / WAMP:** the project folder can sit directly in `htdocs`, e.g.
http://localhost/taylors-events/. The root `.htaccess` sends every request
into `public/`, so `.env`, logs and source code cannot be downloaded.

## Tests

```bash
bash scripts/verify_database.sh -u root -p   # 19 database rule checks
php scripts/test_core.php                    # 55 PHP foundation tests
php scripts/test_tickets.php                 # signed ticket format and tamper checks
php scripts/test_event_workflows.php         # rollback-only MySQL booking/lifecycle/check-in tests
bash scripts/smoke_test.sh                   # 16 HTTP checks on the running app
```

The event workflow test requires the configured MySQL schema and demo seed data.
It runs all fixtures in one transaction and always rolls them back; it does not
reset or retain changes in the configured database. The other test scripts are
safe to run without the workflow database test. The foundation suite runs
automatically on GitHub for every push.

## Verify the database

```bash
bash scripts/verify_database.sh -u root -p
```

Runs 19 checks (lifecycle rules, venue clashes, capacity, audit and door-scan
immutability, rating eligibility, reset-token expiry and a 20-way concurrent
booking race).
Some checks change rows, so reload the seed afterwards.

**Demo reset** (before every rehearsal and on presentation day):

```bash
bash scripts/reset_demo.sh -uroot -p     # or: bash scripts/reset_demo.sh --docker
```

Dates are relative to load time, so "happening now" and "upcoming" stay correct.

## Performance

`scripts/perf/run_performance_test.sh` loads about 120,000 registrations into a
separate database and compares each key query with and without its index.
Results are in `docs/PERFORMANCE.md` (up to about 1,900x faster).

## Demo accounts (password: `Demo@12345`)

| Role | Email |
|---|---|
| Admin | admin@taylors.edu.my |
| Organizer | organizer@taylors.edu.my |
| Student | student@sd.taylors.edu.my |

## Built-in demo scenarios (from seed data)

| Scenario | Event |
|---|---|
| Last seat + concurrency race | Cloud and DevOps Bootcamp, Huawei Lab (29 of 30) |
| Full event, waitlist, auto-promotion | Mental Health Awareness Workshop (40 of 40, 6 waitlisted) |
| Live QR check-in | Hack Lakeside at Taylor's Makerspace (ongoing; demo student not yet checked in) |
| Venue clash + alternative venues | Draft "Data Science Study Jam" overlaps the Bootcamp in the Huawei Lab |
| Admin approval workflow | 3 events pending approval |
| Rating after attendance | Demo student attended "Intro to Python" but has not rated it |
| Soft delete + audit trail | "Inter-Club Chess Tournament" |

## Group 4 module ownership (for peer review)

| Member | Student ID | Ownership |
|---|---|---|
| Mubarak Rian Abdulmuhsen Ahmed | 374215 | Database, infrastructure, PHP foundation (router, database layer, views, language file, error handling, tests) |
| Danah Abdul Rahim Kollochi | 388012 | Sessions, sign-in and registration, roles, security middleware (CSRF, rate limiting, headers) |
| Banda Shalom Z | 386232 | Booking, waitlist, venue clash, event lifecycle, tickets and check-in, front-end pages |

Each member commits through their own branch and pull requests so the Git
history reflects individual contribution. See `CONTRIBUTING.md`.

## Real campus data and sources

Venue names, locations, clubs and school names come from Taylor's University's
public website (campus facilities and clubs pages, checked 15 Oct 2026):

https://university.taylors.edu.my/en/student-life/campus-facilities.html
https://university.taylors.edu.my/en/student-life/your-taylors-journey/clubs-societies-and-sports.html

Capacities marked `capacity_verified = 1` (The Grand Hall: 1,000; lecture
theatres: 300) are published by the university. All other capacities are
reasonable estimates for the demo. People, student numbers and events are
fictional.

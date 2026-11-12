# Project proposal: Taylor's Events

ITS64504 Web Application Programming, Group 4.

## 1. Problem

Campus events at Taylor's Lakeside fail in four specific ways today.

| # | What goes wrong | Who is hurt |
|---|---|---|
| 1 | Events are announced on separate club social media accounts and mailing lists. A student who does not already follow a club never learns its event exists. | Students miss events. Clubs reach only their existing audience. |
| 2 | Seats are counted by hand in spreadsheets. When two students claim the last place at the same moment, both are told they have it and one is turned away at the door. | The student who is turned away. The organiser who has to do it. |
| 3 | There is no single record of which room is held when. Two organisers book the same lab for the same afternoon and discover it on the day. | Both clubs, and everyone who came. |
| 4 | Attendance is collected on paper. It is slow at the door, easy to sign for someone else, and useless as proof for a co-curricular record. | Students who need evidence. Student Development, who cannot measure turnout. |

## 2. Solution

One web application for the whole campus, where each problem is removed by a
specific mechanism and not by asking people to be more careful.

| Problem | Mechanism |
|---|---|
| 1. Scattered announcements | One searchable list of every approved event from every club, with filters by category and date |
| 2. Double-sold seats | Booking inside a database transaction that locks the event, backed by a CHECK constraint; an automatic waitlist that promotes the longest waiting person when a seat is cancelled |
| 3. Double-booked rooms | A venue calendar enforced by a database trigger using the interval overlap test, with a clear message naming the event already there |
| 4. Paper attendance | A signed, single-use QR ticket scanned with a phone camera; a certificate that anyone can verify online |

## 3. Users

| Role | Can do |
|---|---|
| Student | Browse and search, book a seat, join a waitlist, hold a QR ticket, check in, rate events attended, download certificates, manage their profile |
| Organiser (club committee or university office) | Create events for their own club only, edit drafts, submit for approval, check guests in by camera or code, export the attendee list |
| Administrator (Student Development) | Approve or reject events with a written reason, manage venues and user roles, view analytics, read the audit log |

## 4. Scope

**In scope**

* Accounts: registration with a university address, sign in, password reset, profile
* Event lifecycle with approval: draft, pending approval, published, ongoing, completed, archived
* Booking, cancelling and waitlist with automatic promotion
* Venue management and clash detection
* Signed QR tickets, door check-in by camera or manual code, door log
* Attendance certificates with public verification
* Ratings from attendees
* Notifications in the application; emails captured in an outbox
* Analytics dashboard and an immutable audit log
* Responsive interface down to 390px, with a dark theme

**Out of scope**

| Not included | Why |
|---|---|
| Payments and paid tickets | Campus events are free. Payments would bring a gateway, refunds and compliance work that add nothing to the four problems. |
| Real email delivery | Needs an SMTP account and a network during the demonstration. Messages are written to an outbox table, so the logic is complete and the transport can be added later. |
| Single sign-on with the university's accounts | Needs access to university identity systems that a student project does not have. |
| Native mobile apps | The responsive site and the browser camera cover check-in at the door. |
| Recurring events and multi-session events | Each occurrence can be created as its own event. |
| Room booking for anything other than events | The venue calendar covers events only, not classes or meetings. |
| Languages other than English | All text is already in one language file, so a second language is a second file. |
| Uploading images | Avoids file storage and upload security for no gain against the four problems. |

## 5. Technology stack

| Choice | Why this, and not the alternative |
|---|---|
| PHP 8.3, object-oriented, no framework | Required by the module. Writing the front controller, router, middleware and container ourselves shows what a framework does instead of hiding it. PHP 8.3 gives enums, readonly properties and typed everything, which the domain code uses. |
| MySQL 8, InnoDB | The three guarantees need row locks (`SELECT ... FOR UPDATE`), enforced CHECK constraints (MySQL 8.0.16 and later; MariaDB and older MySQL parse but handle them differently), triggers and transactions. JSON columns hold audit snapshots. |
| PDO with prepared statements, no ORM | Required by the module. Every query is visible in a repository class, and injection is ruled out by construction. |
| HTML, CSS, vanilla JavaScript | Required by the module. No build step means a change is visible on reload. JavaScript is used only where it is needed: the QR code, the camera scanner, the theme switch. Every form works without it. |
| Argon2id, HMAC-SHA256 | Current recommendations for password hashing and message authentication, both built into PHP. |
| GitHub Actions | The schema is loaded on a fresh MySQL 8 and every test suite runs on every push, so "it works on my laptop" is not the evidence. |

Everything is bundled. The application makes no request to the internet at run time.

## 6. Timeline

Five weeks, three people. Each milestone ends with something that runs.

| Week | Dates | Milestone | Done when |
|---|---|---|---|
| 1 | 15 to 21 Oct | Proposal, wireframes, schema, seed data | `schema.sql` and `seed.sql` load on a fresh MySQL 8; database tests pass |
| 2 | 22 to 28 Oct | Foundation and accounts: router, middleware, sessions, sign in, registration, password reset | A user can register, sign in and reset a password; CSRF and rate limiting are on |
| 3 | 29 Oct to 4 Nov | Core rules: booking, waitlist, lifecycle, venue clash, approval | The concurrency test passes: 20 simultaneous bookings, one seat, one winner |
| 4 | 5 to 11 Nov | Tickets, check-in, certificates, administrator pages, visual system | Every page in the scope exists and uses the shared stylesheet |
| 5 | 12 to 16 Nov | Testing, performance measurement, documentation, CI | CI is green on `main`; performance numbers are written up |
| 6 | 17 to 19 Nov | Rehearsal and submission | The demo scenarios run in order from a fresh reset |

## 7. Risks

| Risk | Likelihood | What we do about it |
|---|---|---|
| The demonstration machine has no network | Medium | Nothing is loaded from the internet; a test enforces it |
| The demo data is stale on the day | High, if dates were fixed | Every seeded date is relative to load time; one command reloads it |
| Phone cameras need HTTPS | High | Browsers allow the camera on `localhost`, so the scanner is shown on the laptop; a manual door code exists as a fallback |
| A teammate runs MariaDB (XAMPP) | Medium | The setup script detects MariaDB and stops with an explanation; a Docker option is provided |
| A late change breaks a guarantee | Medium | The guarantees are tested at the database level, in CI, on every push |

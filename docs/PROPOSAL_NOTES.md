# Proposal notes (facts to write the proposal from)

These are the agreed facts. Write the proposal itself in your own words.

## Problem

- Campus events at Lakeside are announced across separate club pages, social
  media and email, so students miss events and organizers cannot see who is
  coming.
- Popular sessions overbook: seats are tracked by hand, and two people can be
  promised the last seat.
- Rooms are double-booked when two clubs ask for the same venue and time.
- Attendance and co-curricular evidence (certificates) are collected on paper.

## Users and roles

| Role | Can do |
|---|---|
| Student | Browse and search events, reserve or join a waitlist, keep a QR ticket, check in, rate, download certificates, manage profile |
| Organizer | Create events for their own club, submit for approval, check guests in with a phone camera, export attendee lists |
| Admin (Student Development) | Approve or reject events with a reason, manage venues and users, view analytics, read the audit log, run system tests |

## Scope

In: accounts and sessions, event lifecycle with approval, booking with
waitlist, venue clash detection, signed QR tickets and door check-in,
certificates, notifications, analytics, audit log, responsive UI with dark mode.

Out: payments, native mobile apps, real email delivery (emails are captured in
an outbox for the demo), integration with university systems.

## Technology choices and why

| Choice | Reason |
|---|---|
| HTML, CSS, JavaScript | Required by the module; no front-end framework so every interaction is visible in our own code |
| PHP 8.3, object-oriented, no framework | Required back end; a small custom router, container and middleware show we understand what frameworks do |
| MySQL 8 (InnoDB) | Transactions and row locks (`SELECT ... FOR UPDATE`) prevent double booking; enforced CHECK constraints; JSON for audit snapshots |
| Argon2id, CSRF tokens, prepared statements, HMAC signed tickets | Standard defences against the OWASP Top 10 risks that apply to this app |
| Docker and GitHub Actions | Same environment on every laptop; automatic checks on every commit |

## Timeline and milestones

| Week | Dates | Milestone |
|---|---|---|
| 1 | 15 to 21 Oct | Proposal, wireframe, database schema and seed data |
| 2 | 22 to 28 Oct | Core: routing, sessions, authentication, security middleware |
| 3 | 29 Oct to 4 Nov | Booking, waitlist, venue clash, lifecycle, tickets |
| 4 | 5 to 11 Nov | Front end: all pages, responsive, dark mode, scanner |
| 5 | 12 to 16 Nov | Testing, bug fixing, report |
| 6 | 17 to 18 Nov | Presentation rehearsal and submission |

## Risks

| Risk | Mitigation |
|---|---|
| Demo machine differs from development machines | Docker environment and a demo reset script |
| Camera access needs HTTPS on phones | Use localhost on the laptop, or an HTTPS tunnel for phone scanning |
| Uneven workload | Module ownership table and per-member branches |

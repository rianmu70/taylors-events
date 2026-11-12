<?php

declare(strict_types=1);

/**
 * Real concurrency: separate operating system processes, each with its own
 * MySQL connection, released at the same instant.
 */

use App\Core\App;
use App\Core\Database;
use App\Domain\RuleViolation;
use App\Services\BookingService;
use App\Services\CheckInService;
use App\Services\EventService;

test('20 simultaneous bookings for the last seat: exactly one is confirmed', function () {
    $event = event_id('Capture the Flag');                    // 29 of 30
    same(1, (int) Database::fetchValue('SELECT capacity - confirmed_count FROM events WHERE id = ?', [$event]), 'seats left before the race');
    $students = free_students($event, 20);
    same(20, count($students), 'students taking part');

    $outcomes = in_parallel(20, function (int $i) use ($event, $students): string {
        return App::container()->get(BookingService::class)->book($event, $students[$i])['status'];
    });

    $tally = array_count_values($outcomes);
    same(['confirmed' => 1, 'waitlisted' => 19], [
        'confirmed' => $tally['confirmed'] ?? 0,
        'waitlisted' => $tally['waitlisted'] ?? 0,
    ], 'outcomes ' . json_encode($tally));

    $row = Database::fetchOne('SELECT capacity, confirmed_count FROM events WHERE id = ?', [$event]);
    same(30, (int) $row['confirmed_count'], 'confirmed_count after the race');
    same(30, (int) Database::fetchValue("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'confirmed'", [$event]), 'confirmed rows after the race');
    same(19, (int) Database::fetchValue("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'waitlisted'", [$event]), 'waitlisted rows after the race');
});

test('20 simultaneous raw INSERTs with no PHP lock at all: the database still sells one seat', function () {
    // Free exactly one seat on a full event, then attack it without BookingService.
    $event = event_id('Rapid Chess Open');                    // 32 of 32
    Database::execute("UPDATE registrations SET status = 'cancelled' WHERE event_id = ? AND status = 'confirmed' LIMIT 1", [$event]);
    same(1, (int) Database::fetchValue('SELECT capacity - confirmed_count FROM events WHERE id = ?', [$event]), 'seats left before the race');
    $students = free_students($event, 20);

    $outcomes = in_parallel(20, function (int $i) use ($event, $students): string {
        try {
            Database::execute("INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, 'confirmed', ?)", [$event, $students[$i], bin2hex(random_bytes(16))]);
            return 'inserted';
        } catch (PDOException $e) {
            // The CHECK constraint, or InnoDB picking this transaction as a deadlock victim. Either way: refused.
            return (int) ($e->errorInfo[1] ?? 0) === 1213 ? 'refused' : (Database::violatedRule($e) === 'chk_events_no_overbook' ? 'refused' : 'unexpected:' . $e->getMessage());
        }
    });

    $tally = array_count_values($outcomes);
    same(['inserted' => 1, 'refused' => 19], ['inserted' => $tally['inserted'] ?? 0, 'refused' => $tally['refused'] ?? 0], 'outcomes ' . json_encode($tally));
    $row = Database::fetchOne('SELECT capacity, confirmed_count FROM events WHERE id = ?', [$event]);
    same((int) $row['capacity'], (int) $row['confirmed_count'], 'confirmed_count after the race');
    same((int) $row['capacity'], (int) Database::fetchValue("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'confirmed'", [$event]), 'confirmed rows after the race');
});

test('8 organisers submit different events for the same room and time: exactly one holds it', function () {
    $organiser = user_id('organizer@taylors.edu.my');
    $drafts = as_root(function () use ($organiser): array {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = Database::insert(
                "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status)
                 VALUES (?, 3, 1, 3, ?, ?, 'x', 'x', CURDATE() + INTERVAL 45 DAY + INTERVAL ? MINUTE, CURDATE() + INTERVAL 45 DAY + INTERVAL ? MINUTE, 50, 'draft')",
                // Staggered by 15 minutes: every pair overlaps, no two are identical.
                [$organiser, "Race for LT-8 number $i", "race-lt8-$i", 600 + $i * 15, 780 + $i * 15],
            );
        }
        return $ids;
    });

    $outcomes = in_parallel(8, function (int $i) use ($drafts, $organiser): string {
        try {
            App::container()->get(EventService::class)->submit($drafts[$i], $organiser);
            return 'submitted';
        } catch (RuleViolation $e) {
            return $e->rule;
        }
    });

    $tally = array_count_values($outcomes);
    same(1, $tally['submitted'] ?? 0, 'submissions accepted ' . json_encode($tally));
    same(7, ($tally['VENUE_CLASH_WITH'] ?? 0) + ($tally['VENUE_CLASH'] ?? 0), 'submissions refused for the clash ' . json_encode($tally));
    same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM events WHERE slug LIKE 'race-lt8-%' AND status = 'pending_approval'"), 'events holding the room');
});

test('8 simultaneous raw submissions with no PHP lock: the trigger alone keeps the room single booked', function () {
    $organiser = user_id('organizer@taylors.edu.my');
    $drafts = as_root(function () use ($organiser): array {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = Database::insert(
                "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status)
                 VALUES (?, 3, 1, 2, ?, ?, 'x', 'x', CURDATE() + INTERVAL 50 DAY + INTERVAL 10 HOUR, CURDATE() + INTERVAL 50 DAY + INTERVAL 12 HOUR, 50, 'draft')",
                [$organiser, "Raw race for LT-7 number $i", "raw-race-lt7-$i"],
            );
        }
        return $ids;
    });

    $outcomes = in_parallel(8, function (int $i) use ($drafts): string {
        try {
            Database::execute("UPDATE events SET status = 'pending_approval' WHERE id = ?", [$drafts[$i]]);
            return 'submitted';
        } catch (PDOException $e) {
            return (int) ($e->errorInfo[1] ?? 0) === 1213 ? 'refused' : (Database::violatedRule($e) === 'VENUE_CLASH' ? 'refused' : 'unexpected:' . $e->getMessage());
        }
    });

    $tally = array_count_values($outcomes);
    same(0, count(array_filter(array_keys($tally), static fn (string $k): bool => str_starts_with($k, 'unexpected'))), 'unexpected errors ' . json_encode($tally));
    same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM events WHERE slug LIKE 'raw-race-lt7-%' AND status = 'pending_approval'"), 'events holding the room');
});

test('10 doors scan the same ticket at once: it is admitted exactly once', function () {
    $event = event_id('Build Night');                          // happening now
    $organiser = user_id('organizer@taylors.edu.my');
    $registration = Database::fetchOne('SELECT * FROM registrations WHERE event_id = ? AND user_id = ?', [$event, user_id('student@sd.taylors.edu.my')]);
    check($registration !== null && $registration['checked_in_at'] === null, 'the demo student should hold an unused ticket');
    $payload = App::container()->get(CheckInService::class)->payloadFor($registration);

    $outcomes = in_parallel(10, function () use ($event, $payload, $organiser): string {
        return App::container()->get(CheckInService::class)->scan($event, $payload, $organiser, '127.0.0.1')['result'];
    });

    $tally = array_count_values($outcomes);
    same(['admitted' => 1, 'duplicate' => 9], ['admitted' => $tally['admitted'] ?? 0, 'duplicate' => $tally['duplicate'] ?? 0], 'outcomes ' . json_encode($tally));
    same(10, (int) Database::fetchValue('SELECT COUNT(*) FROM ticket_scans WHERE registration_id = ?', [$registration['id']]), 'scans written to the door log');
});

test('a cancelled seat goes to the longest waiting person, in the same transaction', function () {
    $event = event_id('Resume Clinic');
    $holder = user_id('student2@sd.taylors.edu.my');
    $first = user_id('student@sd.taylors.edu.my');
    $bookings = App::container()->get(BookingService::class);
    $seat = (int) Database::fetchValue('SELECT id FROM registrations WHERE event_id = ? AND user_id = ?', [$event, $holder]);

    $bookings->cancel($seat, $holder);

    same('confirmed', Database::fetchValue('SELECT status FROM registrations WHERE event_id = ? AND user_id = ?', [$event, $first]), 'the first person in the queue');
    same(24, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'the event is still exactly full');
    same(5, (int) Database::fetchValue("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'waitlisted'", [$event]), 'people still waiting');
    same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'booking_promoted'", [$first]), 'promotion notifications');
});

test('the same booking form sent twice books once (idempotency key)', function () {
    $event = event_id('Flix Club Movie Night');
    [$student] = free_students($event, 1);
    $bookings = App::container()->get(BookingService::class);
    $key = '0b0e6f3a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    $before = (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]);

    $first = $bookings->book($event, $student, $key);
    $second = $bookings->book($event, $student, $key);

    same([false, true], [$first['replayed'], $second['replayed']], 'replayed flags');
    same($first['registration_id'], $second['registration_id'], 'registration returned by the replay');
    same($before + 1, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'seats taken');
    try {
        $bookings->book($event, $student);
        check(false, 'a second booking without the key was accepted');
    } catch (RuleViolation $e) {
        same('ALREADY_BOOKED', $e->rule, 'rule for a genuine second booking');
    }
});

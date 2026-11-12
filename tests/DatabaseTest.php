<?php

declare(strict_types=1);

/**
 * The database refuses each forbidden operation by itself.
 *
 * Every test here talks to MySQL as the application account and bypasses the
 * PHP services completely, the way a buggy controller or someone with a SQL
 * console would. If these pass, the rules hold whatever the PHP does.
 */

use App\Core\Database;
use App\Domain\EventStatus;

/** Run $work inside a transaction that is always rolled back, so tests do not disturb each other. */
function rolled_back(callable $work): void
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        $work();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

test('the tests run as the least privileged application account', function () {
    $grants = implode("\n", array_map(static fn (array $row): string => (string) reset($row), Database::fetchAll('SHOW GRANTS')));
    check(str_starts_with((string) Database::fetchValue('SELECT CURRENT_USER()'), 'taylors_app@'), 'not connected as taylors_app');
    check(preg_match('/GRANT SELECT, INSERT, UPDATE, DELETE ON `taylors_events`\.\*/', $grants) === 1, "unexpected grants:\n$grants");
    check(preg_match('/\b(ALL PRIVILEGES|DROP|ALTER|TRIGGER|CREATE|SUPER|EXECUTE)\b/', $grants) === 0, "the account has more than row access:\n$grants");
});

test('seed data: the four demonstration scenarios are present whenever the seed is loaded', function () {
    same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM events WHERE status = 'ongoing' AND starts_at <= NOW() AND ends_at > NOW()"), 'events happening now');
    same(1, (int) Database::fetchValue("SELECT COUNT(*) FROM events WHERE status = 'published' AND capacity - confirmed_count = 1"), 'events with one seat left');
    check((int) Database::fetchValue("SELECT COUNT(*) FROM events e WHERE status = 'published' AND capacity = confirmed_count AND EXISTS (SELECT 1 FROM registrations r WHERE r.event_id = e.id AND r.status = 'waitlisted')") >= 1, 'no full event with a waitlist');
    same(1, (int) Database::fetchValue(
        "SELECT COUNT(*) FROM events d JOIN events b ON b.venue_id = d.venue_id AND b.id <> d.id
          WHERE d.status = 'draft' AND b.status IN ('pending_approval','published','ongoing')
            AND d.starts_at < b.ends_at AND d.ends_at > b.starts_at",
    ), 'drafts that clash with a booked venue');
    same(0, (int) Database::fetchValue("SELECT COUNT(*) FROM events e WHERE confirmed_count <> (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'confirmed')"), 'events whose seat count is wrong');
    same([300, 15, 14, 14, 24], array_map('intval', array_values(Database::fetchOne("SELECT (SELECT COUNT(*) FROM users WHERE role = 'student') a, (SELECT COUNT(*) FROM faculties) b, (SELECT COUNT(*) FROM venues) c, (SELECT COUNT(*) FROM clubs) d, (SELECT COUNT(*) FROM events) e"))), 'students, schools, venues, clubs, events');
    check(password_verify('Demo@12345', (string) Database::fetchValue("SELECT password_hash FROM users WHERE email = 'admin@taylors.edu.my'")), 'the demo password does not match');
    check(str_starts_with((string) Database::fetchValue("SELECT password_hash FROM users WHERE email = 'admin@taylors.edu.my'"), '$argon2id$'), 'passwords are not Argon2id');
});

// ------------------------------------------------- GUARANTEE 1: no overbooking

test('guarantee 1: a confirmed booking beyond capacity is refused by the CHECK constraint', function () {
    $event = event_id('Resume Clinic');                       // seeded full: 24 of 24
    [$student] = free_students($event, 1);
    refused('chk_events_no_overbook', fn () => Database::execute(
        "INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, 'confirmed', ?)",
        [$event, $student, bin2hex(random_bytes(16))],
    ), 'inserting a 25th confirmed booking');
    same(24, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'seat count afterwards');
    same(0, (int) Database::fetchValue('SELECT COUNT(*) FROM registrations WHERE event_id = ? AND user_id = ?', [$event, $student]), 'rows left behind');
});

test('guarantee 1: promoting from the waitlist into a full event is refused', function () {
    $event = event_id('Resume Clinic');
    $waiting = (int) Database::fetchValue("SELECT id FROM registrations WHERE event_id = ? AND status = 'waitlisted' LIMIT 1", [$event]);
    refused('chk_events_no_overbook', fn () => Database::execute("UPDATE registrations SET status = 'confirmed' WHERE id = ?", [$waiting]), 'confirming a waitlisted booking');
});

test('guarantee 1: the seat count cannot be edited past capacity', function () {
    refused('chk_events_no_overbook', fn () => Database::execute('UPDATE events SET confirmed_count = capacity + 1 WHERE id = ?', [event_id('Capture the Flag')]), 'raising confirmed_count');
    refused('chk_events_no_overbook', fn () => Database::execute('UPDATE events SET capacity = confirmed_count - 1 WHERE id = ?', [event_id('Capture the Flag')]), 'shrinking capacity below the bookings');
});

test('guarantee 1: the seat count follows the bookings without any PHP', function () {
    rolled_back(function () {
        $event = event_id('Capture the Flag');                // 29 of 30
        [$student] = free_students($event, 1);
        Database::execute("INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, 'confirmed', ?)", [$event, $student, bin2hex(random_bytes(16))]);
        same(30, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'after a booking');
        Database::execute("UPDATE registrations SET status = 'cancelled' WHERE event_id = ? AND user_id = ?", [$event, $student]);
        same(29, (int) Database::fetchValue('SELECT confirmed_count FROM events WHERE id = ?', [$event]), 'after cancelling it');
    });
});

// ---------------------------------------------- GUARANTEE 2: no double booking

test('guarantee 2: submitting the seeded clashing draft is refused by the trigger', function () {
    refused('VENUE_CLASH', fn () => Database::execute("UPDATE events SET status = 'pending_approval' WHERE id = ?", [event_id('Git and GitHub')]), 'submitting a draft into a booked lab');
    same('draft', Database::fetchValue('SELECT status FROM events WHERE id = ?', [event_id('Git and GitHub')]), 'status afterwards');
});

test('guarantee 2: moving a published event onto a taken slot is refused', function () {
    $ctf = Database::fetchOne('SELECT venue_id, starts_at, ends_at FROM events WHERE id = ?', [event_id('Capture the Flag')]);
    $other = event_id('Table Topics');
    refused('VENUE_CLASH', fn () => Database::execute('UPDATE events SET venue_id = ?, starts_at = ?, ends_at = ? WHERE id = ?', [$ctf['venue_id'], $ctf['starts_at'], $ctf['ends_at'], $other]), 'moving into the same room and time');
    // Overlap by a single minute at either end is still an overlap.
    refused('VENUE_CLASH', fn () => Database::execute('UPDATE events SET venue_id = ?, starts_at = ? - INTERVAL 2 HOUR, ends_at = ? + INTERVAL 1 MINUTE WHERE id = ?', [$ctf['venue_id'], $ctf['starts_at'], $ctf['starts_at'], $other]), 'ending one minute into the other event');
    refused('VENUE_CLASH', fn () => Database::execute('UPDATE events SET venue_id = ?, starts_at = ? - INTERVAL 1 MINUTE, ends_at = ? + INTERVAL 2 HOUR WHERE id = ?', [$ctf['venue_id'], $ctf['ends_at'], $ctf['ends_at'], $other]), 'starting one minute before the other ends');
});

test('guarantee 2: back to back events in the same room are allowed', function () {
    rolled_back(function () {
        $ctf = Database::fetchOne('SELECT venue_id, ends_at FROM events WHERE id = ?', [event_id('Capture the Flag')]);
        Database::execute('UPDATE events SET venue_id = ?, starts_at = ?, ends_at = ? + INTERVAL 2 HOUR, capacity = 30 WHERE id = ?', [$ctf['venue_id'], $ctf['ends_at'], $ctf['ends_at'], event_id('Table Topics')]);
        check(true, 'accepted');
    });
});

test('guarantee 2: an event cannot be larger than its room', function () {
    refused('VENUE_CAPACITY', fn () => Database::execute('UPDATE events SET capacity = 61 WHERE id = ?', [event_id('Git and GitHub')]), 'capacity 61 in a lab for 60');
});

// ------------------------------------------- GUARANTEE 3: immutable audit log

test('guarantee 3: UPDATE on the audit log is refused', function () {
    refused('AUDIT_IMMUTABLE', fn () => Database::execute("UPDATE audit_logs SET action = 'tampered' WHERE id = 1"), 'editing an audit entry');
    refused('AUDIT_IMMUTABLE', fn () => Database::execute('UPDATE audit_logs SET actor_id = NULL'), 'editing every audit entry');
});

test('guarantee 3: DELETE on the audit log is refused', function () {
    $before = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs');
    refused('AUDIT_IMMUTABLE', fn () => Database::execute('DELETE FROM audit_logs WHERE id = 1'), 'deleting an audit entry');
    refused('AUDIT_IMMUTABLE', fn () => Database::execute('DELETE FROM audit_logs'), 'deleting every audit entry');
    same($before, (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs'), 'entries afterwards');
});

test('guarantee 3: the application account cannot remove or bypass the triggers', function () {
    $attempts = [
        'DROP TRIGGER trg_audit_no_update',
        'DROP TRIGGER trg_audit_no_delete',
        'DROP TRIGGER trg_events_bu',
        'TRUNCATE TABLE audit_logs',
        'DROP TABLE audit_logs',
        'ALTER TABLE audit_logs ADD COLUMN note VARCHAR(10)',
        'ALTER TABLE events DROP CHECK chk_events_no_overbook',
        'CREATE TRIGGER trg_x BEFORE INSERT ON faculties FOR EACH ROW SET NEW.code = NEW.code',
        'CREATE TABLE audit_copy LIKE audit_logs',
        "GRANT ALL ON taylors_events.* TO 'taylors_app'@'localhost'",
    ];
    foreach ($attempts as $sql) {
        refused('DENIED', fn () => Database::connection()->exec($sql), $sql);
    }
});

test('guarantee 3: even a privileged account cannot rewrite history', function () {
    as_root(function () {
        refused('AUDIT_IMMUTABLE', fn () => Database::execute("UPDATE audit_logs SET action = 'tampered' WHERE id = 1"), 'root editing an audit entry');
        refused('AUDIT_IMMUTABLE', fn () => Database::execute('DELETE FROM audit_logs WHERE id = 1'), 'root deleting an audit entry');
    });
});

test('guarantee 3: an audit entry survives the deletion of the account it describes', function () {
    $entry = as_root(function (): int {
        $user = Database::insert("INSERT INTO users (full_name, email, password_hash) VALUES ('Leaving Soon', 'leaving@sd.taylors.edu.my', 'x')");
        $entry = Database::insert("INSERT INTO audit_logs (actor_id, action, entity_type, entity_id) VALUES (?, 'auth.login', 'user', ?)", [$user, $user]);
        Database::execute('DELETE FROM users WHERE id = ?', [$user]);
        return $entry;
    });
    same('auth.login', Database::fetchValue('SELECT action FROM audit_logs WHERE id = ?', [$entry]), 'the entry after its account was deleted');
});

test('changes are audited by trigger with before and after snapshots', function () {
    rolled_back(function () {
        $event = event_id('Git and GitHub');
        Database::setAuditContext(user_id('organizer@taylors.edu.my'), '198.51.100.7');
        Database::execute("UPDATE events SET title = 'Git and GitHub Workshop, part one' WHERE id = ?", [$event]);
        $entry = Database::fetchOne("SELECT * FROM audit_logs WHERE entity_type = 'event' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$event]);
        same('event.update', $entry['action'], 'action');
        same(user_id('organizer@taylors.edu.my'), (int) $entry['actor_id'], 'actor');
        same('198.51.100.7', $entry['ip_address'], 'address');
        same('Git and GitHub Workshop', json_decode((string) $entry['before_data'], true)['title'], 'before');
        same('Git and GitHub Workshop, part one', json_decode((string) $entry['after_data'], true)['title'], 'after');
        Database::setAuditContext(null, null);
    });
});

test('the door log is append only as well', function () {
    refused('SCANS_IMMUTABLE', fn () => Database::execute("UPDATE ticket_scans SET result = 'admitted' WHERE result <> 'admitted'"), 'rewriting refused scans');
    refused('SCANS_IMMUTABLE', fn () => Database::execute('DELETE FROM ticket_scans LIMIT 1'), 'deleting a scan');
});

// ------------------------------------------------------- lifecycle state machine

test('lifecycle: the trigger accepts exactly the moves the PHP state machine allows (all 30 pairs)', function () {
    $admin = user_id('admin@taylors.edu.my');
    $organiser = user_id('organizer@taylors.edu.my');
    $event = as_root(fn (): int => Database::insert(
        "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status)
         VALUES (?, 3, 1, 3, 'Lifecycle probe', 'lifecycle-probe', 'x', 'x', NOW() + INTERVAL 60 DAY, NOW() + INTERVAL 60 DAY + INTERVAL 2 HOUR, 10, 'draft')",
        [$organiser],
    ));
    $disagreements = [];
    foreach (EventStatus::cases() as $from) {
        foreach (EventStatus::cases() as $to) {
            if ($from === $to) {
                continue;
            }
            as_root(fn () => Database::execute('UPDATE events SET status = ?, approved_by = NULL, rejection_reason = NULL WHERE id = ?', [$from->value, $event]));
            try {
                // Supply an approver and a reason so only the move itself is being judged.
                Database::execute("UPDATE events SET status = ?, approved_by = ?, rejection_reason = 'A reason that is long enough.' WHERE id = ?", [$to->value, $admin, $event]);
                $accepted = true;
            } catch (PDOException $e) {
                same('LIFECYCLE', Database::violatedRule($e), "{$from->value} to {$to->value} was refused for the wrong reason");
                $accepted = false;
            }
            if ($accepted !== $from->canBecome($to)) {
                $disagreements[] = "{$from->value} > {$to->value}: database " . ($accepted ? 'accepts' : 'refuses') . ', PHP ' . ($from->canBecome($to) ? 'allows' : 'forbids');
            }
        }
    }
    same([], $disagreements, 'pairs where PHP and the database disagree');
});

test('lifecycle: a new event must be a draft', function () {
    refused('LIFECYCLE', fn () => Database::execute(
        "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status)
         VALUES (?, 3, 1, 3, 'Skips approval', 'skips-approval', 'x', 'x', NOW() + INTERVAL 70 DAY, NOW() + INTERVAL 70 DAY + INTERVAL 1 HOUR, 10, 'published')",
        [user_id('organizer@taylors.edu.my')],
    ), 'inserting an event that is already published');
});

test('lifecycle: the application account cannot switch the rules off with seed mode', function () {
    Database::execute('SET @seed_mode = 1');
    try {
        refused('LIFECYCLE', fn () => Database::execute("UPDATE events SET status = 'archived' WHERE id = ?", [event_id('Git and GitHub')]), 'draft to archived with @seed_mode = 1');
    } finally {
        Database::execute('SET @seed_mode = NULL');
    }
});

test('lifecycle: a published event with booked seats cannot be withdrawn', function () {
    refused('LIFECYCLE', fn () => Database::execute("UPDATE events SET status = 'draft' WHERE id = ?", [event_id('Capture the Flag')]), 'withdrawing an event with 29 bookings');
});

test('approval: an organiser can never approve their own event', function () {
    $event = event_id('Machine Learning Study Jam');           // pending, hosted by the demo organiser's club
    $attempts = [
        'the organiser' => user_id('organizer@taylors.edu.my'),
        'a committee member of the same club' => user_id('adrian.chong@taylors.edu.my'),
        'an organiser of another club' => user_id('music@taylors.edu.my'),
        'a student' => user_id('student@sd.taylors.edu.my'),
        'nobody' => null,
    ];
    foreach ($attempts as $who => $approver) {
        refused('SELF_APPROVAL', fn () => Database::execute("UPDATE events SET status = 'published', approved_by = ? WHERE id = ?", [$approver, $event]), "approval by $who");
    }
    rolled_back(function () use ($event) {
        Database::execute("UPDATE events SET status = 'published', approved_by = ? WHERE id = ?", [user_id('admin@taylors.edu.my'), $event]);
        same('published', Database::fetchValue('SELECT status FROM events WHERE id = ?', [$event]), 'approval by an administrator');
    });
});

test('approval: a rejection needs a written reason', function () {
    $event = event_id('Acoustic Night');
    refused('REJECTION_REASON', fn () => Database::execute("UPDATE events SET status = 'draft' WHERE id = ?", [$event]), 'rejecting with no reason');
    refused('REJECTION_REASON', fn () => Database::execute("UPDATE events SET status = 'draft', rejection_reason = 'no' WHERE id = ?", [$event]), 'rejecting with a two letter reason');
});

test('clubs: an organiser cannot create an event for a club they do not run', function () {
    refused('CLUB_SCOPE', fn () => Database::execute(
        "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity)
         VALUES (?, 6, 3, 3, 'Not my club', 'not-my-club', 'x', 'x', NOW() + INTERVAL 80 DAY, NOW() + INTERVAL 80 DAY + INTERVAL 1 HOUR, 10)",
        [user_id('organizer@taylors.edu.my')],
    ), 'the tech organiser creating a Music Club event');
});

test('bookings: only open events can be booked, and bookings are never deleted', function () {
    [$student] = free_students(event_id('Git and GitHub'), 1);
    refused('EVENT_CLOSED', fn () => Database::execute("INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, 'confirmed', ?)", [event_id('Git and GitHub'), $student, bin2hex(random_bytes(16))]), 'booking a draft');
    refused('EVENT_CLOSED', fn () => Database::execute("INSERT INTO registrations (event_id, user_id, status, ticket_nonce) VALUES (?, ?, 'confirmed', ?)", [event_id('Introduction to Docker'), $student, bin2hex(random_bytes(16))]), 'booking a finished event');
    refused('REGISTRATION', fn () => Database::execute('DELETE FROM registrations WHERE event_id = ? LIMIT 1', [event_id('Capture the Flag')]), 'deleting a booking');
    refused('DUPLICATE', fn () => Database::execute(
        "INSERT INTO registrations (event_id, user_id, status, ticket_nonce) SELECT event_id, user_id, 'waitlisted', ? FROM registrations WHERE event_id = ? LIMIT 1",
        [bin2hex(random_bytes(16)), event_id('Capture the Flag')],
    ), 'a second booking for the same person and event');
});

test('check-in: only a confirmed seat can be checked in, and a certificate needs a check-in', function () {
    $event = event_id('Resume Clinic');
    $waiting = (int) Database::fetchValue("SELECT id FROM registrations WHERE event_id = ? AND status = 'waitlisted' LIMIT 1", [$event]);
    refused('chk_registrations_checkin', fn () => Database::execute('UPDATE registrations SET checked_in_at = NOW() WHERE id = ?', [$waiting]), 'checking in a waitlisted booking');
    refused('chk_registrations_cert', fn () => Database::execute("UPDATE registrations SET certificate_code = 'ABCDEF123456' WHERE id = ?", [$waiting]), 'a certificate without a check-in');
});

test('ratings: only people who were checked in can rate, from 1 to 5', function () {
    $docker = event_id('Introduction to Docker');
    $attendee = user_id('student@sd.taylors.edu.my');          // attended, has not rated
    $stranger = (int) Database::fetchValue("SELECT u.id FROM users u WHERE u.role = 'student' AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.event_id = ?) LIMIT 1", [$docker]);
    refused('RATING', fn () => Database::execute('INSERT INTO event_ratings (event_id, user_id, rating) VALUES (?, ?, 5)', [$docker, $stranger]), 'a rating from someone who was not there');
    refused('RATING', fn () => Database::execute('INSERT INTO event_ratings (event_id, user_id, rating) VALUES (?, ?, 5)', [event_id('Build Night'), $attendee]), 'rating an event that has not ended');
    refused('chk_ratings_range', fn () => Database::execute('INSERT INTO event_ratings (event_id, user_id, rating) VALUES (?, ?, 6)', [$docker, $attendee]), 'a rating of 6');
});

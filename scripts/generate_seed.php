<?php

declare(strict_types=1);

/**
 * Writes database/seed.sql: the demonstration data.
 *
 *     php scripts/generate_seed.php
 *
 * The output is committed, so nobody needs to run this to use the project.
 * It exists so the data can be regenerated or resized. A fixed random seed
 * makes every run produce the same people and bookings.
 *
 * Every date in the output is an SQL expression relative to the moment the
 * file is loaded (@now, @today, @hour), never a fixed date. Whenever the
 * seed is loaded there is one event happening now, one with a single seat
 * left, one full with a waitlist and one draft that clashes with a booked
 * venue.
 */

mt_srand(64504);

const DEMO_PASSWORD = 'Demo@12345';
const STUDENTS = 300;

$q = static fn (?string $v): string => $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $v) . "'";
$hex = static function (int $bytes): string {
    $out = '';
    for ($i = 0; $i < $bytes; $i++) {
        $out .= sprintf('%02x', mt_rand(0, 255));
    }
    return $out;
};
$pick = static fn (array $list): mixed => $list[mt_rand(0, count($list) - 1)];
$hash = password_hash(DEMO_PASSWORD, PASSWORD_ARGON2ID);

// ------------------------------------------------------------ reference data
$faculties = [
    ['SCS', 'School of Computer Science', 14], ['TBS', "Taylor's Business School", 16], ['SOE', 'School of Engineering', 10],
    ['SOM', 'School of Medicine', 5], ['SOP', 'School of Pharmacy', 4], ['SOB', 'School of Biosciences', 5],
    ['SHTE', 'School of Hospitality, Tourism and Events', 9], ['TCI', "Taylor's Culinary Institute", 5],
    ['SFSG', 'School of Food Studies and Gastronomy', 3], ['SLAS', 'School of Liberal Arts and Sciences', 5],
    ['SMC', 'School of Media and Communication', 8], ['TDS', "The Design School at Taylor's", 6],
    ['SABD', 'School of Architecture, Building and Design', 5], ['TLS', "Taylor's Law School", 3], ['SED', 'School of Education', 2],
];
$venues = [
    ['GRAND-HALL', 'The Grand Hall', 'Block B', '2', 1000, 'hall', 1],
    ['LT-7', 'Lecture Theatre 7', 'Block D', null, 300, 'lecture_theatre', 1],
    ['LT-8', 'Lecture Theatre 8', 'Block D', null, 300, 'lecture_theatre', 1],
    ['EXP-THEATRE', 'Experimental Theatre', 'Block B', '2', 150, 'theatre', 1],
    ['VX-THEATRE', "Taylor's VX Learning Theatre", 'Block C', '6', 120, 'lecture_theatre', 1],
    ['MAKERSPACE', "Taylor's Makerspace", 'Block E', null, 40, 'lab', 1],
    ['CYBER-LAB', 'Cybersecurity Lab', 'Block C', '7', 60, 'lab', 1],
    ['HUAWEI-LAB', 'Huawei Lab', 'Block C', '7', 40, 'lab', 1],
    ['XSPACE-D3', 'X-Space Classroom D3', 'Block D', '3', 45, 'classroom', 1],
    ['XSPACE-E5', 'X-Space Classroom E5', 'Block E', '5', 45, 'classroom', 1],
    ['ROBOTIC-HUB', 'Robotic Hub', 'Block D', '1', 60, 'lab', 1],
    ['TRADING-ROOM', "Taylor's Trading Room", 'Block C', '1', 40, 'lab', 1],
    ['SLC', 'Student Life Centre', 'Block B', '1', 120, 'lounge', 0],
    ['ARCADIA', 'Arcadia Breakout Area', 'Blocks C and D', '2', 250, 'breakout', 0],
];
$categories = [
    ['Technology', 'technology'], ['Career and industry', 'career'], ['Arts and culture', 'arts-culture'],
    ['Sports and wellness', 'sports-wellness'], ['Workshops', 'workshops'], ['Clubs and societies', 'clubs'],
    ['Competitions', 'competitions'], ['Community service', 'community'],
];
$clubs = [
    ['Student Development', 'student-development', 'office'], ['Career Services', 'career-services', 'office'],
    ["Taylor's Agents of Tech", 'agents-of-tech', 'club'], ["Taylor's Entrepreneurship Club", 'entrepreneurship-club', 'club'],
    ['Bursa Young Investors Club', 'bursa-yic', 'club'], ["Taylor's Music Club", 'music-club', 'club'],
    ["Taylor's Flix Club", 'flix-club', 'club'], ["Taylor's International Student Society", 'tiss', 'society'],
    ["Taylor's C.S.I Volunteers", 'csi-volunteers', 'club'], ["Taylor's Toastmasters Club", 'toastmasters', 'club'],
    ["Taylor's Football and Futsal Club", 'futsal-club', 'sports'], ["Taylor's Badminton Club", 'badminton-club', 'sports'],
    ["Taylor's Chess Club", 'chess-club', 'sports'], ["Taylor's University Student Council", 'student-council', 'society'],
];

// ------------------------------------------------------------------- people
$given = ['Aiman', 'Nurul', 'Wei Jie', 'Siew Ling', 'Arjun', 'Priya', 'Daniel', 'Aisyah', 'Jun Hao', 'Mei Xin', 'Haziq', 'Farah',
    'Kavin', 'Divya', 'Zhi Wei', 'Xin Yi', 'Irfan', 'Sofea', 'Ethan', 'Chloe', 'Hafiz', 'Nadia', 'Yong Sheng', 'Hui Min',
    'Rahul', 'Anjali', 'Adam', 'Sarah', 'Kai Xuan', 'Jia Hui', 'Amir', 'Liyana', 'Vikram', 'Shalini', 'Ryan', 'Natasha',
    'Omar', 'Layla', 'Minh', 'Thanh', 'Budi', 'Putri', 'Yusuf', 'Zara', 'Kenji', 'Hana', 'Marcus', 'Elena'];
$family = ['Abdullah', 'Tan', 'Lim', 'Lee', 'Wong', 'Kumar', 'Rahman', 'Ng', 'Chong', 'Ismail', 'Raj', 'Chan', 'Hassan', 'Goh',
    'Teo', 'Nair', 'Yusof', 'Ong', 'Singh', 'Low', 'Aziz', 'Koh', 'Pillai', 'Chua', 'Othman', 'Yap', 'Menon', 'Lau',
    'Ibrahim', 'Foo', 'Nguyen', 'Santoso', 'Khan', 'Sato', 'Fernandez', 'Petrov'];

$weighted = [];
foreach ($faculties as $i => $f) {
    $weighted = array_merge($weighted, array_fill(0, $f[2], $i + 1));
}

$users = [];   // id => [faculty, student_number, name, email, role]
$addUser = static function (?int $faculty, ?string $number, string $name, string $email, string $role) use (&$users): int {
    $users[count($users) + 1] = [$faculty, $number, $name, $email, $role];
    return count($users);
};

$ADMIN = $addUser(null, null, 'Farid Hamzah', 'admin@taylors.edu.my', 'admin');
$addUser(null, null, 'Grace Fernandez', 'grace.fernandez@taylors.edu.my', 'admin');

// One president per club. The demo organiser runs Taylor's Agents of Tech.
$presidents = [];
$staffNames = ['Melissa Tan', 'Rajesh Nair', 'Aina Zulkifli', 'Jason Lim', 'Suresh Pillai', 'Emily Wong', 'Hakim Rosli',
    'Linh Nguyen', 'Deepa Menon', 'Benjamin Koh', 'Syafiq Azman', 'Michelle Lau', 'Ivan Petrov', 'Nur Iman Yusof'];
foreach ($clubs as $i => $club) {
    $clubId = $i + 1;
    $name = $staffNames[$i];
    $email = $clubId === 3 ? 'organizer@taylors.edu.my' : ($clubId === 6 ? 'music@taylors.edu.my'
        : strtolower(str_replace(' ', '.', $name)) . '@taylors.edu.my');
    $presidents[$clubId] = $addUser($club[2] === 'office' ? null : mt_rand(1, 15), null, $name, $email, 'organizer');
}
$ORGANISER = $presidents[3];
$committee = [];
foreach ([3 => 'Adrian Chong', 14 => 'Hannah Abdullah'] as $clubId => $name) {
    $committee[$clubId] = $addUser(mt_rand(1, 15), null, $name, strtolower(str_replace(' ', '.', $name)) . '@taylors.edu.my', 'organizer');
}

$students = [];
$usedEmails = [];
$DEMO_STUDENT = $addUser(1, '0364504', 'Aina Sofea Rahman', 'student@sd.taylors.edu.my', 'student');
$DEMO_STUDENT_2 = $addUser(2, '0364505', 'Marcus Lee', 'student2@sd.taylors.edu.my', 'student');
$students = [$DEMO_STUDENT, $DEMO_STUDENT_2];
while (count($students) < STUDENTS) {
    $name = $pick($given) . ' ' . $pick($family);
    $slug = strtolower(str_replace(' ', '', $name));
    $email = $slug . mt_rand(10, 99) . '@sd.taylors.edu.my';
    if (isset($usedEmails[$email])) {
        continue;
    }
    $usedEmails[$email] = true;
    $students[] = $addUser($pick($weighted), '03' . str_pad((string) (50000 + count($students) * 7), 5, '0', STR_PAD_LEFT), $name, $email, 'student');
}
$pool = array_values(array_diff($students, [$DEMO_STUDENT, $DEMO_STUDENT_2]));

// ------------------------------------------------------------------- events
// [title, summary, club, category, venue, start SQL, hours, capacity, status, confirmed, waitlisted, extra]
$day = static fn (int $d, float $h): string => sprintf('@today + INTERVAL %d DAY + INTERVAL %d MINUTE', $d, (int) round($h * 60));
$events = [
    ['Build Night: Ship a Web App in an Evening', 'Bring a laptop and an idea. Mentors help you take it from blank page to deployed by the end of the night.', 3, 5, 6, '@hour - INTERVAL 1 HOUR', 3, 40, 'ongoing', 34, 0],
    ['Capture the Flag: Beginner Cybersecurity Challenge', 'A three hour hacking competition for first timers, in teams of two. Laptops provided in the lab.', 3, 7, 7, $day(2, 14), 3, 30, 'published', 29, 0],
    ['Resume Clinic with Industry Recruiters', 'Fifteen minutes one to one with a recruiter from a Malaysian employer. Bring a printed resume.', 2, 2, 12, $day(3, 10), 2, 24, 'published', 24, 6],
    ['Git and GitHub Workshop', 'Branches, pull requests and resolving conflicts, taught hands-on for students who have never used version control.', 3, 5, 7, $day(2, 15), 2, 40, 'draft', 0, 0],
    ['Machine Learning Study Jam', 'Work through an image classifier together, from loading the data to reading the confusion matrix.', 3, 1, 8, $day(9, 14), 2, 36, 'pending_approval', 0, 0],
    ['Acoustic Night at the Experimental Theatre', 'Twelve student acts, two songs each. Doors open half an hour before the first set.', 6, 3, 4, $day(12, 19), 2, 150, 'pending_approval', 0, 0],
    ['Five-a-side Futsal Mixer', 'Turn up alone or with friends and get sorted into mixed teams. Bibs and balls provided.', 11, 4, 13, $day(8, 17), 2, 60, 'pending_approval', 0, 0],
    ['Public Speaking Bootcamp', 'Three short speeches in one afternoon, each with written feedback from an experienced Toastmaster.', 10, 5, 9, $day(6, 14), 3, 40, 'draft', 0, 0, 'The description does not say who is giving the feedback. Add the names of the evaluators and submit again.'],
    ['Student Council Town Hall', 'The council reports on this semester and takes questions from the floor. Every student has a vote on the three motions.', 14, 6, 1, $day(5, 18), 3, 300, 'published', 205, 0],
    ['Founders Fireside: Starting a Company While Studying', 'Three alumni founders talk about what they got wrong in their first year, then take questions.', 4, 2, 2, $day(4, 14), 2, 200, 'published', 148, 0],
    ['Stock Pitch Competition', 'Teams of three pitch one Bursa-listed company to a panel of analysts. Ten minutes each.', 5, 7, 12, $day(7, 15), 2, 40, 'published', 37, 0],
    ['Flix Club Movie Night', 'A double bill voted for by members, with popcorn. The titles are announced on the door.', 7, 3, 4, $day(6, 19), 3, 120, 'published', 45, 0],
    ['Global Village Food Fair', 'Thirty stalls run by international students, each cooking one dish from home.', 8, 3, 14, $day(10, 12), 3, 250, 'published', 96, 0],
    ['Beach Clean-up Volunteer Briefing', 'Compulsory briefing for everyone joining the Port Dickson clean-up: transport, safety and what to bring.', 9, 8, 13, $day(4, 9), 3, 80, 'published', 71, 0],
    ['Badminton Doubles Social', 'Casual doubles for all levels. Rackets available to borrow, shuttles provided.', 12, 4, 13, $day(11, 18), 3, 48, 'published', 12, 0],
    ['Rapid Chess Open', 'Five rounds of rapid chess, ten minutes each side. Boards and clocks provided.', 13, 7, 10, $day(3, 14), 4, 32, 'published', 32, 3],
    ['Study Skills for Finals', 'How to plan four weeks of revision, with templates you can take away. Run by Student Development.', 1, 5, 5, $day(1, 10), 2, 100, 'published', 58, 0],
    ['Table Topics Night', 'Two minutes, one surprise topic, a friendly room. The gentlest way to practise speaking without notes.', 10, 5, 9, $day(2, 18), 2, 40, 'published', 22, 0],
    ['Introduction to Docker', 'Containers explained from first principles, then build and run your own image.', 3, 1, 7, $day(-3, 14), 3, 50, 'completed', 48, 0],
    ['Career Fair Preparation Talk', 'How to research employers, what to ask at a booth and how to follow up afterwards.', 2, 2, 2, $day(-6, 15), 2, 180, 'completed', 150, 0],
    ['Battle of the Bands: Heats', 'Eight bands, four places in the final. Judged by lecturers and decided by the room.', 6, 3, 4, $day(-9, 19), 3, 140, 'completed', 120, 0],
    ['Community Kitchen Volunteer Day', 'Cook and pack two hundred meals for a shelter in Subang Jaya.', 9, 8, 14, $day(-12, 9), 4, 100, 'completed', 64, 0],
    ['Orientation Welcome Assembly', 'The official welcome for the new intake, with the club showcase straight afterwards.', 14, 6, 1, $day(-25, 10), 2, 300, 'archived', 180, 0],
    ['Startup Weekend Pitch Final', 'The six finalist teams pitch to investors after fifty four hours of building.', 4, 7, 11, $day(-30, 16), 3, 60, 'archived', 52, 0],
];

$descriptions = [
    "What to expect\nThe session is practical from the first minute. You will work in small groups and leave with something finished.\n\nWho it is for\nOpen to every Taylor's student. No experience is needed and all materials are provided.\n\nGood to know\nPlease arrive ten minutes early so we can start on time. Seats are limited to what the room can hold.",
    "About the event\nThis is one of the most asked for sessions of the semester, and places usually go within a few days.\n\nOn the day\nRegistration opens at the door one hour before the start. Have your QR ticket ready on your phone.\n\nAfterwards\nEveryone who is checked in receives an attendance certificate for their co-curricular record.",
];

$slug = static fn (string $t): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');

// ----------------------------------------------------------------- output
$out = [];
$out[] = <<<SQL
-- =============================================================================
--  Taylor's Events : demonstration data. Generated by scripts/generate_seed.php
--  Load after schema.sql:   mysql -u root -p < database/seed.sql
--
--  Every date is relative to the moment this file is loaded, so the
--  demonstration scenarios are always in place:
--    happening now      Build Night: Ship a Web App in an Evening
--    one seat left      Capture the Flag: Beginner Cybersecurity Challenge
--    full + waitlist    Resume Clinic with Industry Recruiters
--    draft that clashes Git and GitHub Workshop (same lab, same afternoon as Capture the Flag)
--
--  Demo accounts, all with the password Demo@12345
--    admin@taylors.edu.my        administrator, Student Development
--    organizer@taylors.edu.my    organiser, Taylor's Agents of Tech
--    music@taylors.edu.my        organiser, Taylor's Music Club
--    student@sd.taylors.edu.my   student, first on the Resume Clinic waitlist
--    student2@sd.taylors.edu.my  student, holds a seat at the Resume Clinic
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
USE taylors_events;

SET @seed_mode = 1;      -- lets events be inserted in any status and skips trigger auditing
SET @app_user_id = NULL;
SET @app_ip = NULL;
SET @now   = NOW();
SET @today = CAST(CURDATE() AS DATETIME);
SET @hour  = CAST(DATE_FORMAT(@now, '%Y-%m-%d %H:00:00') AS DATETIME);

START TRANSACTION;
SQL;

$rows = [];
foreach ($faculties as $i => $f) {
    $rows[] = sprintf('(%d, %s, %s)', $i + 1, $q($f[0]), $q($f[1]));
}
$out[] = "INSERT INTO faculties (id, code, name) VALUES\n  " . implode(",\n  ", $rows) . ';';

$rows = [];
foreach ($venues as $i => $v) {
    $rows[] = sprintf('(%d, %s, %s, %s, %s, %d, %s, %d, 1)', $i + 1, $q($v[0]), $q($v[1]), $q($v[2]), $q($v[3]), $v[4], $q($v[5]), $v[6]);
}
$out[] = "INSERT INTO venues (id, code, name, building, floor_label, capacity, venue_type, has_projector, is_accessible) VALUES\n  " . implode(",\n  ", $rows) . ';';

$rows = [];
foreach ($categories as $i => $c) {
    $rows[] = sprintf('(%d, %s, %s)', $i + 1, $q($c[0]), $q($c[1]));
}
$out[] = "INSERT INTO event_categories (id, name, slug) VALUES\n  " . implode(",\n  ", $rows) . ';';

$rows = [];
foreach ($clubs as $i => $c) {
    $rows[] = sprintf('(%d, %s, %s, %s)', $i + 1, $q($c[0]), $q($c[1]), $q($c[2]));
}
$out[] = "INSERT INTO clubs (id, name, slug, kind) VALUES\n  " . implode(",\n  ", $rows) . ';';

$out[] = '-- Every seeded account has the same Argon2id hash (password: ' . DEMO_PASSWORD . ').';
$out[] = 'SET @pw = ' . $q($hash) . ';';
foreach (array_chunk($users, 100, true) as $chunk) {
    $rows = [];
    foreach ($chunk as $id => $u) {
        $rows[] = sprintf('(%d, %s, %s, %s, %s, @pw, %s, @now - INTERVAL %d DAY)', $id, $u[0] ?? 'NULL', $q($u[1]), $q($u[2]), $q($u[3]), $q($u[4]), mt_rand(40, 400));
    }
    $out[] = "INSERT INTO users (id, faculty_id, student_number, full_name, email, password_hash, role, created_at) VALUES\n  " . implode(",\n  ", $rows) . ';';
}

$rows = [];
foreach ($presidents as $clubId => $userId) {
    $rows[] = sprintf("(%d, %d, '%s')", $clubId, $userId, $clubs[$clubId - 1][2] === 'office' ? 'advisor' : 'president');
}
foreach ($committee as $clubId => $userId) {
    $rows[] = sprintf("(%d, %d, 'committee')", $clubId, $userId);
}
foreach (array_slice($pool, 0, 60) as $i => $userId) {
    $rows[] = sprintf("(%d, %d, 'member')", 3 + $i % 12, $userId);
}
$out[] = "INSERT INTO club_members (club_id, user_id, role) VALUES\n  " . implode(",\n  ", $rows) . ';';

// Events. confirmed_count is left at 0: the registration triggers count it.
$rows = [];
foreach ($events as $i => $e) {
    $id = $i + 1;
    $start = '(' . $e[5] . ')';
    $published = in_array($e[8], ['published', 'ongoing', 'completed', 'archived'], true);
    $rows[] = sprintf(
        "(%d, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s + INTERVAL %d HOUR, %d, %s, %s, %s, %s, %s - INTERVAL %d DAY)",
        $id, $presidents[$e[2]], $e[2], $e[3], $e[4], $q($e[0]), $q($slug($e[0])), $q($e[1]), $q($descriptions[$i % 2]),
        $start, $start, $e[6], $e[7], $q($e[8]), $q($e[11] ?? null),
        $published ? (string) $ADMIN : 'NULL',
        $published ? "$start - INTERVAL 9 DAY" : 'NULL',
        $start, 14,
    );
}
$out[] = "INSERT INTO events (id, organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status, rejection_reason, approved_by, approved_at, created_at) VALUES\n  " . implode(",\n  ", $rows) . ';';

// Registrations
$regRows = [];
$ratingRows = [];
$scanRows = [];
$regId = 0;
$comments = ['Well organised and worth the time.', 'Learned more than I expected.', 'Good session, the room was a bit cold.', null,
    'Would come again.', 'The speakers were excellent.', null, 'Started late but the content was strong.', null, 'Exactly what I needed before finals.'];
$demoRegistrations = [];

foreach ($events as $i => $e) {
    $eventId = $i + 1;
    [$status, $confirmed, $waitlisted] = [$e[8], $e[9], $e[10]];
    if ($confirmed === 0) {
        continue;
    }
    $start = '(' . $e[5] . ')';
    $shuffled = $pool;
    shuffle($shuffled);
    $attendees = array_slice($shuffled, 0, $confirmed + $waitlisted);

    // Place the demo students where the scenarios need them.
    if ($eventId === 1) { $attendees[0] = $DEMO_STUDENT; }                      // seat at the event happening now, not yet checked in
    if ($eventId === 3) { $attendees[0] = $DEMO_STUDENT_2; $attendees[$confirmed] = $DEMO_STUDENT; }   // seat holder, and first in the queue
    if ($eventId === 19 || $eventId === 20) { $attendees[0] = $DEMO_STUDENT; }   // two past events attended

    foreach ($attendees as $n => $userId) {
        $regId++;
        $isWaiting = $n >= $confirmed;
        $finished = in_array($status, ['completed', 'archived'], true);
        $checkedIn = !$isWaiting && (
            ($finished && ($userId === $DEMO_STUDENT || mt_rand(1, 100) <= 82))
            || ($status === 'ongoing' && $userId !== $DEMO_STUDENT && $n <= 20)
        );
        // Bookings for upcoming events were made over the last two weeks; the waitlist is in arrival order.
        $queued = $finished || $status === 'ongoing'
            ? sprintf('%s - INTERVAL %d MINUTE', $start, mt_rand(2 * 1440, 12 * 1440))
            : ($isWaiting
                ? sprintf('@now - INTERVAL %d MINUTE', 600 - ($n - $confirmed) * 37)
                : sprintf('@now - INTERVAL %d MINUTE', mt_rand(700, 13 * 1440)));
        $checkTime = $checkedIn ? sprintf('%s + INTERVAL %d MINUTE', $start, mt_rand(-25, 20)) : 'NULL';
        $regRows[] = sprintf(
            "(%d, %d, %d, '%s', '%s', %s, %s, %s, %s, %s, %s)",
            $regId, $eventId, $userId, $isWaiting ? 'waitlisted' : 'confirmed', $hex(16), $queued, $queued,
            $checkTime, $checkedIn ? (string) $presidents[$e[2]] : 'NULL', $checkedIn ? "'" . strtoupper($hex(6)) . "'" : 'NULL',
            $queued,
        );
        if ($userId === $DEMO_STUDENT || $userId === $DEMO_STUDENT_2) {
            $demoRegistrations[$eventId][$userId] = $regId;
        }
        if ($checkedIn) {
            $scanRows[] = sprintf("(%d, %d, %d, 'admitted', '%s', '10.20.%d.%d', %s)", $eventId, $regId, $presidents[$e[2]], $hex(32), mt_rand(1, 9), mt_rand(2, 250), $checkTime);
            // The demo student has rated one past event and still owes a rating for the other.
            $rates = $finished && ($userId === $DEMO_STUDENT ? $eventId === 20 : mt_rand(1, 100) <= 45);
            if ($rates) {
                $ratingRows[] = sprintf('(%d, %d, %d, %s, %s + INTERVAL %d HOUR)', $eventId, $userId, $pick([5, 5, 5, 4, 4, 4, 4, 3, 3, 2]), $q($pick($comments)), $start, mt_rand(4, 60));
            }
        }
    }
}
foreach (array_chunk($regRows, 250) as $chunk) {
    $out[] = "INSERT INTO registrations (id, event_id, user_id, status, ticket_nonce, queued_at, created_at, checked_in_at, checked_in_by, certificate_code, updated_at) VALUES\n  " . implode(",\n  ", $chunk) . ';';
}
foreach (array_chunk($scanRows, 250) as $chunk) {
    $out[] = "INSERT INTO ticket_scans (event_id, registration_id, scanned_by, result, payload_hash, ip_address, created_at) VALUES\n  " . implode(",\n  ", $chunk) . ';';
}
$out[] = "-- Two refused scans at the event happening now, so the door log shows what a refusal looks like.\n"
    . sprintf("INSERT INTO ticket_scans (event_id, registration_id, scanned_by, result, payload_hash, ip_address, created_at) VALUES\n  (1, NULL, %d, 'invalid_signature', '%s', '10.20.3.14', @now - INTERVAL 31 MINUTE),\n  (1, 2, %d, 'duplicate', '%s', '10.20.3.14', @now - INTERVAL 22 MINUTE);", $ORGANISER, $hex(32), $ORGANISER, $hex(32));
foreach (array_chunk($ratingRows, 250) as $chunk) {
    $out[] = "INSERT INTO event_ratings (event_id, user_id, rating, comment, created_at) VALUES\n  " . implode(",\n  ", $chunk) . ';';
}

// What the demo accounts see on first sign-in.
$ticketNow = $demoRegistrations[1][$DEMO_STUDENT];
$out[] = sprintf(<<<SQL
INSERT INTO notifications (user_id, type, title, body, link_url, read_at, created_at) VALUES
  (%1\$d, 'booking_confirmed', 'Seat confirmed', 'You have a seat at Build Night: Ship a Web App in an Evening.', '/my/tickets/%2\$d', NULL, @now - INTERVAL 2 DAY),
  (%1\$d, 'booking_waitlisted', 'You are on the waitlist', 'Resume Clinic with Industry Recruiters is full. We will tell you if a seat frees up.', '/my/registrations', NULL, @now - INTERVAL 10 HOUR),
  (%1\$d, 'checked_in', 'Checked in', 'You were checked in to Introduction to Docker. Your certificate is ready when the event ends.', '/my/registrations', @now - INTERVAL 2 DAY, @now - INTERVAL 3 DAY),
  (%3\$d, 'event_rejected', 'Event sent back', 'Public Speaking Bootcamp was not approved. Reason: The description does not say who is giving the feedback.', '/organise/events/8/edit', NULL, @now - INTERVAL 1 DAY),
  (%4\$d, 'approval_needed', 'An event needs a decision', 'Machine Learning Study Jam has been submitted for approval.', '/admin/approvals', NULL, @now - INTERVAL 5 HOUR),
  (%4\$d, 'approval_needed', 'An event needs a decision', 'Acoustic Night at the Experimental Theatre has been submitted for approval.', '/admin/approvals', NULL, @now - INTERVAL 3 HOUR);
SQL, $DEMO_STUDENT, $ticketNow, $presidents[10], $ADMIN);

$out[] = sprintf(<<<SQL
INSERT INTO mail_outbox (to_email, related_user_id, template, subject, body_text, created_at) VALUES
  ('student@sd.taylors.edu.my', %1\$d, 'booking_confirmed', 'Your seat at Build Night: Ship a Web App in an Evening', 'Hello Aina Sofea Rahman,\\n\\nYour seat at Build Night: Ship a Web App in an Evening is confirmed.\\n\\nTaylor''s Events', @now - INTERVAL 2 DAY),
  ('student@sd.taylors.edu.my', %1\$d, 'booking_waitlisted', 'You are on the waitlist for Resume Clinic with Industry Recruiters', 'Hello Aina Sofea Rahman,\\n\\nResume Clinic with Industry Recruiters is full, so you are on the waitlist. If a seat frees up it goes to whoever has waited longest, and we will email you.\\n\\nTaylor''s Events', @now - INTERVAL 10 HOUR);
SQL, $DEMO_STUDENT);

// A believable history for the audit log page.
$audit = [];
foreach ($events as $i => $e) {
    $id = $i + 1;
    $start = '(' . $e[5] . ')';
    $organiser = $presidents[$e[2]];
    $audit[] = sprintf("(%d, 'event.create', 'event', %d, NULL, JSON_OBJECT('title', %s, 'status', 'draft'), '10.20.%d.%d', %s - INTERVAL 14 DAY)", $organiser, $id, $q($e[0]), mt_rand(1, 9), mt_rand(2, 250), $start);
    if ($e[8] !== 'draft' || isset($e[11])) {
        $audit[] = sprintf("(%d, 'event.pending_approval', 'event', %d, JSON_OBJECT('status', 'draft'), JSON_OBJECT('status', 'pending_approval'), '10.20.%d.%d', %s - INTERVAL 10 DAY)", $organiser, $id, mt_rand(1, 9), mt_rand(2, 250), $start);
    }
    if (isset($e[11])) {
        $audit[] = sprintf("(%d, 'event.rejected', 'event', %d, JSON_OBJECT('status', 'pending_approval'), JSON_OBJECT('status', 'draft', 'rejection_reason', %s), '10.10.1.20', @now - INTERVAL 1 DAY)", $ADMIN, $id, $q($e[11]));
    }
    if (in_array($e[8], ['published', 'ongoing', 'completed', 'archived'], true)) {
        $audit[] = sprintf("(%d, 'event.published', 'event', %d, JSON_OBJECT('status', 'pending_approval'), JSON_OBJECT('status', 'published', 'approved_by', %d), '10.10.1.20', %s - INTERVAL 9 DAY)", $ADMIN, $id, $ADMIN, $start);
    }
    if (in_array($e[8], ['ongoing', 'completed', 'archived'], true)) {
        $audit[] = sprintf("(NULL, 'event.ongoing', 'event', %d, JSON_OBJECT('status', 'published'), JSON_OBJECT('status', 'ongoing'), NULL, %s)", $id, $start);
    }
    if (in_array($e[8], ['completed', 'archived'], true)) {
        $audit[] = sprintf("(NULL, 'event.completed', 'event', %d, JSON_OBJECT('status', 'ongoing'), JSON_OBJECT('status', 'completed'), NULL, %s + INTERVAL %d HOUR)", $id, $start, $e[6]);
    }
}
$audit[] = sprintf("(%d, 'user.update', 'user', %d, JSON_OBJECT('role', 'student', 'is_active', 1), JSON_OBJECT('role', 'organizer', 'is_active', 1), '10.10.1.20', @now - INTERVAL 20 DAY)", $ADMIN, $committee[3]);
$audit[] = "(NULL, 'auth.login_failed', 'user', NULL, NULL, JSON_OBJECT('reason', 'unknown'), '203.0.113.24', @now - INTERVAL 3 HOUR)";
$audit[] = sprintf("(%d, 'auth.login', 'user', %d, NULL, NULL, '10.10.1.20', @now - INTERVAL 2 HOUR)", $ADMIN, $ADMIN);
$out[] = "-- Inserted oldest first so the log reads in order.\nINSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address, created_at)\nSELECT * FROM (VALUES\n  ROW" . implode(",\n  ROW", $audit) . "\n) AS history (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address, created_at)\nORDER BY created_at;";

$out[] = "COMMIT;\nSET @seed_mode = NULL;";

file_put_contents(dirname(__DIR__) . '/database/seed.sql', implode("\n\n", $out) . "\n");
printf("database/seed.sql written: %d users (%d students), %d events, %d registrations, %d ratings\n", count($users), count($students), count($events), $regId, count($ratingRows));

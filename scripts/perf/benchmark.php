<?php

declare(strict_types=1);

/**
 * Measures what the indexes are worth, on a large synthetic dataset.
 *
 *     php scripts/perf/benchmark.php            200,000 events, 1,000,000 registrations
 *     php scripts/perf/benchmark.php 50000      a smaller run
 *
 * Builds a separate database (taylors_events_perf) from schema.sql, fills it,
 * then runs each hot query twice with EXPLAIN ANALYZE: once as the
 * application runs it, once with the index hidden from the optimiser. The
 * times printed are the ones MySQL itself reports. The demo database is not
 * touched. Results are written up in docs/PERFORMANCE.md.
 *
 * Needs a privileged account: DB_ROOT_USER / DB_ROOT_PASS (default root / root).
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$events = max(1000, (int) ($argv[1] ?? 200000));
$registrations = $events * 5;
$users = 20000;

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db.host'), config('db.port')),
    getenv('DB_ROOT_USER') ?: 'root',
    getenv('DB_ROOT_PASS') !== false ? getenv('DB_ROOT_PASS') : 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE => false],
);

// ------------------------------------------------------------------ build
$schema = str_replace('taylors_events', 'taylors_events_perf', (string) file_get_contents(BASE_PATH . '/database/schema.sql'));
// Tables only (everything before the first DELIMITER): triggers and views are
// not what is being measured, and they would make loading a million rows far slower.
$schema = substr($schema, 0, (int) strpos($schema, 'DELIMITER $$'));
foreach (preg_split('/;\s*\n/', $schema) as $statement) {
    $statement = trim($statement);
    if ($statement !== '' && preg_match('/^(--[^\n]*\n|\s)*(SET|DROP DATABASE|CREATE DATABASE|USE|CREATE TABLE)/i', $statement) === 1) {
        $pdo->exec($statement);
    }
}
$pdo->exec('USE taylors_events_perf');
$pdo->exec('SET SESSION cte_max_recursion_depth = 5000000, unique_checks = 0, foreign_key_checks = 0');

$t = microtime(true);
$numbers = static fn (int $n): string => "WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < $n)";
$pdo->exec("INSERT INTO faculties (code, name) VALUES ('F1', 'Faculty')");
$pdo->exec("INSERT INTO event_categories (name, slug) " . $numbers(8) . " SELECT CONCAT('Category ', n), CONCAT('category-', n) FROM seq");
$pdo->exec("INSERT INTO clubs (name, slug) " . $numbers(40) . " SELECT CONCAT('Club ', n), CONCAT('club-', n) FROM seq");
$pdo->exec("INSERT INTO venues (code, name, building, capacity, venue_type) " . $numbers(60) . " SELECT CONCAT('V', n), CONCAT('Venue ', n), 'Block', 500, 'hall' FROM seq");
$pdo->exec("INSERT INTO users (faculty_id, full_name, email, password_hash) " . $numbers($users) . " SELECT 1, CONCAT('Student ', n), CONCAT('s', n, '@sd.taylors.edu.my'), 'x' FROM seq");

// Events spread over four years, 60 venues. A few percent are still to come.
$words = "ELT(1 + n % 12, 'hackathon', 'workshop', 'concert', 'tournament', 'seminar', 'career', 'volunteer', 'robotics', 'debate', 'film', 'startup', 'chess')";
$pdo->exec(
    "INSERT INTO events (organizer_id, club_id, category_id, venue_id, title, slug, summary, description, starts_at, ends_at, capacity, status) " . $numbers($events) . "
     SELECT 1 + n % 50, 1 + n % 40, 1 + n % 8, 1 + (n * 7) % 60,
            CONCAT('Event ', n, ' ', $words), CONCAT('event-', n), CONCAT('A ', $words, ' for students'), CONCAT('Description of the ', $words, ' number ', n),
            NOW() - INTERVAL 1400 DAY + INTERVAL (n * 1500 * 1440 DIV $events) MINUTE,
            NOW() - INTERVAL 1400 DAY + INTERVAL (n * 1500 * 1440 DIV $events) + 120 MINUTE,
            100,
            CASE WHEN n * 1500 DIV $events < 1400 THEN ELT(1 + n % 10, 'completed','completed','completed','completed','archived','archived','archived','archived','archived','draft')
                 ELSE ELT(1 + n % 5, 'published','published','published','pending_approval','draft') END
       FROM seq",
);
$pdo->exec(
    "INSERT INTO registrations (event_id, user_id, status, ticket_nonce, queued_at) " . $numbers($registrations) . "
     SELECT 1 + (n - 1) DIV 5, 1 + (n * 7919) % $users + 0 * n, ELT(1 + n % 10, 'confirmed','confirmed','confirmed','confirmed','confirmed','confirmed','confirmed','waitlisted','waitlisted','cancelled'),
            LPAD(HEX(n), 32, '0'), NOW(3) - INTERVAL n SECOND
       FROM seq",
);
$pdo->query('ANALYZE TABLE events, registrations')->fetchAll();
printf("Built taylors_events_perf: %s events, %s registrations, %s users in %.0f s\n\n", number_format($events), number_format($registrations), number_format($users), microtime(true) - $t);

// ---------------------------------------------------------------- measure
/** Run EXPLAIN ANALYZE five times and return the median total time MySQL reports, in milliseconds. */
$measure = static function (string $sql) use ($pdo): float {
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $plan = (string) $pdo->query('EXPLAIN ANALYZE ' . $sql)->fetchColumn();
        preg_match('/actual time=[\d.]+\.\.([\d.]+)/', $plan, $m);
        $times[] = (float) ($m[1] ?? 0);
    }
    sort($times);
    return $times[2];
};

$venue = 8;
$queries = [
    'Venue clash check (guarantee 2)' => [
        "SELECT COUNT(*) FROM events e %s WHERE e.venue_id = $venue AND e.status IN ('pending_approval','published','ongoing') AND e.deleted_at IS NULL AND e.starts_at < NOW() + INTERVAL 30 DAY + INTERVAL 2 HOUR AND e.ends_at > NOW() + INTERVAL 30 DAY",
        'idx_events_venue_time',
    ],
    'Browse page, first 8 upcoming events' => [
        "SELECT e.id, e.title, e.starts_at FROM events e %s WHERE e.deleted_at IS NULL AND e.status IN ('published','ongoing') ORDER BY e.starts_at, e.id LIMIT 8",
        'idx_events_status_start',
    ],
    'Clock: published events that have started' => [
        "SELECT e.id FROM events e %s WHERE e.status = 'published' AND e.starts_at <= NOW() AND e.deleted_at IS NULL",
        'idx_events_status_start',
    ],
    'Next person on a waitlist' => [
        "SELECT r.id FROM registrations r %s WHERE r.event_id = " . (int) ($events / 2) . " AND r.status = 'waitlisted' ORDER BY r.queued_at, r.id LIMIT 1",
        'idx_registrations_queue, uq_registrations_event_user',
    ],
    'My registrations' => [
        "SELECT r.id, r.status FROM registrations r %s WHERE r.user_id = 4242",
        'idx_registrations_user',
    ],
];

printf("%-44s %16s %16s %10s\n", 'Query', 'with index (ms)', 'without (ms)', 'speed-up');
echo str_repeat('-', 90), "\n";
$report = [];
foreach ($queries as $name => [$sql, $index]) {
    $with = $measure(sprintf($sql, ''));
    $without = $measure(sprintf($sql, "IGNORE INDEX ($index)"));
    printf("%-44s %16.3f %16.3f %9.0fx\n", $name, $with, $without, $without / max($with, 0.001));
    $report[] = sprintf('| %s | `%s` | %.3f | %.3f | %.0fx |', $name, $index, $with, $without, $without / max($with, 0.001));
}

// A design that was measured and rejected: FULLTEXT for the search box.
$pdo->exec('ALTER TABLE events ADD FULLTEXT KEY ftx_events_search (title, summary, description)');
$open = "FROM events e WHERE e.deleted_at IS NULL AND e.status IN ('published','ongoing') AND ";
$like = $measure("SELECT e.id $open (e.title LIKE '%robotics%' OR e.summary LIKE '%robotics%' OR e.description LIKE '%robotics%')");
$fulltext = $measure("SELECT e.id $open MATCH (e.title, e.summary, e.description) AGAINST ('+robotics*' IN BOOLEAN MODE)");
printf("\nSearch for \"robotics\" among open events:  LIKE %.3f ms,  FULLTEXT %.3f ms\n", $like, $fulltext);

echo "\nMarkdown for docs/PERFORMANCE.md:\n\n| Query | Index | With (ms) | Without (ms) | Speed-up |\n|---|---|---:|---:|---:|\n", implode("\n", $report), "\n";
echo "\nPlan of the venue clash check:\n", $pdo->query('EXPLAIN ANALYZE ' . sprintf($queries['Venue clash check (guarantee 2)'][0], ''))->fetchColumn(), "\n";
printf("\nMySQL %s, PHP %s\n", $pdo->query('SELECT VERSION()')->fetchColumn(), PHP_VERSION);
if (!in_array('--keep', $argv, true)) {
    $pdo->exec('DROP DATABASE taylors_events_perf');
}

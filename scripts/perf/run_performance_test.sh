#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Index performance test. Builds a SEPARATE database (taylors_events_perf),
# loads the schema plus a large synthetic dataset, then runs EXPLAIN ANALYZE on
# the queries the application relies on, with and without their index.
# The demo database is never touched.
#
# Usage: bash scripts/perf/run_performance_test.sh [mysql args]   e.g. -uroot -p
# -----------------------------------------------------------------------------
set -euo pipefail
ARGS="$*"
DB=taylors_events_perf
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
M="mysql $ARGS"

echo "Building $DB ..."
sed "s/taylors_events/$DB/g" "$ROOT/database/schema.sql" | $M
sed "s/taylors_events/$DB/g" "$ROOT/database/seed.sql"   | $M >/dev/null

echo "Loading synthetic volume ..."
$M $DB <<'SQL'
SET @seed_mode = 1;
SET time_zone = '+08:00';
SET SESSION cte_max_recursion_depth = 100000;

-- 20,000 extra students
INSERT INTO users (faculty_id, student_number, full_name, email, password_hash, role)
WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20000)
SELECT 1 + (i % 15), CONCAT('9', LPAD(i, 6, '0')), CONCAT('Load Student ', i),
       CONCAT('9', LPAD(i, 6, '0'), '@sd.taylors.edu.my'), 'x', 'student' FROM n;

-- 3,000 extra events at 07:00, one per venue per day, half finished (past)
-- and half published (from 30 days ahead), so they never clash with the demo
-- events or with each other.
INSERT INTO events (public_id, organizer_id, club_id, category_id, venue_id, title, slug, summary,
                    description, starts_at, ends_at, capacity, status)
WITH RECURSIVE n(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < 2999)
SELECT UUID(), 3, 3, 1 + (i % 8), 1 + (FLOOR(i / 2) % 14), CONCAT('Load event ', i), CONCAT('load-event-', i),
       'Synthetic event for performance testing', 'Synthetic description text for full text search testing.',
       TIMESTAMPADD(HOUR, 7, TIMESTAMPADD(DAY, IF(i % 2 = 0, FLOOR(i / 28) + 30, -FLOOR(i / 28) - 40), CAST(CURDATE() AS DATETIME))),
       TIMESTAMPADD(MINUTE, 510, TIMESTAMPADD(DAY, IF(i % 2 = 0, FLOOR(i / 28) + 30, -FLOOR(i / 28) - 40), CAST(CURDATE() AS DATETIME))),
       40, IF(i % 2 = 0, 'published', 'completed') FROM n;

-- 200,000 registrations spread over load events and load students
INSERT IGNORE INTO registrations (event_id, user_id, status, ticket_nonce, queued_at)
WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 100000)
SELECT e.id, u.id, IF(n.i % 5 = 0, 'waitlisted', 'confirmed'), MD5(CONCAT(n.i, '-', k.k)),
       TIMESTAMPADD(SECOND, n.i, '2026-09-01 00:00:00')
  FROM n
  JOIN (SELECT 0 AS k UNION ALL SELECT 1) k
  JOIN (SELECT MIN(id) AS lo FROM events WHERE slug LIKE 'load-event-%') b
  JOIN events e ON e.id = b.lo + ((n.i * 7 + k.k * 13) % 3000)
  JOIN (SELECT MIN(id) AS lo FROM users WHERE email LIKE '9%@sd.taylors.edu.my') ub
  JOIN users  u ON u.id = ub.lo + ((n.i * 3 + k.k * 11) % 20000);

-- 150,000 notifications
INSERT INTO notifications (user_id, type, title, body, read_at)
WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 75000)
SELECT u.id, 'event_reminder', 'Reminder', 'Your event starts soon.', IF(n.i % 3 = 0, NULL, NOW())
  FROM n JOIN (SELECT 0 AS k UNION ALL SELECT 1) k
  JOIN (SELECT MIN(id) AS lo FROM users WHERE email LIKE '9%@sd.taylors.edu.my') ub
  JOIN users u ON u.id = ub.lo + ((n.i + k.k * 5000) % 20000);

ANALYZE TABLE users, events, registrations, notifications;
SQL

$M -N $DB -e "SELECT CONCAT('Rows: users=',(SELECT COUNT(*) FROM users),', events=',(SELECT COUNT(*) FROM events),', registrations=',(SELECT COUNT(*) FROM registrations),', notifications=',(SELECT COUNT(*) FROM notifications));"

EV=$($M -N $DB -e "SELECT id FROM events WHERE slug='load-event-1500'")
US=$($M -N $DB -e "SELECT MIN(id)+777 FROM users WHERE email LIKE '9%@sd.taylors.edu.my'")

run(){ # label, sql
  echo; echo "== $1"
  $M $DB -e "EXPLAIN ANALYZE $2" | sed 's/\\n/\n/g' | grep -E "actual time|Index|scan|Filter|Limit" | head -4
}
run "Browse upcoming events (idx_events_status_start)" \
  "SELECT id,title,starts_at FROM events WHERE status='published' AND deleted_at IS NULL AND starts_at >= NOW() ORDER BY starts_at LIMIT 20"
run "Browse upcoming events WITHOUT index" \
  "SELECT id,title,starts_at FROM events IGNORE INDEX (idx_events_status_start) WHERE status='published' AND deleted_at IS NULL AND starts_at >= NOW() ORDER BY starts_at LIMIT 20"
run "Venue clash check (idx_events_venue_time)" \
  "SELECT COUNT(*) FROM events WHERE venue_id=7 AND status IN ('pending_approval','published','ongoing') AND deleted_at IS NULL AND starts_at < NOW() + INTERVAL 30 DAY AND ends_at > NOW() + INTERVAL 29 DAY"
run "Venue clash check WITHOUT index" \
  "SELECT COUNT(*) FROM events IGNORE INDEX (idx_events_venue_time, idx_events_status_start) WHERE venue_id=7 AND status IN ('pending_approval','published','ongoing') AND deleted_at IS NULL AND starts_at < NOW() + INTERVAL 30 DAY AND ends_at > NOW() + INTERVAL 29 DAY"
run "Next person on the waitlist (idx_registrations_queue)" \
  "SELECT id FROM registrations WHERE event_id=$EV AND status='waitlisted' ORDER BY queued_at, id LIMIT 1"
run "Next person on the waitlist WITHOUT index" \
  "SELECT id FROM registrations IGNORE INDEX (idx_registrations_queue, uq_registrations_event_user) WHERE event_id=$EV AND status='waitlisted' ORDER BY queued_at, id LIMIT 1"
run "My tickets (idx_registrations_user)" \
  "SELECT id FROM registrations WHERE user_id=$US AND status='confirmed'"
run "My tickets WITHOUT index" \
  "SELECT id FROM registrations IGNORE INDEX (idx_registrations_user) WHERE user_id=$US AND status='confirmed'"
run "Unread notification badge (idx_notifications_user_unread)" \
  "SELECT COUNT(*) FROM notifications WHERE user_id=$US AND read_at IS NULL"
run "Unread notification badge WITHOUT index" \
  "SELECT COUNT(*) FROM notifications IGNORE INDEX (idx_notifications_user_unread) WHERE user_id=$US AND read_at IS NULL"

echo; echo "Done. Drop the test database with:  mysql $ARGS -e 'DROP DATABASE $DB'"

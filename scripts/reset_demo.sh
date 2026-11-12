#!/usr/bin/env bash
# Put the demo data back exactly as it was, with every date recalculated from now:
#
#     bash scripts/reset_demo.sh
#
# Run it before a demonstration, or after testing. Takes a few seconds.
set -euo pipefail
cd "$(dirname "$0")/.."

DB_HOST="${DB_HOST:-$(grep -s '^DB_HOST=' .env | cut -d= -f2- || true)}"
DB_PORT="${DB_PORT:-$(grep -s '^DB_PORT=' .env | cut -d= -f2- || true)}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
if [ -z "${MYSQL_ROOT_PASSWORD+x}" ]; then
  read -r -s -p "MySQL password for $ROOT_USER (leave empty if none): " MYSQL_ROOT_PASSWORD; echo
fi
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
MYSQL=(mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"$ROOT_USER")

"${MYSQL[@]}" < database/schema.sql
"${MYSQL[@]}" < database/seed.sql
rm -f storage/cache/lifecycle.clock

"${MYSQL[@]}" -t taylors_events -e "
  SELECT 'happening now' AS scenario, title FROM events WHERE status = 'ongoing'
  UNION ALL SELECT 'one seat left', title FROM events WHERE status = 'published' AND capacity - confirmed_count = 1
  UNION ALL SELECT 'full, with a waitlist', title FROM events e WHERE status = 'published' AND capacity = confirmed_count
            AND EXISTS (SELECT 1 FROM registrations r WHERE r.event_id = e.id AND r.status = 'waitlisted')
  UNION ALL SELECT 'draft that clashes', d.title FROM events d JOIN events b ON b.venue_id = d.venue_id AND b.id <> d.id
            WHERE d.status = 'draft' AND b.status IN ('pending_approval','published','ongoing')
              AND d.starts_at < b.ends_at AND d.ends_at > b.starts_at;"
echo "Demo data reloaded."

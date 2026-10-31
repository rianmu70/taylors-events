#!/usr/bin/env bash
# Reload a fresh demo database. Run before every rehearsal and on demo day:
# seed dates are relative, so "happening now" and "upcoming" stay correct.
#
#   Local MySQL:   bash scripts/reset_demo.sh -uroot -p
#   Docker:        bash scripts/reset_demo.sh --docker
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [ "${1:-}" = "--docker" ]; then
  docker compose -f "$ROOT/docker-compose.yml" exec -T db sh -c \
    'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/01-schema.sql \
     && mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/02-seed.sql \
     && mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/03-app-user.sql'
else
  mysql "$@" < "$ROOT/database/schema.sql"
  mysql "$@" < "$ROOT/database/seed.sql"
fi
echo "Demo database reset."

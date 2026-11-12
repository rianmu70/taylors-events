#!/usr/bin/env bash
# First-time setup. Run once from the project folder:
#
#     bash scripts/setup.sh
#
# It creates .env with fresh secrets, loads the schema and the demo data, and
# creates the least privileged database account the application runs as.
# It asks for the MySQL root password once (or reads MYSQL_ROOT_PASSWORD).
set -euo pipefail
cd "$(dirname "$0")/.."

command -v php   >/dev/null || { echo "PHP 8.3 is not installed or not on PATH."; exit 1; }
command -v mysql >/dev/null || { echo "The mysql client is not installed or not on PATH."; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("pdo_mysql") ? 0 : 1);' \
  || { echo "PHP 8.3 or newer with the pdo_mysql extension is required. Found PHP $(php -r 'echo PHP_VERSION;')."; exit 1; }

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
if [ -z "${MYSQL_ROOT_PASSWORD+x}" ]; then
  read -r -s -p "MySQL password for $ROOT_USER (leave empty if none): " MYSQL_ROOT_PASSWORD; echo
fi
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$ROOT_USER")

"${MYSQL[@]}" -e "SELECT 1" >/dev/null || { echo "Could not sign in to MySQL at $DB_HOST:$DB_PORT as $ROOT_USER."; exit 1; }
VERSION=$("${MYSQL[@]}" -N -e "SELECT VERSION()")
case "$VERSION" in
  *MariaDB*) echo "This server is MariaDB ($VERSION). The project needs MySQL 8: see README, 'Requirements'."; exit 1 ;;
esac

if [ ! -f .env ]; then
  APP_PASS=$(php -r 'echo bin2hex(random_bytes(16));')
  TICKET=$(php -r 'echo bin2hex(random_bytes(32));')
  sed -e "s/^DB_PASS=.*/DB_PASS=$APP_PASS/" -e "s/^TICKET_SECRET=.*/TICKET_SECRET=$TICKET/" \
      -e "s/^DB_HOST=.*/DB_HOST=$DB_HOST/" -e "s/^DB_PORT=.*/DB_PORT=$DB_PORT/" .env.example > .env
  echo "Created .env with a new ticket secret and database password."
fi
APP_PASS=$(grep '^DB_PASS=' .env | cut -d= -f2-)

echo "Loading schema and demo data into MySQL $VERSION ..."
"${MYSQL[@]}" < database/schema.sql
"${MYSQL[@]}" < database/seed.sql
"${MYSQL[@]}" < database/app_user.sql
"${MYSQL[@]}" -e "ALTER USER 'taylors_app'@'localhost' IDENTIFIED BY '$APP_PASS'; ALTER USER 'taylors_app'@'%' IDENTIFIED BY '$APP_PASS'; FLUSH PRIVILEGES;"

echo
echo "Ready. Start the application with:   bash scripts/serve.sh"
echo "Then open http://localhost:8000 and sign in as student@sd.taylors.edu.my / Demo@12345"

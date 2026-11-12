#!/usr/bin/env bash
# Run every test suite: unit, database guarantees, concurrency, HTTP.
#
#     bash scripts/test.sh                 everything
#     bash scripts/test.sh concurrency     one suite
#
# The suites reload the demo data before and after, so do not run them
# against a database you care about. Needs DB_ROOT_PASS if the MySQL root
# password is not "root".
set -euo pipefail
cd "$(dirname "$0")/.."
exec php tests/run.php "$@"

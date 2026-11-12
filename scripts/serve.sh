#!/usr/bin/env bash
# Start the application on http://localhost:8000 using PHP's built-in server.
# The web root is public/ : nothing outside that folder can be requested.
#
#     bash scripts/serve.sh            port 8000
#     bash scripts/serve.sh 8080       another port
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { echo "No .env yet. Run: bash scripts/setup.sh"; exit 1; }
PORT="${1:-8000}"
echo "Taylor's Events is running at http://localhost:$PORT  (Ctrl+C to stop)"
PHP_CLI_SERVER_WORKERS=4 exec php -S "0.0.0.0:$PORT" -t public public/index.php

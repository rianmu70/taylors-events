#!/usr/bin/env bash
# End-to-end check of the running app over HTTP, using PHP's built-in server.
#
#   bash scripts/smoke_test.sh
#
# Uses the database settings from .env (or environment variables).
# Read-only: it changes no data. Exit code 0 = all passed.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SMOKE_PORT:-8765}"
BAD_PORT=$((PORT + 1))
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
PASS=0
FAIL=0
PIDS=()

cleanup() {
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
  rm -rf "$TMP"
}
trap cleanup EXIT

start_server() { # port, extra env...
  local port="$1"; shift
  env "$@" php -S "127.0.0.1:$port" -t "$ROOT/public" "$ROOT/public/index.php" >"$TMP/server-$port.log" 2>&1 &
  PIDS+=("$!")
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$port/assets/css/app.css" && return 0
    sleep 0.1
  done
  echo "Server on port $port did not start:"; cat "$TMP/server-$port.log"; exit 1
}

# request METHOD URL -> sets STATUS, TYPE and writes the body to $TMP/body
request() {
  local method="$1" url="$2"
  local out
  out=$(curl -s --path-as-is -X "$method" -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}|%{content_type}' "$url")
  STATUS="${out%%|*}"
  TYPE="${out#*|}"
}

ok()   { PASS=$((PASS + 1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; echo "        $2"; }

expect() { # name, method, url, status, [text the body must contain], [text it must NOT contain]
  local name="$1" method="$2" url="$3" want="$4" contains="${5:-}" absent="${6:-}"
  request "$method" "$url"
  if [ "$STATUS" != "$want" ]; then
    bad "$name" "expected HTTP $want, got $STATUS"
  elif [ -n "$contains" ] && ! grep -qF -- "$contains" "$TMP/body"; then
    bad "$name" "body does not contain: $contains"
  elif [ -n "$absent" ] && grep -qF -- "$absent" "$TMP/body"; then
    bad "$name" "body must not contain: $absent"
  else
    ok "$name"
  fi
}

echo "Normal operation"
start_server "$PORT"

expect "home page lists upcoming events"          GET  "$BASE/"                  200 'class="event-card"'
expect "home page title"                          GET  "$BASE/"                  200 '<h1>Upcoming events</h1>'
expect "drafts are not listed"                    GET  "$BASE/"                  200 '' 'Data Science Study Jam'
expect "health check reports the database"        GET  "$BASE/api/health"        200 '"database":"ok"'
expect "unknown page is 404"                      GET  "$BASE/no-such-page"      404 'Page not found'
expect "unknown API route returns JSON"           GET  "$BASE/api/no-such-route" 404 '"success":false'
expect "stylesheet is served"                     GET  "$BASE/assets/css/app.css" 200 '--brand:'
expect "index.php in the URL still routes"        GET  "$BASE/index.php"         200 'class="event-card"'
expect "path traversal cannot read .env"          GET  "$BASE/../.env"           404 '' 'DB_PASS'
expect "app code is not reachable from the web"   GET  "$BASE/../app/bootstrap.php" 404 '' 'spl_autoload_register'

# CSRF protection runs before routing, so an unsigned POST never reaches the
# router. Check that first, then sign a POST to prove the router still answers
# 405 for a route that only accepts GET.
request POST "$BASE/"
if [ "$STATUS" = "419" ]; then ok "POST without a CSRF token is rejected"
else bad "POST without a CSRF token is rejected" "expected HTTP 419, got $STATUS"; fi

# A page with a form gives us a session cookie and a matching token.
curl -s -c "$TMP/jar" -o "$TMP/form" "$BASE/login" >/dev/null
CSRF="$(sed -n 's/.*name="_csrf"[^>]*value="\([^"]*\)".*/\1/p' "$TMP/form" | head -1)"
if [ -z "$CSRF" ]; then
  bad "POST to a GET page is 405 with Allow header" "no CSRF token found on /login"
else
  STATUS=$(curl -s -b "$TMP/jar" -X POST --data-urlencode "_csrf=$CSRF" \
    -D "$TMP/headers" -o "$TMP/body" -w '%{http_code}' "$BASE/")
  if [ "$STATUS" = "405" ] && grep -qi '^Allow: GET, HEAD' "$TMP/headers"; then
    ok "POST to a GET page is 405 with Allow header"
  else
    bad "POST to a GET page is 405 with Allow header" "got $STATUS"
  fi
fi

# Raw socket: curl never reads a HEAD body, so it cannot prove there is none.
exec 3<>"/dev/tcp/127.0.0.1/$PORT"
printf 'HEAD / HTTP/1.0\r\nHost: localhost\r\n\r\n' >&3
HEAD_RAW="$(cat <&3)"
exec 3<&-
if [[ "$HEAD_RAW" == "HTTP/1."*" 200 "* ]] && [[ "$HEAD_RAW" != *"<html"* ]]; then ok "HEAD returns headers only"
else bad "HEAD returns headers only" "$(printf '%s' "$HEAD_RAW" | head -c 200)"; fi

request GET "$BASE/"
case "$TYPE" in text/html*) ok "pages are sent as UTF-8 HTML" ;; *) bad "pages are sent as UTF-8 HTML" "content type: $TYPE" ;; esac

echo "Database unavailable (wrong password, debug off)"
start_server "$BAD_PORT" DB_PASS=wrong-password APP_DEBUG=false
BAD="http://127.0.0.1:$BAD_PORT"
expect "home page shows a friendly 500 page"      GET "$BAD/"           500 'Something went wrong' 'SQLSTATE'
expect "no password or SQL details leak"          GET "$BAD/"           500 '' 'wrong-password'
expect "health check reports 503"                 GET "$BAD/api/health" 503 '"database":"unavailable"' 'Access denied'

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]

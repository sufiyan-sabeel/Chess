#!/usr/bin/env bash
# bin/test.sh — full test cycle:
#   start DB if needed -> ensure database -> migrate -> start API server
#   -> run tests/run.php -> stop API server -> print summary + exit code
#
# Usage: backend/bin/test.sh

set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "${BACKEND_DIR}/bin/lib.sh"

API_HOST="127.0.0.1"
API_PORT="${TEST_PORT:-8081}"
TEST_BASE_URL="http://${API_HOST}:${API_PORT}"
SERVER_LOG="${RUN_DIR}/test-server.log"
SERVER_PID_FILE="${RUN_DIR}/test-server.pid"

cleanup() {
  if [ -f "$SERVER_PID_FILE" ]; then
    kill "$(cat "$SERVER_PID_FILE")" >/dev/null 2>&1 || true
    rm -f "$SERVER_PID_FILE"
  fi
}
trap cleanup EXIT

log "bootstrapping backend (env, database, migrations)..."
backend_up

# Stop a stale test server from a previous run, if any.
cleanup

# Tests pin the mail transport: whatever .env says, the suite must exercise the
# dev NullMailTransport (preview) and, in production mode, MAIL_NOT_CONFIGURED.
export MAIL_TRANSPORT=null
export APP_DEBUG=false

log "starting test API server on ${TEST_BASE_URL}"
APP_ENV="${TEST_APP_ENV:-development}" setsid nohup php -S "${API_HOST}:${API_PORT}" \
  -t "${BACKEND_DIR}/public" "${BACKEND_DIR}/bin/front-controller.php" \
  >"$SERVER_LOG" 2>&1 </dev/null &
echo $! > "$SERVER_PID_FILE"

for _ in $(seq 1 30); do
  if curl -fsS "${TEST_BASE_URL}/api/v1/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done
if ! curl -fsS "${TEST_BASE_URL}/api/v1/health" >/dev/null 2>&1; then
  tail -20 "$SERVER_LOG" >&2 || true
  die "test API server did not come up"
fi

set +e
TEST_BASE_URL="$TEST_BASE_URL" TEST_API_PORT="$API_PORT" php "${BACKEND_DIR}/tests/run.php"
STATUS=$?
set -e

if [ "$STATUS" -eq 0 ]; then
  log "test suite PASSED"
else
  log "test suite FAILED (exit ${STATUS})"
fi
exit "$STATUS"

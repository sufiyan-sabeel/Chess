#!/usr/bin/env bash
# bin/dev.sh — idempotent local development bootstrap:
#   1. ensure .env exists, private MariaDB datadir + server, database, migrations
#   2. run `php -S 127.0.0.1:8080` with public/ as docroot
#
# Usage: backend/bin/dev.sh

set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "${BACKEND_DIR}/bin/lib.sh"

HOST="${DEV_HOST:-127.0.0.1}"
PORT="${DEV_PORT:-8080}"

log "bootstrapping backend (env, database, migrations)..."
backend_up
log "backend ready — DB 127.0.0.1:3307, API http://${HOST}:${PORT}/api/v1"
log "stopping: Ctrl+C"

cd "$BACKEND_DIR"
# Keep the documented entry point in sync with the canonical front controller.
cp -f bin/front-controller.php public/index.php
exec php -S "${HOST}:${PORT}" -t public bin/front-controller.php

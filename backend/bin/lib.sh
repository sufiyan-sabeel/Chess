#!/usr/bin/env bash
# Shared helpers for bin/dev.sh and bin/test.sh: private MariaDB lifecycle.
# Source this file; set BACKEND_DIR first.

set -euo pipefail

ENV_FILE="${BACKEND_DIR}/.env"
DB_DIR="${BACKEND_DIR}/var/db"
RUN_DIR="${BACKEND_DIR}/var/run"
SOCKET="${RUN_DIR}/mysqld.sock"
PID_FILE="${RUN_DIR}/mysqld.pid"
ERR_LOG="${RUN_DIR}/mysqld.err"
# Small redo log: MariaDB defaults to 96 MB, too much for this disk.
DB_FLAGS=(--innodb-buffer-pool-size=16M --innodb-log-file-size=16M)

log() { printf '[checkmate] %s\n' "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

# Read KEY from .env (real environment wins), default when unset.
get_env() {
  local key="$1" default="${2:-}"
  local env_val="${!key-}"
  if [ -n "$env_val" ]; then
    printf '%s' "$env_val"
    return
  fi
  if [ -f "$ENV_FILE" ]; then
    local line
    line=$(grep -E "^${key}=" "$ENV_FILE" | tail -1 || true)
    if [ -n "$line" ]; then
      local val="${line#*=}"
      val="${val%\"}"
      val="${val#\"}"
      printf '%s' "$val"
      return
    fi
  fi
  printf '%s' "$default"
}

ensure_env_file() {
  if [ -f "$ENV_FILE" ]; then
    return
  fi
  [ -f "${BACKEND_DIR}/.env.example" ] || die ".env.example missing"
  local pw
  pw=$(php -r 'echo bin2hex(random_bytes(16));')
  sed "s/^DB_PASSWORD=.*/DB_PASSWORD=${pw}/" "${BACKEND_DIR}/.env.example" > "$ENV_FILE"
  chmod 600 "$ENV_FILE"
  log "created .env from .env.example (generated DB password)"
}

ensure_datadir() {
  mkdir -p "$DB_DIR" "$RUN_DIR"
  if [ -f "${DB_DIR}/mysql/user.MAD" ] || [ -d "${DB_DIR}/mysql" ]; then
    return
  fi
  log "initializing private datadir (${DB_DIR})"
  mariadb-install-db \
    --datadir="$DB_DIR" \
    --auth-root-authentication-method=normal \
    --skip-test-db \
    --innodb-buffer-pool-size=8M \
    --innodb-log-file-size=16M \
    >"${RUN_DIR}/install-db.log" 2>&1 \
    || { tail -20 "${RUN_DIR}/install-db.log" >&2; die "mariadb-install-db failed"; }
}

db_server_running() {
  [ -S "$SOCKET" ] || return 1
  mariadb --socket="$SOCKET" -uroot --connect-timeout=2 -e "SELECT 1" >/dev/null 2>&1
}

start_db_server() {
  if db_server_running; then
    return
  fi
  log "starting MariaDB on 127.0.0.1:3307 (socket ${SOCKET})"
  setsid nohup mariadbd \
    --datadir="$DB_DIR" \
    --socket="$SOCKET" \
    --port=3307 \
    --bind-address=127.0.0.1 \
    --pid-file="$PID_FILE" \
    --user=root \
    --skip-name-resolve \
    "${DB_FLAGS[@]}" \
    >"$ERR_LOG" 2>&1 </dev/null &
  local i
  for i in $(seq 1 30); do
    if db_server_running; then
      return
    fi
    sleep 1
  done
  tail -20 "$ERR_LOG" >&2 || true
  die "MariaDB did not become ready (see ${ERR_LOG})"
}

# Create the dedicated database + least-privilege user from .env (idempotent).
ensure_database() {
  local name user pass
  name=$(get_env DB_NAME checkmate)
  user=$(get_env DB_USER checkmate)
  pass=$(get_env DB_PASSWORD "")
  [ -n "$pass" ] || die "DB_PASSWORD is empty in .env"

  mariadb --socket="$SOCKET" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user}'@'127.0.0.1' IDENTIFIED BY '${pass}';
CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${name}\`.* TO '${user}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${name}\`.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

run_migrations() {
  php "${BACKEND_DIR}/bin/migrate.php"
}

# Full idempotent bootstrap: env, datadir, server, db, migrations.
backend_up() {
  ensure_env_file
  ensure_datadir
  start_db_server
  ensure_database
  run_migrations
}

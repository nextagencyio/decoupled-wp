#!/usr/bin/env bash
#
# Sidecar entrypoint — starts MariaDB in the background, then
# runs FrankenPHP. Mirror of the Drupal entrypoint adapted for
# WordPress (Bedrock layout: wp at /app/web/wp, content at /app/web/app).
#
# Responsibilities:
#   1. On first boot, initialize /data/mysql (mariadb-install-db,
#      create `wordpress` user with DB_PASSWORD from the Fly secret)
#   2. On every boot, create /data/uploads (WP wp-content/uploads)
#      if missing and chown to www-data
#   3. Start mariadbd as mysql user in the background
#   4. Wait for mariadbd to accept pings before starting WP
#   5. Trap SIGTERM so Fly's graceful shutdown also stops mariadbd
#      cleanly (so InnoDB flushes its buffer pool)
#   6. Run FrankenPHP, wait on it + mariadbd so signal traps survive

set -euo pipefail

LOG_PREFIX="[entrypoint]"
log() { echo "${LOG_PREFIX} $*"; }

DATA_DIR="/data/mysql"
UPLOADS_DIR="/data/uploads"

# -------------------------------------------------------------
# /data/mysql — MariaDB datadir (see docker/mariadb.cnf)
# -------------------------------------------------------------

if [ ! -d "${DATA_DIR}/mysql" ]; then
  log "empty MariaDB datadir at ${DATA_DIR}, initializing..."
  mkdir -p "${DATA_DIR}"
  chown -R mysql:mysql "${DATA_DIR}"

  mariadb-install-db \
    --user=mysql \
    --datadir="${DATA_DIR}" \
    --auth-root-authentication-method=normal \
    >/dev/null

  log "starting temporary mariadbd for user/db setup..."
  mariadbd --user=mysql --datadir="${DATA_DIR}" --skip-networking --socket=/tmp/mariadb-init.sock &
  TMP_PID=$!

  for i in $(seq 1 30); do
    if mariadb --socket=/tmp/mariadb-init.sock -e "SELECT 1" >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done

  if ! mariadb --socket=/tmp/mariadb-init.sock -e "SELECT 1" >/dev/null 2>&1; then
    log "ERROR: temporary mariadbd did not accept connections in 30s"
    kill "${TMP_PID}" 2>/dev/null || true
    exit 1
  fi

  log "creating wordpress database + user..."
  mariadb --socket=/tmp/mariadb-init.sock <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME:-wordpress} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER:-wordpress}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD:?DB_PASSWORD must be set by the Fly provisioner}';
CREATE USER IF NOT EXISTS '${DB_USER:-wordpress}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME:-wordpress}.* TO '${DB_USER:-wordpress}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON ${DB_NAME:-wordpress}.* TO '${DB_USER:-wordpress}'@'localhost';
FLUSH PRIVILEGES;
SQL

  log "shutting down temporary mariadbd..."
  mariadb-admin --socket=/tmp/mariadb-init.sock shutdown
  wait "${TMP_PID}" 2>/dev/null || true
  rm -f /tmp/mariadb-init.sock
  log "MariaDB initialization complete"
else
  log "MariaDB datadir already initialized"
fi

# -------------------------------------------------------------
# /data/uploads — WordPress wp-content/uploads
# -------------------------------------------------------------

mkdir -p "${UPLOADS_DIR}"
chown -R www-data:www-data "${UPLOADS_DIR}"

# -------------------------------------------------------------
# Start the real mariadbd in the background
# -------------------------------------------------------------

log "starting mariadbd (background)..."
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
gosu mysql mariadbd --user=mysql &
MARIADB_PID=$!

# Graceful shutdown: when Fly sends SIGTERM, stop mariadbd cleanly
# so InnoDB flushes its buffer pool instead of ending in a
# crash-recovery state on next boot.
shutdown() {
  log "received shutdown signal, stopping mariadbd..."
  mariadb-admin --socket=/run/mysqld/mysqld.sock shutdown 2>/dev/null || \
    kill -TERM "${MARIADB_PID}" 2>/dev/null || true
  wait "${MARIADB_PID}" 2>/dev/null || true
  log "mariadbd stopped"
  exit 0
}
trap shutdown SIGTERM SIGINT

log "waiting for mariadbd to accept connections..."
for i in $(seq 1 60); do
  if mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
    log "mariadbd accepting pings after ${i}s"
    break
  fi
  sleep 1
done

if ! mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
  log "ERROR: mariadbd never accepted pings in 60s"
  exit 1
fi

# -------------------------------------------------------------
# FrankenPHP in the background; wait so the trap survives
# -------------------------------------------------------------
log "starting frankenphp..."
/usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile &
FRANKENPHP_PID=$!

wait -n "${FRANKENPHP_PID}" "${MARIADB_PID}"
EXIT_CODE=$?
log "a child process exited (code=${EXIT_CODE}), shutting down..."
shutdown

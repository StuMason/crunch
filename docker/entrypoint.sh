#!/bin/sh
set -e
cd /app

# Generate an APP_KEY if neither env nor .env provides one.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

# SQLite: ensure the database file exists (on its persistent volume), then migrate.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/app/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_FILE")"
    [ -f "$DB_FILE" ] || touch "$DB_FILE"
fi

php artisan migrate --force --no-interaction || true
php artisan config:clear

# Background queue worker (database queue) for async jobs e.g. transcription.
# Auto-restarts if it dies; --max-jobs recycles it so the loaded Whisper model
# doesn't accumulate memory. Octane remains the main (health) process.
( while true; do
    php artisan queue:work --sleep=2 --tries=1 --timeout=600 --max-jobs=25 || true
    sleep 2
  done ) &

# Long-running Octane server (FrankenPHP). Each worker holds its own warm copy of
# the models, so the worker count is bounded (FrankenPHP otherwise defaults to one
# per CPU — 16 here — which would multiply model RAM). Tune via OCTANE_WORKERS.
# Security: Octane's FrankenPHP php_server executes any on-disk .php, so block
# execution under /storage and /uploads (web-shell hardening, injected into the
# Caddy route block before php_server). Mirrors the nginx fix on the sibling apps.
export CADDY_SERVER_EXTRA_DIRECTIVES="@denyStoragePhp path_regexp ^/(storage|uploads)/.*\.php\$
respond @denyStoragePhp 403"

exec php artisan octane:start --server=frankenphp \
    --host=0.0.0.0 --port=8000 \
    --workers="${OCTANE_WORKERS:-2}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-500}"

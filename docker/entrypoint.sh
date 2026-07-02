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

# Keep booting on a migrate failure (a transient SQLite lock must not crash-loop the app),
# but shout — a swallowed `|| true` here hid a stale schema for days (the compose volume
# used to shadow database/migrations/, so migrate said "Nothing to migrate" forever).
if ! php artisan migrate --force --no-interaction; then
    echo "!!! MIGRATE FAILED — schema may be stale and jobs may error; investigate before trusting output." >&2
fi
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
# Security: custom Caddyfile 403s PHP under /storage and /uploads (web-shell
# hardening). CADDY_SERVER_EXTRA_DIRECTIVES is reserved by Octane for Mercure,
# so we pass our own Caddyfile via --caddyfile instead.
exec php artisan octane:start --server=frankenphp \
    --caddyfile=/app/docker/Caddyfile \
    --host=0.0.0.0 --port=8000 \
    --workers="${OCTANE_WORKERS:-2}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-500}"

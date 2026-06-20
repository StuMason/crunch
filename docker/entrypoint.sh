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

# Long-running Octane server (FrankenPHP). Models load lazily and stay warm per worker.
exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000

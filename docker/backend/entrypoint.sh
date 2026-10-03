#!/bin/sh
# Shared entrypoint for the backend, queue and scheduler containers.
# Usage: backend-entrypoint <serve|queue|scheduler|any command>
set -e

cd /app
READY_MARKER=storage/framework/.krl-ready

wait_for_api() {
    # The "serve" container installs dependencies, migrates and seeds first.
    echo "Waiting for the backend container to finish setup..."
    until [ -f "$READY_MARKER" ]; do sleep 2; done
}

case "$1" in
    serve)
        rm -f "$READY_MARKER"

        [ -f .env ] || cp .env.example .env

        if [ ! -f vendor/autoload.php ]; then
            echo "Installing composer dependencies..."
            composer install --no-interaction --prefer-dist
        fi

        echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
        until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" >/dev/null 2>&1; do
            sleep 1
        done

        if ! grep -q '^APP_KEY=base64' .env; then
            php artisan key:generate --force
        fi

        php artisan migrate --force

        # Seed only a fresh database (no users yet).
        if [ "$(php artisan krl:needs-seed)" = "yes" ]; then
            php artisan db:seed --force
        fi

        touch "$READY_MARKER"
        exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
        ;;
    queue)
        wait_for_api
        exec php artisan queue:work --tries=1 --timeout=1800 --sleep=3
        ;;
    scheduler)
        wait_for_api
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac

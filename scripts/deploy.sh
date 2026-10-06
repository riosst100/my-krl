#!/usr/bin/env bash
# Production deploy on the VPS (native: nginx + php-fpm + pm2).
# Run from the repository root after the code has been updated, e.g. by
# .github/workflows/deploy.yml:  git reset --hard origin/master && bash scripts/deploy.sh
#
# Settings (environment variables, all optional):
#   PHP_BIN          PHP binary                       (default: php)
#   COMPOSER_BIN     Composer binary                  (default: composer)
#   PHP_FPM_SERVICE  systemd unit reloaded after deploy, e.g. php8.3-fpm; empty = skip
#                    (needs passwordless sudo for `systemctl reload <unit>`)
#   PM2_APP_NAME     pm2 process name of the Next.js app (default: my-krl-frontend)
#   FRONTEND_PORT    Port for `next start` on first start (default: 3000)
#   WEB_USER         Owner of backend/storage and bootstrap/cache when deploying as root
#                    (default: www-data, the php-fpm user)
set -euo pipefail

PHP_BIN=${PHP_BIN:-php}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
PHP_FPM_SERVICE=${PHP_FPM_SERVICE:-}
PM2_APP_NAME=${PM2_APP_NAME:-my-krl-frontend}
FRONTEND_PORT=${FRONTEND_PORT:-3000}
WEB_USER=${WEB_USER:-www-data}

# Deploying as root: allow Composer plugins (Laravel's package discovery needs them).
[ "$(id -u)" -eq 0 ] && export COMPOSER_ALLOW_SUPERUSER=1

ROOT=$(cd "$(dirname "$0")/.." && pwd)
log() { printf '\n==> %s\n' "$*"; }

# --- Backend (Laravel) --------------------------------------------------------
cd "$ROOT/backend"
[ -f .env ] || { echo "backend/.env is missing on the server" >&2; exit 1; }

log "Backend: composer install"
"$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

log "Backend: migrate"
"$PHP_BIN" artisan migrate --force

log "Backend: cache config/routes/views"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize

log "Backend: restart queue workers"
# Workers exit after their current job; supervisor/pm2 starts them again with the new code.
"$PHP_BIN" artisan queue:restart

if [ "$(id -u)" -eq 0 ]; then
    # Files artisan just created as root (logs, caches) must stay writable for php-fpm.
    chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
fi

if [ -n "$PHP_FPM_SERVICE" ]; then
    log "Backend: reload $PHP_FPM_SERVICE (clears OPcache)"
    if [ "$(id -u)" -eq 0 ]; then systemctl reload "$PHP_FPM_SERVICE"; else sudo -n systemctl reload "$PHP_FPM_SERVICE"; fi
fi

# --- Frontend (Next.js) -------------------------------------------------------
cd "$ROOT/frontend"
[ -f .env.local ] || [ -f .env.production ] || [ -f .env ] \
    || echo "warning: no frontend/.env.local or .env.production; NEXT_PUBLIC_API_URL must come from the environment" >&2

log "Frontend: npm ci"
npm ci --no-audit --no-fund

log "Frontend: build"
NEXT_TELEMETRY_DISABLED=1 npm run build

log "Frontend: (re)start pm2 process $PM2_APP_NAME"
if pm2 describe "$PM2_APP_NAME" >/dev/null 2>&1; then
    pm2 reload "$PM2_APP_NAME" --update-env
else
    pm2 start npm --name "$PM2_APP_NAME" -- start -- --port "$FRONTEND_PORT"
fi
pm2 save

log "Deploy finished: $(git -C "$ROOT" rev-parse --short HEAD)"

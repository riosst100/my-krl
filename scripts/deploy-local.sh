#!/usr/bin/env bash
# Local (Docker Compose) counterpart of deploy.sh: bring the dev stack up to date
# after pulling new code. Everything runs inside the containers, so the host
# needs no PHP extensions or Node. Run from anywhere: bash scripts/deploy-local.sh
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"
log() { printf '\n==> %s\n' "$*"; }
dc() { docker compose "$@"; }

log "Docker: build images and start containers"
dc up -d --build

log "Backend: wait for setup to finish"
until dc exec -T backend test -f storage/framework/.krl-ready; do sleep 2; done

log "Backend: composer install (with dev dependencies)"
dc exec -T backend composer install --no-interaction --prefer-dist

log "Backend: migrate"
dc exec -T backend php artisan migrate --force

log "Backend: clear caches"
dc exec -T backend php artisan optimize:clear

log "Backend: restart queue and scheduler"
dc restart queue scheduler

log "Frontend: npm install"
dc exec -T frontend npm install --no-audit --no-fund

log "Frontend: restart dev server"
dc restart frontend

log "Local deploy finished: $(git rev-parse --short HEAD)"
dc ps

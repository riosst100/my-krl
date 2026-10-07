# My KRL

Jadwal KRL Commuter Line Jabodetabek: Laravel REST API + Next.js (situs publik & panel admin) + PostgreSQL.

```
krl-app/
├── backend/            Laravel 13 (PHP 8.4) — REST API /api/v1, Sanctum, scheduler, queue
├── frontend/           Next.js 16 (App Router, TypeScript, Tailwind) — situs publik + /admin
├── docker/             Dockerfile & entrypoint backend, init SQL PostgreSQL
├── docker-compose.yml  postgres, backend, queue, scheduler, frontend
└── .env.example        Port & kredensial Docker Compose
```

The API is versioned (`/api/v1`) and supports both cookie sessions (browser) and bearer tokens (mobile/Flutter), so a mobile app can use the same backend later.

---

## 1. Quick start (Docker Desktop + WSL 2)

The project lives in the WSL filesystem (`~/projects/krl-app`) so bind mounts are fast. Run everything from a WSL shell:

```bash
cd ~/projects/krl-app
cp .env.example .env        # optional: change ports / UID
docker compose up -d
```

First start takes 1–2 minutes: composer/npm install, migrations, seeding and the first (mock) KCI sync.

| What          | URL                              |
| ------------- | -------------------------------- |
| Public site   | http://localhost:3000            |
| Admin panel   | http://localhost:3000/admin      |
| Laravel API   | http://localhost:8000/api/v1     |
| PostgreSQL    | `localhost:5432` (krl / secret; `DB_FORWARD_PORT`, currently 5433 because a Windows PostgreSQL uses 5432) |

> **Port 3000 already in use?** Set `FRONTEND_PORT=3010` (for example) in the root `.env` and run `docker compose up -d` again.
> CORS and Sanctum's stateful domains follow `FRONTEND_PORT` automatically.

### Custom domains: `https://my-krl.local` and `https://api.my-krl.local`

The current `.env` uses custom domains through the global Caddy on Windows (`C:\Users\rioss\caddy`, one file per project in `sites\`):

| URL | Caddy site (`sites\my-krl.caddy`) |
| --- | --- |
| https://my-krl.local (public + `/admin`) | `reverse_proxy 127.0.0.1:3020`, `tls internal` |
| https://api.my-krl.local/api/v1 | `reverse_proxy 127.0.0.1:8020`, `tls internal` |

One-time setup (PowerShell **as Administrator**):

```powershell
Add-Content C:\Windows\System32\drivers\etc\hosts "`r`n127.0.0.1 my-krl.local`r`n127.0.0.1 api.my-krl.local"
```

After editing a Caddy site: `caddy reload --config C:\Users\rioss\caddy\Caddyfile`.

How it fits together (root `.env`):

```env
FRONTEND_PORT=3020
BACKEND_PORT=8020
APP_DOMAIN=my-krl.local
FRONTEND_URL=https://my-krl.local          # CORS origin
API_URL=https://api.my-krl.local           # APP_URL + NEXT_PUBLIC_API_URL
SANCTUM_STATEFUL_DOMAINS=my-krl.local
SESSION_DOMAIN=.my-krl.local               # cookies shared by both subdomains
SESSION_SECURE_COOKIE=true                     # HTTPS only
```

Laravel trusts the proxy's `X-Forwarded-*` headers (`TRUSTED_PROXIES`, default `*`; the ports are bound to 127.0.0.1 only), so generated URLs use `https://`. Next.js allows HMR from `APP_DOMAIN` via `allowedDevOrigins`. To go back to plain localhost, remove the domain block from `.env` and run `docker compose up -d --force-recreate`.

The browser E2E test can run against the domains too: add `--add-host my-krl.local:host-gateway --add-host api.my-krl.local:host-gateway -e E2E_BASE_URL=https://my-krl.local -e E2E_IGNORE_HTTPS_ERRORS=1` (and drop `--network host`).

### Seeded accounts (local only)

| Role  | Email            | Password      |
| ----- | ---------------- | ------------- |
| Admin | `admin@krl.test` | `Admin12345`  |
| User  | `user@krl.test`  | `Password123` |

The admin comes from `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `backend/.env`. **Change them for any shared environment.** The demo user is only created when `APP_ENV=local`.

### Containers

| Service     | Purpose                                                                 |
| ----------- | ----------------------------------------------------------------------- |
| `postgres`  | PostgreSQL 17 (`krl` database + `krl_test` for automated tests)          |
| `backend`   | `php artisan serve` on :8000; runs migrations + seeding on startup       |
| `queue`     | `queue:work` — queued jobs                                               |
| `scheduler` | `schedule:work` — `schedules:carry-forward` at 00:01 (see §5)            |
| `frontend`  | `next dev` on :3000                                                     |

Redis is not used: sessions, cache, locks and the queue use the database.

---

## 2. Development commands

```bash
# Logs
docker compose logs -f backend frontend

# Artisan / Composer / npm inside containers
docker compose exec backend php artisan route:list --path=api
docker compose exec backend composer require some/package
docker compose exec frontend npm install some-package

# Backend tests (uses the separate krl_test database)
docker compose exec backend php artisan test

# Frontend type check + lint
docker compose exec frontend npx tsc --noEmit
docker compose exec frontend npx eslint src

# Copy the last known timetable to today (normally the scheduler does this at 00:01)
docker compose exec backend php artisan schedules:carry-forward

# Reset the database (drop everything, migrate, seed demo data from the mock KCI client)
docker compose exec backend php artisan migrate:fresh --seed

# Stop / remove (add -v to also delete the database volume)
docker compose down
```

Browser end-to-end test (register → login → "browser restart" → schedule search → admin → sync):

```bash
docker run --rm --network host -v "$PWD/frontend/e2e":/e2e:ro -e E2E_BASE_URL=http://localhost:3000 -e E2E_STATION=THB node:24-bookworm \
  sh -c "mkdir /pw && cd /pw && npm init -y >/dev/null && npm i playwright@1 >/dev/null && npx playwright install --with-deps chromium >/dev/null && cp /e2e/flow.mjs . && node flow.mjs"
```

### Without Docker

Requirements: PHP 8.3+ (`pdo_pgsql`, `intl`, `bcmath`), Composer, Node 20+, PostgreSQL.

```bash
# backend
cd backend && cp .env.example .env    # set DB_HOST=127.0.0.1 and the DB credentials
composer install && php artisan key:generate
php artisan migrate --seed
php artisan serve                      # :8000
php artisan queue:work                 # separate terminal
php artisan schedule:work              # separate terminal

# frontend
cd frontend && cp .env.example .env.local   # API_INTERNAL_URL=http://localhost:8000
npm install && npm run dev                   # :3000
```

---

## 3. Environment variables

### Root `.env` (Docker Compose)

| Variable                                       | Default        |
| ---------------------------------------------- | -------------- |
| `FRONTEND_PORT`, `BACKEND_PORT`, `DB_FORWARD_PORT` | 3000, 8000, 5432 |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | krl, krl, secret |
| `UID`, `GID` (container user, so files stay owned by you) | 1000, 1000 |

Compose injects the database host/credentials, `APP_URL`, `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` into the backend containers; they override `backend/.env`.

### `backend/.env` (see `backend/.env.example`)

| Variable | Meaning |
| --- | --- |
| `APP_URL`, `FRONTEND_URL` | API and Next.js origins (CORS allows exactly `FRONTEND_URL`) |
| `SANCTUM_STATEFUL_DOMAINS` | `host:port` of the frontend; requests from it get cookie sessions |
| `SESSION_LIFETIME` | Session lifetime in minutes (default `525600` = 1 year). The cookie is renewed on every visit, so users and admins stay signed in until they are idle for a year |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` | Cookie scope; set `SESSION_SECURE_COOKIE=true` behind HTTPS |
| `DB_*` | PostgreSQL connection |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Initial admin created by the seeder |
| `KCI_DRIVER` | `auto` (http if `KCI_API_URL` is set, else mock), `http`, or `mock` |
| `KCI_API_URL`, `KCI_API_KEY` | KCI base URL and bearer token — **never commit real values** |
| `KCI_STATIONS_ENDPOINT`, `KCI_SCHEDULES_ENDPOINT` | Paths relative to `KCI_API_URL` |
| `KCI_TIMEOUT`, `KCI_RETRIES` | HTTP client behaviour (demo data only) |
| `SYNC_INGEST_TOKEN` | Shared secret with krl-sync (`INGEST_TOKEN` there): guards `/api/v1/ingest/*` and authenticates the on-demand stop lookup; empty = ingest API off |
| `KCI_STOPS_PROXY_URL` | krl-sync endpoint for one train's stops (default `https://krl-sync.vercel.app/api/sync`); empty = no on-demand fetch |
| `KCI_SYNC_STATIONS` | Default stations krl-sync syncs until an admin chooses them; empty = all active stations |
| `TRUSTED_PROXIES` | Proxies whose `X-Forwarded-*` headers are trusted (default `*`) |

### `frontend/.env.local` (see `frontend/.env.example`)

| Variable | Meaning |
| --- | --- |
| `NEXT_PUBLIC_API_URL` | API origin as seen by the browser (public, no secrets) |
| `API_INTERNAL_URL` | API origin as seen by the Next.js server (`http://backend:8000` in Docker) |

`.env` files are git-ignored; only the `.env.example` files are committed.

---

## 4. Authentication

### Website users (Sanctum SPA cookies)

```
GET  /sanctum/csrf-cookie         → XSRF-TOKEN cookie
POST /api/v1/auth/register|login  (X-XSRF-TOKEN header, credentials: include)
     → Laravel session cookie (HTTP-only) + remember_web_* cookie (HTTP-only, long-lived)
GET  /api/v1/auth/me              → current user
```

- No token is ever stored in `localStorage`; the browser only holds HTTP-only cookies (the XSRF-TOKEN cookie is the CSRF double-submit token).
- Login uses "remember me" by default, so after the browser is closed the remember cookie silently restores the session — users stay signed in.
- `frontend/src/lib/api/client.ts` handles CSRF (including one automatic retry on `419`), JSON errors and broadcasts `401`s; `AuthProvider` / `useAuth()` expose `user`, `status`, `login`, `register`, `logout`.
- `401` on an authenticated call flips the UI to signed-out (expired session); network errors and `5xx` show friendly messages, never stack traces.

### Admin (separate guard)

- `/api/v1/admin/auth/login` signs in on a dedicated `admin` session guard. A website login never grants admin access, and admin login does not sign you in on the public site.
- Only users with `role = admin` can log in there; every admin request re-checks the role in the database (`EnsureUserIsAdmin`), so a demoted admin loses access immediately.
- Admin sessions use the same 1-year `SESSION_LIFETIME` (renewed on every visit); there is no separate "remember" cookie for admins.
- The Next.js `/admin/*` pages use `AdminAuthProvider` and redirect to `/admin/login` when there is no admin session; the API enforces the same rules independently.
- Roles are never accepted from the client on registration (`role` is not mass-assignable); only an admin can change another user's role, and never their own.

### Mobile clients (bearer tokens)

```http
POST /api/v1/auth/token   {"email": "...", "password": "...", "device_name": "pixel-8"}
→ 201 {"data": {"token": "1|abc...", "token_type": "Bearer"}}

GET /api/v1/auth/me       Authorization: Bearer 1|abc...
DELETE /api/v1/auth/token (revokes the current token)
```

### Security notes

- Passwords hashed with bcrypt (`hashed` cast); min. 8 chars with letters and numbers.
- Rate limits: auth endpoints 5/min per email+IP (20/min per IP), API 120/min, manual sync 3/min.
- CORS: only `FRONTEND_URL`, with credentials. CSRF protection on every stateful request.
- Production: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS, real `ADMIN_PASSWORD`, and a proper web server (nginx + php-fpm) instead of `artisan serve`.

---

## 5. KCI integration & synchronization

```
krl-sync (Vercel, separate repo riosst100/krl-sync)
   │  fetches kci.id with a browser fingerprint, paced like a person browsing
   ├─► GET  /api/v1/ingest/config        stations to sync + automatic sync on/off (admin settings)
   ├─► POST /api/v1/ingest/{start,stations,schedules,stops,progress,finish}
   └─◄ GET  ?train=5701C                 one train's stops, asked by this app on demand
        │
        ▼
PostgreSQL (stations, train_lines, schedules, train_stops, sync_logs)  ◄── all API reads
```

**This server never fetches from KCI.** Cloudflare in front of kci.id blocks the VPS, so the data comes from **krl-sync** on Vercel through the ingest API (`IngestController`, Bearer `SYNC_INGEST_TOKEN`). krl-sync runs daily (Vercel Cron, 00:30 WIB) or manually from its own page, and syncs the station list and each selected station's timetable. Each run is a `kci_schedules` row in `sync_logs` (`trigger = ingest`) with the progress krl-sync reports; the run history is in **Admin → Sinkronisasi → Riwayat**.

**Admin settings for krl-sync** (Admin → Configuration → Sync Configuration): which stations it syncs (`/admin/settings/sync-stations`, default `KCI_SYNC_STATIONS`, empty = all active stations) and whether its daily automatic sync runs (`PUT /admin/settings/ingest-auto-sync`). krl-sync reads both from `GET /ingest/config`.

**Nothing is truncated.** A sync only replaces what it sends: one station's schedules (that date and its older days), or one train's stops. Every other station and train keeps its data. To keep the last timetable valid on a new day, `schedules:carry-forward` (scheduler, 00:01, and at the start of every ingest run) copies each station's latest day — and each train's latest stops, marked `carried_forward` — to the new date when it has no data yet.

**Train stops on demand.** The daily sync does not fetch train stops (thousands of requests). When a train's detail is opened (`GET /schedules/trains/{n}/stops`) and its stops for today were not fetched yet, `TrainStopLookupService` asks krl-sync (`KCI_STOPS_PROXY_URL?train=…`), stores the answer and serves later views from the database. Until then (or when the fetch fails) the carried-forward stops are used, so "Ke stasiun" search, reachable destinations and favourite routes keep working.

**Manual import (JSON).** Admin → Sinkronisasi → Import Manual accepts the pasted response of a KCI API call (`/schedules?stationid=…`, `/train-schedule?trainid=…` or `/stations`; `POST /api/v1/admin/sync/import`), with the same parsers, validation and replace rules.

**Demo data.** `KciClient` (`MockKciClient` by default, `HttpKciClient` with `KCI_API_URL`) only feeds the seeder: a fresh local database gets stations and today's mock timetable. **Mock times are generated, not official.**

Stored as published: alphanumeric train numbers (`5198C`), routing suffixes (`KAMPUNGBANDAN VIA MRI` → "Kampung Bandan via MRI"), `route_name`, and the **per-train colour** (`schedules.color`; a line's colour is the colour most of its trains use). New stations start with `is_active = kci_enabled`; afterwards `is_active` and coordinates belong to the admin and are never overwritten.

### Data model

| Table | Notes |
| --- | --- |
| `users` | `role` (`user` / `admin`), indexed |
| `stations` | `code` (unique, KCI `sta_id`), `name`, `slug` (unique), `latitude/longitude` (nullable, admin-editable — not provided by KCI), `operational_area`, `kci_enabled`, `is_active`, `synced_at` |
| `train_lines` | KCI `ka_name` + line colour |
| `schedules` | one train at one station on one date: `train_number`, `train_line_id`, `route_name`, `destination`, `departure_time`, `destination_arrival_time`, `service_date`; unique `(station_id, service_date, train_number)`, indexes on `(station_id, service_date, departure_time)` and `(service_date, train_number)` |
| `favorite_routes` | `user_id`, `from_station_id`, `to_station_id`, `position`; unique per user/route and user/position (1–4 per user) |
| `train_stops` | every stop of a train per service date (KCI `train-schedule`); unique `(service_date, train_number, sequence)`, index `(service_date, station_id, train_number)` |
| `sync_logs` | type, status, trigger, triggered_by, source, records/stations processed, error_message, meta (failed stations), started/finished |

KCI publishes one time per stop (`time_est`) plus the arrival time at the final destination. Platform, arrival time at the stop and live status are **not** provided, so they are neither stored nor shown.

---

## 6. API reference (`/api/v1`)

All responses are JSON: `{"data": ..., "meta": {...}}`. Errors: `{"message": "...", "errors": {...}}` with 401, 403, 404, 409, 419, 422, 429 or 503.

### Auth

| Method | Path | Auth |
| --- | --- | --- |
| POST | `/auth/register` | — (name, email, password, password_confirmation) |
| POST | `/auth/login` | — (email, password, remember=true) |
| POST | `/auth/logout` | session/token |
| GET | `/auth/me` | session/token |
| POST / DELETE | `/auth/token` | mobile token issue / revoke |

### Favourite stations (signed-in user)

Favourites are an account feature: **favourite routes (departure → destination station, 1 to 4)** are chosen in a dialog on the homepage right after signing in (mandatory until the first route exists) and stored on the account. The homepage ("Rute Favorit") then shows the next 2 trains of each route that stop at the destination; routes can be changed later ("Ubah Rute Favorit"). Guests see an invitation to sign in and add their favourite routes (the homepage no longer lists "Kereta terdekat"; `GET /schedules/upcoming` is still available as an API).

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/me/favorite-routes` | ordered list of `{from, to}`; `meta.min` = 1, `meta.max` = 4 |
| PUT | `/me/favorite-routes` | `{"routes": [{"from": "THB", "to": "SUD"}]}` — 1–4 distinct routes between different active stations |
| GET | `/me/favorite-routes/departures?limit=2` | next trains per favourite route (needs train stop data) |
| GET | `/schedules/trains/{trainNumber}/stops` | public: every stop of a train (latest timetable) with station name, time and transit flag; powers "Lihat perjalanan" |

### Stations & schedules (public)

| Method | Path | Query |
| --- | --- | --- |
| GET | `/stations` | `search` |
| GET | `/stations/{code-or-slug}` | |
| GET | `/stations/{code-or-slug}/schedules` | `date` (Y-m-d, default today WIB), `to` (destination station code/slug), `direction`, `line`, `train_number`, `time_from`, `time_to` (H:i) |
| GET | `/stations/{code-or-slug}/destinations` | `date` — stations reachable from here (later stops of its trains), with train counts |
| GET | `/schedules` | same filters + `station`, `per_page` (paginated) |
| GET | `/schedules/dates` | service dates available in the database |
| GET | `/schedules/upcoming` | `limit` (1–20, default 5), optional `station` (code/slug) — soonest departures from now (WIB), across all active stations or from one departure station; rolls over to the next synced date. The guest homepage uses it, with the chosen station in the URL (`/?dari=THB`) |
| GET | `/schedules/next` | `stations=THB,SUD` (max 5), `limit` (1–5, default 2) — upcoming departures from now (WIB) per station; falls back to the next synced date when today is over |

Example:

```http
GET /api/v1/stations/BKS/schedules?date=2026-10-03&direction=cikarang&time_from=07:00
```

```json
{
  "data": [
    {
      "id": 31877,
      "train_number": "5040",
      "line": { "id": 2, "name": "COMMUTER LINE CIKARANG", "color": "#0084D8" },
      "color": "#0084D8",
      "route_name": "KAMPUNGBANDAN-CIKARANG",
      "destination": "Cikarang",
      "departure_time": "07:04",
      "destination_arrival_time": "07:25",
      "service_date": "2026-10-03"
    }
  ],
  "meta": {
    "station": { "code": "BKS", "name": "Bekasi", "slug": "bekasi" },
    "date": "2026-10-03",
    "count": 31,
    "total_for_date": 84,
    "destinations": ["Cikarang", "Kampung Bandan"],
    "lines": [{ "id": 2, "name": "COMMUTER LINE CIKARANG", "color": "#0084D8" }],
    "last_synced_at": "2026-10-03T13:34:37+07:00"
  }
}
```

### Admin (admin session required)

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/admin/auth/login`, `/admin/auth/logout` | |
| GET | `/admin/auth/me` | |
| GET | `/admin/dashboard` | totals + last sync |
| GET | `/admin/users?search=&role=` | paginated |
| GET | `/admin/users/{id}` | |
| PATCH | `/admin/users/{id}/role` | `{"role":"admin"}`; not allowed on yourself |
| GET | `/admin/stations?search=&status=active\|inactive` | paginated, today's schedule count; `meta.last_station_sync`, `meta.next_station_sync` |
| GET | `/admin/stations/{id}` | station data + `lines` (name, colour), `schedules_by_date`, `destinations_today` |
| PATCH | `/admin/stations/{id}` | `{"is_active": false}` and/or `{"latitude": -6.2, "longitude": 106.8}` |
| GET / PUT / DELETE | `/admin/settings/sync-stations` | stations krl-sync syncs (`{"stations": ["THB"]}`) / reset to `KCI_SYNC_STATIONS`; includes `auto_sync` |
| PUT | `/admin/settings/ingest-auto-sync` | `{"enabled": false}` switches krl-sync's daily automatic sync off |
| GET | `/admin/schedules?station=&date=&train_number=` | paginated, includes inactive stations |
| GET | `/admin/sync-logs?type=schedules\|stations\|trains`, `/admin/sync-logs/{id}` | history; `meta`: `in_progress`, `last_sync`, `last_successful_sync` |
| POST | `/admin/sync/import` | manual import of a pasted KCI response |

---

## 7. Frontend structure

```
frontend/src/
├── app/
│   ├── (public)/          /, /schedule, /stations, /stations/[stationCode], /login, /register, /account
│   └── admin/             /admin/login + (panel)/dashboard, users, users/[id], stations, stations/[id], schedules, sync
├── components/            ui primitives, schedule board/search, admin badges, auth forms
└── lib/
    ├── api/               client.ts (fetch, CSRF, errors), auth, stations, schedules, admin, types
    ├── auth/              AuthProvider (useAuth), AdminAuthProvider (useAdminAuth)
    ├── hooks/             useApi (loading/error/cancel), useDebounced
    └── format.ts          WIB date/time helpers
```

Components only call functions from `lib/api/*`; no `fetch` calls are scattered in components.

## 8. Roadmap hooks

Favourite stations/routes/schedules and push/delay notifications can be added as new tables related to `users` and `stations`/`schedules`, exposed under `/api/v1/me/...` with `auth:sanctum` — the same endpoints will serve the Flutter app via bearer tokens.

**No date picker.** The public site has no date selection: the API serves the latest synced service date.

---

## 9. CI/CD (GitHub Actions → VPS)

Every push to `master` runs [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml): backend tests (PostgreSQL service) and frontend `tsc` + `eslint` + `next build`. Only if both pass does it SSH into the VPS, `git reset --hard origin/master` in `APP_DIR`, and run [`scripts/deploy.sh`](scripts/deploy.sh) (composer install `--no-dev`, `migrate --force`, `optimize`, `queue:restart`, optional php-fpm reload, `npm ci` + `next build`, `pm2 reload`). It can also be started by hand from the Actions tab (**Run workflow**).

**GitHub settings** (Settings → Secrets and variables → Actions; the deploy job uses the `production` environment):

| Kind | Name | Example |
| --- | --- | --- |
| Secret | `VPS_HOST` | `203.0.113.10` |
| Secret | `VPS_USER` | `deploy` |
| Secret | `VPS_SSH_KEY` | private key whose public key is in the VPS user's `~/.ssh/authorized_keys` |
| Secret | `VPS_PORT` | optional, default `22` |
| Variable | `APP_DIR` | `/var/www/my-krl` (git clone of this repo) |
| Variable | `PHP_FPM_SERVICE` | optional, e.g. `php8.3-fpm` (reloaded with `sudo -n systemctl reload`) |
| Variable | `PM2_APP_NAME` | optional, default `my-krl-frontend` |
| Variable | `FRONTEND_PORT` | optional, default `3000` (used only when pm2 starts the app the first time) |

**One-time VPS setup** (as the deploy user):

```bash
git clone https://github.com/riosst100/my-krl.git /var/www/my-krl   # private repo: use a deploy key
cp backend/.env.example backend/.env          # APP_ENV=production, APP_DEBUG=false, DB_*, URLs, ADMIN_*
php backend/artisan key:generate
echo 'NEXT_PUBLIC_API_URL=https://api.example.com' >  frontend/.env.production
echo 'API_INTERNAL_URL=http://127.0.0.1:8000'      >> frontend/.env.production
bash scripts/deploy.sh                         # first deploy by hand; also starts the pm2 process
pm2 startup                                    # follow the printed command so pm2 survives reboots
```

- nginx: the API vhost points `root` at `backend/public` (php-fpm); the frontend vhost proxies to `127.0.0.1:$FRONTEND_PORT`. The php-fpm user must be able to write `backend/storage` and `backend/bootstrap/cache`.
- Queue worker: keep `php artisan queue:work --tries=1 --timeout=1800` running under supervisor or pm2; `queue:restart` makes it pick up new code.
- Scheduler: cron `* * * * * cd /var/www/my-krl/backend && php artisan schedule:run >> /dev/null 2>&1`.
- `NEXT_PUBLIC_*` values are baked in at build time, so changing `frontend/.env.production` needs a redeploy.
- `git reset --hard` discards uncommitted changes in `APP_DIR`; the git-ignored `.env` files are kept.

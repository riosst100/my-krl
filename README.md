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
| `queue`     | `queue:work` — runs "Sync KCI Data Now" jobs from the admin panel        |
| `scheduler` | `schedule:work` — `kci:sync-schedules` daily at `KCI_SYNC_TIMES` (default 00:00 and 04:00), `kci:sync-stations` monthly |
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

# Run a KCI sync now (from the CLI)
docker compose exec backend php artisan kci:sync-stations                  # station list (normally monthly)
docker compose exec backend php artisan kci:sync-schedules
docker compose exec backend php artisan kci:sync-schedules --date=2026-10-10 --days=1
docker compose exec backend php artisan kci:sync-schedules --station=THB      # one station only

# Reset the database (drop everything, migrate, seed, sync)
docker compose exec backend php artisan migrate:fresh --seed

# Stop / remove (add -v to also delete the database volume)
docker compose down
```

Browser end-to-end test (register → login → "browser restart" → schedule search → admin → sync):

```bash
docker run --rm --network host -v "$PWD/frontend/e2e":/e2e:ro -e E2E_BASE_URL=http://localhost:3000 -e E2E_STATION=THB node:24-bookworm \
  sh -c "mkdir /pw && cd /pw && npm init -y >/dev/null && npm i playwright@1 >/dev/null && npx playwright install --with-deps chromium >/dev/null && node /e2e/flow.mjs"
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
| `KCI_TIMEOUT`, `KCI_RETRIES`, `KCI_REQUEST_DELAY_MS` | HTTP client behaviour |
| `KCI_SYNC_STATIONS` | Comma-separated station codes to sync (e.g. `THB` while testing); empty = all active stations |
| `KCI_SYNC_DAYS` | Service days stored per sync (starting today) |
| `KCI_RETENTION_DAYS` | Older schedules are pruned |
| `KCI_SYNC_TIMES` | Daily schedule sync times, comma-separated (Asia/Jakarta, default `00:00,04:00`); `KCI_SYNC_DAY_OFFSET` (default 0) = which service day they store |
| `KCI_STATION_SYNC_DAY`, `KCI_STATION_SYNC_TIME` | Monthly station sync: day of month and time (default 1st, 01:00) |
| `KCI_STATIONS_API_URL` | Default Stations API URL (`https://www.kci.id/api/krl/stations`); admins can override it in the panel |
| `KCI_STATIONS_API_TOKEN` | Optional bearer token for the Stations API URL |
| `KCI_SCHEDULES_API_URL` | Default Schedules API URL (`stationid` / `{station}` is replaced per station); admins can override it in the panel; empty = KCI client |
| `KCI_SCHEDULES_API_TOKEN` | Optional bearer token for the Schedules API URL |
| `KCI_TRAIN_STOPS_API_URL` | Default Train Stops API URL (`trainid` / `{train}` replaced per train); empty = no "Ke stasiun" search |
| `KCI_TRAIN_STOPS_API_TOKEN` | Optional bearer token for the Train Stops API URL |
| `KCI_TRAIN_STOPS_CONCURRENCY` | Parallel requests for train stops (default 5; the upstream needs 3–7 s per train) |
| `KCI_RATE_LIMIT_PAUSE_SECONDS` | Wait on HTTP 429 without `Retry-After`, or when `X-RateLimit-Remaining` is nearly used up (default 30) |
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
Laravel Scheduler (daily) / admin button / CLI
        │
        ▼
ScheduleSyncService ──► KciService ──► KciClient (interface)
   (upsert, logs)      (validate +       ├── HttpKciClient  (KCI_API_URL)
                        transform)       └── MockKciClient  (default, offline)
        │
        ▼
PostgreSQL (stations, train_lines, schedules, sync_logs)  ◄── all API reads
```

- Pages and API **never call KCI directly**; they read PostgreSQL.
- `HttpKciClient` expects the shape of the public KCI "krl-webs" API:
  - stations: `{"status":200,"data":[{"sta_id":"BKS","sta_name":"BEKASI","fg_enable":1}]}`
  - schedules (`?stationid=BKS&timefrom=00:00&timeto=23:59`):
    `{"status":200,"data":[{"train_id":"5012","ka_name":"COMMUTER LINE CIKARANG","route_name":"CIKARANG-KAMPUNGBANDAN","dest":"KAMPUNGBANDAN","time_est":"05:21:00","color":"#0084D8","dest_time":"06:08:00"}]}`
  - Endpoint paths, base URL and bearer token are configuration only. **Verify them against current KCI documentation/credentials before going live**; no real credentials are included.
- `MockKciClient` generates a deterministic, realistic timetable (5 lines, ~70 stations, peak/off-peak/weekend headways) in the same shape. **Its times are generated, not official.**
- To connect another source, implement `App\Services\Kci\Contracts\KciClient` and bind it in `AppServiceProvider` — nothing else changes.

Two separate syncs, each recorded in `sync_logs` with its own `type`:

| Sync | Command | Schedule | Admin button |
| --- | --- | --- | --- |
| Stations (`kci_stations`) | `kci:sync-stations` | monthly (`KCI_STATION_SYNC_DAY` at `KCI_STATION_SYNC_TIME`) | Admin → Stasiun → "Sync Stasiun Sekarang" |
| Schedules (`kci_schedules`) | `kci:sync-schedules` | daily (`KCI_SYNC_TIMES`: 00:00 and 04:00) | Admin → Sinkronisasi → "Sync KCI Data Now" |

**Stations API URL.** The station sync reads the station list from a full URL, default `https://www.kci.id/api/krl/stations` (`KCI_STATIONS_API_URL`). Admins can change it in **Admin → Stasiun → Ubah URL** (stored in the `settings` table, overrides `.env`), dry-run it with **Tes URL** (fetch + parse, nothing saved) or reset it to the default. An empty URL means "take the station list from the KCI client" (mock or `KCI_API_URL`). Optional bearer token: `KCI_STATIONS_API_TOKEN`.

The parser expects the KCI shape `{"status":200,"data":[{"sta_id","sta_name","group_wil","fg_enable"}]}` (verified against the live URL on 2026-10-03: 111 stations) and also accepts a top-level array or a list under `stations`/`result`, with alternative field names (`code`/`name`/`active`...). KCI's area header rows (`WIL0 AREA JABODETABEK`, `WIL1 AREA MERAK`, `WIL6 AREA YOGYAKARTA`) are not stations: they become operational-area names (`operational_area_name`). A failure (e.g. HTTP 403 from Cloudflare, non-JSON response) marks the sync `failed` with the reason and leaves existing stations untouched.

**Schedules API URL.** The daily schedule sync reads each station's timetable from a full URL, default `https://www.kci.id/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59` (`KCI_SCHEDULES_API_URL`). The value of `stationid` (or a `{station}` placeholder) is replaced by the code of every synced station (`KCI_SYNC_STATIONS`, currently `THB`). Admins manage it in **Admin → Sinkronisasi** (Ubah URL / Tes URL / Kembalikan ke default), next to the last sync date & time and the "Sync KCI Data Now" button. Optional bearer token: `KCI_SCHEDULES_API_TOKEN`.

- The response is the KCI shape `{"status":200,"data":[{"train_id","ka_name","route_name","dest","time_est","color","dest_time"}]}` (verified on 2026-10-03: 344 trains at Tanah Abang; a field-by-field comparison of all 344 rows against `/api/v1/stations/THB/schedules` showed no differences).
- The upstream timetable is **not dated**, so a URL-based sync stores it for **today only** (no copying onto future dates); the date picker therefore offers the days that were actually synced.
- Stored as published: alphanumeric train numbers (`5198C`), routing suffixes (`KAMPUNGBANDAN VIA MRI` → "Kampung Bandan via MRI"), `route_name`, and the **per-train colour** (`schedules.color`; a line's colour is the colour most of its trains use).
- The default URL uses `timefrom=00:00&timeto=23:59`, so the whole day (including trains after 23:00) is included.

**Train Stops API URL & "Ke stasiun".** After a URL-based schedule sync, the stops of every synced train are fetched from `https://www.kci.id/api/krl/train-schedule?trainid=5701C` (`KCI_TRAIN_STOPS_API_URL`; the `trainid` value or a `{train}` placeholder is replaced per train) and stored in `train_stops` (`service_date`, `train_number`, `sequence`, `station_code`/`station_id`, `time`, `is_transit`). Trains whose stops are already stored for that day are skipped, so re-syncs are cheap; the first sync of a day makes one request per train (~344 for Tanah Abang). KCI answers slowly (3–7 s per request), so stops are fetched in parallel batches (`KCI_TRAIN_STOPS_CONCURRENCY`, default 5) and failures are retried once. The KCI web API is rate limited (`x-ratelimit-limit: 60`): HTTP 429 responses are retried after `Retry-After` (or `KCI_RATE_LIMIT_PAUSE_SECONDS`, max 2 retries) and batches pause when `X-RateLimit-Remaining` gets low; if it still fails, the sync is logged as failed and existing data is kept. Admins manage the URL in **Admin → Sinkronisasi → Pemberhentian kereta**; an empty URL disables it. This powers the **"Ke stasiun"** picker on `/stations/{code}` and `/schedule`: only trains that stop at the chosen station *after* the departure station are listed, with the arrival time there and the number of stations.

Station sync: upserts code, name, `operational_area` (KCI `group_wil`) and `kci_enabled` (KCI `fg_enable`), sets `synced_at`, and reports new / changed / missing stations. New stations start with `is_active = kci_enabled`; afterwards `is_active` and coordinates belong to the admin and are never overwritten. Stations that disappear from KCI are reported, not deleted.

Schedule sync behaviour (`php artisan kci:sync-schedules`):

1. Takes a cache lock (no concurrent syncs) and writes a `sync_logs` row (`queued → running → success | partial | failed`).
2. Uses the stations already in the database (it only fetches the station list itself when the table is empty).
3. For every active station (or only `KCI_SYNC_STATIONS` / `--station`) and each of `KCI_SYNC_DAYS` dates: fetch, validate rows (invalid rows are skipped and logged), then upsert on `(station_id, service_date, train_number)` — no duplicates — and delete trains that disappeared from the timetable.
4. Per-station failures do not abort the run (status `partial`); an unreachable API marks the run `failed` with the error message. Existing data stays in place.
5. Prunes schedules older than `KCI_RETENTION_DAYS`.

The scheduler is defined in `backend/routes/console.php` and runs inside the `scheduler` container. Without Docker, use `php artisan schedule:work` or a cron entry `* * * * * php /path/artisan schedule:run`.

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

Favourites are an account feature: **favourite routes (departure → destination station, 1 to 4)** are chosen in a dialog on the homepage right after signing in (mandatory until the first route exists) and stored on the account. The homepage ("Rute Favorit") then shows the next 2 trains of each route that stop at the destination; routes can be changed later ("Ubah Rute Favorit"). Guests see the **soonest departures from now** across all stations (`GET /schedules/upcoming`) plus an invitation to sign up.

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
| POST | `/admin/stations/sync` | queue a station sync: `202`, or `409` if one is running |
| GET / PUT / DELETE | `/admin/settings/stations-api` | read / set (`{"url": "https://..."}`, `""` = use KCI client) / reset to default |
| POST | `/admin/settings/stations-api/test` | `{"url": "..."}` → `{ok, count, sample[], error}` without saving |
| GET / PUT / DELETE | `/admin/settings/schedules-api` | read / set (must contain `stationid=` or `{station}`; `""` = KCI client) / reset |
| GET / PUT / DELETE | `/admin/settings/train-stops-api` | read / set (must contain `trainid=` or `{train}`; `""` = disabled) / reset |
| POST | `/admin/settings/train-stops-api/test` | `{"url": "...", "train": "5701C"}` → `{ok, count, stops[], error}` without saving |
| POST | `/admin/settings/schedules-api/test` | `{"url": "...", "station": "THB"}` → `{ok, count, first, last, lines, sample[], error}` without saving |
| GET | `/admin/schedules?station=&date=&train_number=` | paginated, includes inactive stations |
| GET | `/admin/sync-logs?type=schedules\|stations`, `/admin/sync-logs/{id}` | history; `meta`: `in_progress`, `last_schedule_sync`, `last_successful_schedule_sync`, `next_schedule_sync` |
| POST | `/admin/sync` | `202` queued, `409` if a sync is already running |

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

**Fresh data, no date picker.** After a successful sync via the Schedules API URL, every schedule row (and train stop) that the run did not write is deleted, so the tables only ever hold the latest timetable. The public site has no date selection: the API always serves the latest synced service date and ignores `?date=`.

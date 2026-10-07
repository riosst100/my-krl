<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KCI Data Source
    |--------------------------------------------------------------------------
    |
    | "auto" uses the HTTP client when KCI_API_URL is configured and falls
    | back to the bundled mock data source otherwise. Force a driver with
    | "http" or "mock".
    |
    */

    'driver' => env('KCI_DRIVER', 'auto'),

    'base_url' => env('KCI_API_URL'),

    // Sent as a Bearer token. Never commit a real value.
    'api_key' => env('KCI_API_KEY'),

    'timeout' => (int) env('KCI_TIMEOUT', 20),

    'retries' => (int) env('KCI_RETRIES', 2),

    // Pause between requests (per station, and between train-stop batches) to avoid hammering the upstream API.
    'request_delay_ms' => (int) env('KCI_REQUEST_DELAY_MS', 1000),

    // After KCI blocks this server (Cloudflare 403, or 429 that did not clear), send
    // no request to KCI for this many minutes. 0 = no pause.
    'block_cooldown_minutes' => (int) env('KCI_BLOCK_COOLDOWN_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Stations API
    |--------------------------------------------------------------------------
    |
    | Full URL of the station list used by the monthly station sync. Admins can
    | override it in the panel (stored in the settings table). An empty value
    | means: take the station list from the KCI client (http or mock).
    |
    */

    'stations_api_url' => env('KCI_STATIONS_API_URL', 'https://www.kci.id/api/krl/stations'),

    // Optional bearer token for the Stations API URL. Never commit a real value.
    'stations_api_token' => env('KCI_STATIONS_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Schedules API
    |--------------------------------------------------------------------------
    |
    | Full URL of one station's timetable, used by the daily schedule sync.
    | The "stationid" query value (or a {station} placeholder) is replaced by
    | each synced station's code. Admins can override it in the panel. Empty
    | = use the KCI client (http/mock). The upstream timetable is not dated,
    | so a URL-based sync stores it for the current service day only.
    |
    */

    'schedules_api_url' => env('KCI_SCHEDULES_API_URL', 'https://www.kci.id/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A59'),

    'schedules_api_token' => env('KCI_SCHEDULES_API_TOKEN'),

    // Stops of one train (the "trainid" value or a {train} placeholder is replaced
    // per train). Synced together with URL-based schedules. Empty = no stops.
    'train_stops_api_url' => env('KCI_TRAIN_STOPS_API_URL', 'https://www.kci.id/api/krl/train-schedule?trainid=5701C'),

    'train_stops_api_token' => env('KCI_TRAIN_STOPS_API_TOKEN'),

    // Parallel requests for train stops (the upstream takes several seconds per train).
    'train_stops_concurrency' => (int) env('KCI_TRAIN_STOPS_CONCURRENCY', 2),

    // KCI allows ~60 requests per window: wait this long on HTTP 429 (without
    // Retry-After) or when the remaining quota is nearly used up.
    'rate_limit_pause_seconds' => (int) env('KCI_RATE_LIMIT_PAUSE_SECONDS', 30),

    'user_agent' => env('KCI_USER_AGENT', 'JadwalKRL/1.0'),

    // kci-fetch sidecar (docker/kci-fetch): Cloudflare blocks PHP's TLS fingerprint,
    // so KCI URL requests go through this curl_cffi service. Empty = request directly.
    'fetch_proxy_url' => rtrim((string) env('KCI_FETCH_PROXY_URL', ''), '/'),

    'endpoints' => [
        'stations' => env('KCI_STATIONS_ENDPOINT', '/krl-station'),
        'schedules' => env('KCI_SCHEDULES_ENDPOINT', '/schedule'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Synchronization
    |--------------------------------------------------------------------------
    */

    // Comma-separated station codes to sync (e.g. "THB" while testing). Empty = all active stations.
    'sync_stations' => array_values(array_filter(array_map('trim', explode(',', (string) env('KCI_SYNC_STATIONS', ''))))),

    // Number of service days (starting today) stored per sync run.
    'sync_days' => (int) env('KCI_SYNC_DAYS', 7),

    // Schedules older than this many days are pruned after each sync.
    'retention_days' => (int) env('KCI_RETENTION_DAYS', 7),

    // Timetable watcher: fingerprints KCI's current timetable to learn when KCI
    // publishes a new one (see Admin -> Sinkronisasi).
    'watch_station' => env('KCI_WATCH_STATION'),
    'watch_every_minutes' => (int) env('KCI_WATCH_EVERY_MINUTES', 15),
    'watch_retention_days' => (int) env('KCI_WATCH_RETENTION_DAYS', 30),

    // Automatic sync: comma-separated times of day (HH:MM, app timezone), e.g. "00:30,04:00".
    // Admins can change them in the panel (settings table). Empty = no automatic sync.
    'auto_sync_times' => env('KCI_AUTO_SYNC_TIMES', ''),

    // What the automatic sync runs, in this order: comma-separated "stations", "schedules", "trains".
    // Admins can change it in the panel.
    'auto_sync_types' => env('KCI_AUTO_SYNC_TYPES', 'schedules,trains'),

    // A scheduler that was down at the exact minute still starts a run this many minutes late.
    'auto_sync_grace_minutes' => (int) env('KCI_AUTO_SYNC_GRACE_MINUTES', 10),

    // Shared secret for the ingest API (krl-sync on Vercel pushes the timetable here).
    // Empty = the receiving API is switched off.
    'ingest_token' => env('SYNC_INGEST_TOKEN'),

    // A queued/running sync older than this is treated as stale (crashed worker).
    // A full sync with the polite request delays takes ~20-30 minutes.
    'stale_after_minutes' => (int) env('KCI_SYNC_STALE_MINUTES', 60),

];

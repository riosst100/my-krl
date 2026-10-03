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

    // Pause between per-station requests to avoid hammering the upstream API.
    'request_delay_ms' => (int) env('KCI_REQUEST_DELAY_MS', 200),

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

    'schedules_api_url' => env('KCI_SCHEDULES_API_URL', 'https://www.kci.id/api/krl/schedules?stationid=THB&timefrom=00%3A00&timeto=23%3A00'),

    'schedules_api_token' => env('KCI_SCHEDULES_API_TOKEN'),

    // Stops of one train (the "trainid" value or a {train} placeholder is replaced
    // per train). Synced together with URL-based schedules. Empty = no stops.
    'train_stops_api_url' => env('KCI_TRAIN_STOPS_API_URL', 'https://www.kci.id/api/krl/train-schedule?trainid=5701C'),

    'train_stops_api_token' => env('KCI_TRAIN_STOPS_API_TOKEN'),

    // Parallel requests for train stops (the upstream takes several seconds per train).
    'train_stops_concurrency' => (int) env('KCI_TRAIN_STOPS_CONCURRENCY', 5),

    // KCI allows ~60 requests per window: wait this long on HTTP 429 (without
    // Retry-After) or when the remaining quota is nearly used up.
    'rate_limit_pause_seconds' => (int) env('KCI_RATE_LIMIT_PAUSE_SECONDS', 30),

    'user_agent' => env('KCI_USER_AGENT', 'JadwalKRL/1.0'),

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

    // Daily schedule sync time (application timezone).
    'sync_time' => env('KCI_SYNC_TIME', '02:00'),

    // Monthly station sync: day of month (1-28) and time.
    'station_sync_day' => (int) env('KCI_STATION_SYNC_DAY', 1),
    'station_sync_time' => env('KCI_STATION_SYNC_TIME', '01:00'),

    // A queued/running sync older than this is treated as stale (crashed worker).
    'stale_after_minutes' => (int) env('KCI_SYNC_STALE_MINUTES', 30),

];

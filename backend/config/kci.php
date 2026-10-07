<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KCI Data Source
    |--------------------------------------------------------------------------
    |
    | This server never fetches from KCI itself: the timetable comes from
    | krl-sync on Vercel (ingest API), or from a pasted KCI response (manual
    | import). The KCI client below only provides the local demo data for the
    | seeder: "auto" uses the HTTP client when KCI_API_URL is configured and
    | falls back to the bundled mock data source otherwise.
    |
    */

    'driver' => env('KCI_DRIVER', 'auto'),

    'base_url' => env('KCI_API_URL'),

    // Sent as a Bearer token. Never commit a real value.
    'api_key' => env('KCI_API_KEY'),

    'timeout' => (int) env('KCI_TIMEOUT', 20),

    'retries' => (int) env('KCI_RETRIES', 2),

    'endpoints' => [
        'stations' => env('KCI_STATIONS_ENDPOINT', '/krl-station'),
        'schedules' => env('KCI_SCHEDULES_ENDPOINT', '/schedule'),
    ],

    /*
    |--------------------------------------------------------------------------
    | krl-sync (Vercel)
    |--------------------------------------------------------------------------
    */

    // Default station codes krl-sync syncs (GET /ingest/config), until an admin
    // chooses them in the panel. Empty = all active stations.
    'sync_stations' => array_values(array_filter(array_map('trim', explode(',', (string) env('KCI_SYNC_STATIONS', ''))))),

    // krl-sync on Vercel: fetches one train's stops from KCI on demand (when a train's
    // detail is opened and its stops for today are not stored yet). Authenticated with
    // SYNC_INGEST_TOKEN. Empty = no on-demand fetch.
    'stops_proxy_url' => env('KCI_STOPS_PROXY_URL', 'https://krl-sync.vercel.app/api/sync'),

    // Shared secret for the ingest API (krl-sync on Vercel pushes the timetable here).
    // Empty = the receiving API is switched off.
    'ingest_token' => env('SYNC_INGEST_TOKEN'),

    // A running sync older than this is treated as stale (e.g. a broken krl-sync chain),
    // so a new run can start.
    'stale_after_minutes' => (int) env('KCI_SYNC_STALE_MINUTES', 60),

];

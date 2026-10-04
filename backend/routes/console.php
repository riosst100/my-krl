<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Station master data rarely changes: refresh it once a month.
Schedule::command('kci:sync-stations --trigger=schedule')
    ->monthlyOn(config('kci.station_sync_day'), config('kci.station_sync_time'))
    ->withoutOverlapping()
    ->onOneServer();

// Midnight and early-morning runs (default 00:00 and 04:00) that store the timetable.
foreach (config('kci.sync_times') as $time) {
    Schedule::command('kci:sync-schedules --trigger=schedule --day-offset='.config('kci.sync_day_offset'))
        ->dailyAt($time)
        ->withoutOverlapping()
        ->onOneServer();
}

// Used by the Docker entrypoint to seed only a fresh database.
Artisan::command('krl:needs-seed', function () {
    $this->line(User::query()->exists() ? 'no' : 'yes');
})->purpose('Print "yes" when the database has not been seeded yet');

// Learn when KCI publishes a new timetable (content fingerprint every N minutes).
Schedule::command('kci:watch-timetable')
    ->cron('*/'.max(1, min(59, (int) config('kci.watch_every_minutes'))).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

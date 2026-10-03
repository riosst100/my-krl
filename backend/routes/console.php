<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Station master data rarely changes: refresh it once a month.
Schedule::command('kci:sync-stations --trigger=schedule')
    ->monthlyOn(config('kci.station_sync_day'), config('kci.station_sync_time'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('kci:sync-schedules --trigger=schedule')
    ->dailyAt(config('kci.sync_time'))
    ->withoutOverlapping()
    ->onOneServer();

// Used by the Docker entrypoint to seed only a fresh database.
Artisan::command('krl:needs-seed', function () {
    $this->line(User::query()->exists() ? 'no' : 'yes');
})->purpose('Print "yes" when the database has not been seeded yet');

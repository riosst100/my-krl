<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Just after midnight: stations and trains without data for the new day keep their
// last known timetable until a sync (krl-sync on Vercel) replaces it.
Schedule::command('schedules:carry-forward')
    ->dailyAt('00:01')
    ->onOneServer();

// Used by the Docker entrypoint to seed only a fresh database.
Artisan::command('krl:needs-seed', function () {
    $this->line(User::query()->exists() ? 'no' : 'yes');
})->purpose('Print "yes" when the database has not been seeded yet');

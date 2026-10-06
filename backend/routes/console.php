<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Automatic sync at the times set in Admin -> Sinkronisasi (or KCI_AUTO_SYNC_TIMES).
// The command checks every minute whether a time is due and queues the sync:
// "Sync Data to Prod" on the local machine, a direct KCI fetch on the server
// (through the kci-fetch sidecar). The manual buttons keep working as before.
Schedule::command('kci:auto-sync')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Used by the Docker entrypoint to seed only a fresh database.
Artisan::command('krl:needs-seed', function () {
    $this->line(User::query()->exists() ? 'no' : 'yes');
})->purpose('Print "yes" when the database has not been seeded yet');

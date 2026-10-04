<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// There are no scheduled KCI jobs: the production server cannot reach KCI. The
// local machine fetches the data and pushes it to production from the admin
// panel ("Sync Data to Prod"). `kci:sync-stations` / `kci:sync-schedules` stay
// available as manual commands for the local machine.

// Used by the Docker entrypoint to seed only a fresh database.
Artisan::command('krl:needs-seed', function () {
    $this->line(User::query()->exists() ? 'no' : 'yes');
})->purpose('Print "yes" when the database has not been seeded yet');

<?php

namespace Database\Seeders;

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\ScheduleSyncService;
use App\Services\StationSyncService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(StationSyncService $stationSync, ScheduleSyncService $sync): void
    {
        $this->seedAdmin();

        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'user@krl.test'],
                ['name' => 'Demo User', 'password' => 'Password123'],
            );
        }

        // Stations, then schedules, from the configured KCI source (mock by default).
        $stations = $stationSync->run($stationSync->createLog('console', status: SyncStatus::Running));
        $this->command?->info("KCI station sync: {$stations->status->value}, {$stations->records_processed} stations");

        $log = $sync->run($sync->createLog('console', status: SyncStatus::Running));
        $this->command?->info("KCI sync: {$log->status->value}, {$log->records_processed} schedules");
    }

    private function seedAdmin(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set: no admin account created.');

            return;
        }

        $admin = User::firstOrNew(['email' => strtolower($email)]);
        $admin->fill(['name' => env('ADMIN_NAME', 'Administrator'), 'password' => $password]);
        $admin->forceFill(['role' => UserRole::Admin, 'email_verified_at' => now()])->save();
    }
}

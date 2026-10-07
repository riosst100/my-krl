<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;
use App\Services\FavoriteRouteService;
use App\Services\KciService;
use App\Services\ScheduleSyncService;
use App\Services\StationSyncService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(KciService $kci, StationSyncService $stationSync, ScheduleSyncService $sync, FavoriteRouteService $favorites): void
    {
        $this->seedAdmin();

        // Demo stations and today's schedules from the KCI client (mock by default).
        // Real data comes from krl-sync on Vercel.
        $stations = $stationSync->import($kci->getStations());
        $this->command?->info("Stations: {$stations['total']}");

        $today = now();
        $records = Station::active()->get()->sum(fn (Station $station) => $sync->persist($station, $today, $kci->getStationSchedules($station->code, $today)));
        $sync->refreshLineColors();
        $this->command?->info("Schedules: {$records}");

        if (app()->environment('local')) {
            $demo = User::firstOrCreate(
                ['email' => 'user@krl.test'],
                ['name' => 'Demo User', 'password' => 'Password123'],
            );

            // Like a real user: the demo user has one favourite route.
            $codes = Station::active()->whereIn('code', ['THB', 'SUD'])->pluck('code')->all();
            if (count($codes) === 2 && $demo->favoriteRoutes()->doesntExist()) {
                $favorites->replace($demo, [['from' => 'THB', 'to' => 'SUD']]);
            }
        }
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

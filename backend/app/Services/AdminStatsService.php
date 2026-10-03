<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;

class AdminStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $today = now()->toDateString();

        return [
            'total_users' => User::count(),
            'total_admins' => User::where('role', UserRole::Admin)->count(),
            'total_stations' => Station::count(),
            'active_stations' => Station::active()->count(),
            'total_schedules' => Schedule::count(),
            'schedules_today' => Schedule::whereDate('service_date', $today)->count(),
            'last_sync' => SyncLog::with('triggeredBy')->where('type', SyncLog::TYPE_KCI_SCHEDULES)->latest('id')->first(),
            'last_station_sync' => SyncLog::with('triggeredBy')->where('type', SyncLog::TYPE_KCI_STATIONS)->latest('id')->first(),
        ];
    }
}

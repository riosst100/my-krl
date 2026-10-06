<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\AutoSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin setting: the times of day at which the sync runs automatically.
 */
class AutoSyncController extends Controller
{
    public function show(AutoSyncService $auto): JsonResponse
    {
        return $this->respond($auto);
    }

    /**
     * PUT { "times": ["00:30", "04:00"] } — an empty list switches the automatic sync off.
     */
    public function update(Request $request, AutoSyncService $auto): JsonResponse
    {
        $validated = $request->validate([
            'times' => ['present', 'array', 'max:48'],
            'times.*' => ['required', 'string', 'regex:'.AutoSyncService::TIME_PATTERN],
        ], [
            'times.*.regex' => 'Format jam harus HH:MM (00:00–23:59).',
            'times.max' => 'Maksimal 48 jadwal.',
        ]);

        Setting::put(Setting::AUTO_SYNC_TIMES, implode(',', AutoSyncService::normalize($validated['times'])), $request->user()->id);

        return $this->respond($auto);
    }

    public function reset(AutoSyncService $auto): JsonResponse
    {
        Setting::whereKey(Setting::AUTO_SYNC_TIMES)->delete();

        return $this->respond($auto);
    }

    private function respond(AutoSyncService $auto): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->find(Setting::AUTO_SYNC_TIMES);
        $last = SyncLog::where('type', SyncLog::TYPE_KCI_SCHEDULES)
            ->where('trigger', 'schedule')
            ->latest('id')
            ->first();
        $heartbeat = AutoSyncService::lastHeartbeat();

        return response()->json([
            'data' => [
                'times' => AutoSyncService::times(),
                'default_times' => AutoSyncService::normalize(explode(',', (string) config('kci.auto_sync_times'))),
                'is_default' => $setting === null,
                'timezone' => config('app.timezone'),
                'next_run_at' => $auto->nextRun()?->toIso8601String(),
                'last_run' => $last ? new SyncLogResource($last) : null,
                // The scheduler calls kci:auto-sync every minute; no recent call = scheduler not running.
                'scheduler_seen_at' => $heartbeat?->toIso8601String(),
                'scheduler_running' => $heartbeat !== null && $heartbeat->diffInMinutes(now()) < 3,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
                'updated_by' => $setting?->updatedBy?->name,
            ],
        ]);
    }
}

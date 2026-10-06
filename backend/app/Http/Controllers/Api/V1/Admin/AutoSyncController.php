<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\AutoSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin setting: the times of day at which the sync runs automatically, and
 * which kinds (stations, schedules, trains) it runs.
 */
class AutoSyncController extends Controller
{
    public function show(AutoSyncService $auto): JsonResponse
    {
        return $this->respond($auto);
    }

    /**
     * PUT { "times": ["00:30", "04:00"], "types": ["schedules", "trains"] } — an empty
     * times list switches the automatic sync off; types may be left out.
     */
    public function update(Request $request, AutoSyncService $auto): JsonResponse
    {
        $validated = $request->validate([
            'times' => ['present', 'array', 'max:48'],
            'times.*' => ['required', 'string', 'regex:'.AutoSyncService::TIME_PATTERN],
            'types' => ['sometimes', 'array', 'min:1'],
            'types.*' => ['required', 'string', Rule::in(array_keys(SyncLog::KCI_TYPES))],
        ], [
            'times.*.regex' => 'Format jam harus HH:MM (00:00–23:59).',
            'times.max' => 'Maksimal 48 jadwal.',
            'types.min' => 'Pilih minimal 1 jenis sync.',
            'types.*.in' => 'Jenis sync tidak dikenal.',
        ]);

        Setting::put(Setting::AUTO_SYNC_TIMES, implode(',', AutoSyncService::normalize($validated['times'])), $request->user()->id);

        if (isset($validated['types'])) {
            Setting::put(Setting::AUTO_SYNC_TYPES, implode(',', AutoSyncService::normalizeTypes($validated['types'])), $request->user()->id);
        }

        return $this->respond($auto);
    }

    public function reset(AutoSyncService $auto): JsonResponse
    {
        Setting::whereKey([Setting::AUTO_SYNC_TIMES, Setting::AUTO_SYNC_TYPES])->delete();

        return $this->respond($auto);
    }

    private function respond(AutoSyncService $auto): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->whereKey([Setting::AUTO_SYNC_TIMES, Setting::AUTO_SYNC_TYPES])->latest('updated_at')->first();
        $last = SyncLog::whereIn('type', SyncLog::KCI_TYPES)
            ->where('trigger', 'schedule')
            ->latest('id')
            ->first();
        $heartbeat = AutoSyncService::lastHeartbeat();

        return response()->json([
            'data' => [
                'times' => AutoSyncService::times(),
                'default_times' => AutoSyncService::normalize(explode(',', (string) config('kci.auto_sync_times'))),
                'types' => AutoSyncService::types(),
                'default_types' => AutoSyncService::normalizeTypes(explode(',', (string) config('kci.auto_sync_types'))),
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

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Station;
use App\Services\ScheduleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin settings for krl-sync on Vercel (read through GET /ingest/config):
 * which stations it syncs, and whether its daily automatic sync runs.
 */
class SyncStationsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->respond();
    }

    /**
     * PUT { "stations": ["THB", "KRI"] } — at least one active station.
     */
    public function update(Request $request): JsonResponse
    {
        $request->merge([
            'stations' => array_values(array_unique(array_map(
                fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code,
                (array) $request->input('stations', []),
            ))),
        ]);

        $validated = $request->validate([
            'stations' => ['required', 'array', 'min:1', 'max:300'],
            'stations.*' => ['required', 'string', Rule::exists('stations', 'code')->where('is_active', true)],
        ], [
            'stations.required' => 'Pilih minimal 1 stasiun.',
            'stations.min' => 'Pilih minimal 1 stasiun.',
            'stations.*.exists' => 'Stasiun tidak ditemukan atau tidak aktif.',
        ]);

        Setting::put(Setting::SYNC_STATIONS, implode(',', $validated['stations']), $request->user()->id);

        return $this->respond();
    }

    /**
     * PUT { "enabled": bool } — switches krl-sync's automatic (cron) sync on or off.
     * Manual syncs from the krl-sync page keep working.
     */
    public function autoSync(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        Setting::put(Setting::INGEST_AUTO_SYNC, $validated['enabled'] ? 'true' : 'false', $request->user()->id);

        return $this->respond();
    }

    public function reset(): JsonResponse
    {
        Setting::whereKey(Setting::SYNC_STATIONS)->delete();

        return $this->respond();
    }

    private function respond(): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->find(Setting::SYNC_STATIONS);
        $codes = ScheduleSyncService::syncStationCodes();

        return response()->json([
            'data' => [
                // Empty = every active station.
                'stations' => $codes,
                'default_stations' => array_values(config('kci.sync_stations')),
                'is_default' => $setting === null,
                'active_stations' => Station::active()->count(),
                'updated_at' => $setting?->updated_at?->toIso8601String(),
                'updated_by' => $setting?->updatedBy?->name,
                'auto_sync' => filter_var(Setting::value(Setting::INGEST_AUTO_SYNC, 'true'), FILTER_VALIDATE_BOOL),
            ],
        ]);
    }
}

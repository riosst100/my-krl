<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\StationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin setting: the URL the monthly station sync reads the station list from.
 */
class StationsApiController extends Controller
{
    public function show(StationSyncService $sync): JsonResponse
    {
        return $this->respond($sync);
    }

    /**
     * PUT { "url": "https://..." } — an empty string means "use the KCI client's
     * station list instead of a separate URL".
     */
    public function update(Request $request, StationSyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['present', 'nullable', 'string', 'max:500', 'url:http,https'],
        ]);

        Setting::put(Setting::STATIONS_API_URL, trim((string) $validated['url']), $request->user()->id);

        return $this->respond($sync);
    }

    /**
     * DELETE — back to the KCI_STATIONS_API_URL default.
     */
    public function reset(StationSyncService $sync): JsonResponse
    {
        Setting::whereKey(Setting::STATIONS_API_URL)->delete();

        return $this->respond($sync);
    }

    /**
     * POST { "url": "https://..." } — fetch and parse without saving anything.
     */
    public function test(Request $request, StationSyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:http,https'],
        ]);

        return response()->json(['data' => $sync->preview($validated['url'])]);
    }

    private function respond(StationSyncService $sync): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->find(Setting::STATIONS_API_URL);

        return response()->json([
            'data' => [
                'url' => $sync->stationsApiUrl(),
                'default_url' => (string) config('kci.stations_api_url'),
                'is_default' => $setting === null,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
                'updated_by' => $setting?->updatedBy?->name,
            ],
        ]);
    }
}

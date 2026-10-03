<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ScheduleSyncService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin setting: the URL the daily schedule sync reads timetables from.
 */
class SchedulesApiController extends Controller
{
    public function show(ScheduleSyncService $sync): JsonResponse
    {
        return $this->respond($sync);
    }

    /**
     * PUT { "url": "https://...?stationid=THB&..." } — empty string = use the KCI client.
     */
    public function update(Request $request, ScheduleSyncService $sync): JsonResponse
    {
        $validated = $request->validate(['url' => ['present', 'nullable', 'string', 'max:500', $this->urlRule()]]);

        Setting::put(Setting::SCHEDULES_API_URL, trim((string) $validated['url']), $request->user()->id);

        return $this->respond($sync);
    }

    public function reset(ScheduleSyncService $sync): JsonResponse
    {
        Setting::whereKey(Setting::SCHEDULES_API_URL)->delete();

        return $this->respond($sync);
    }

    /**
     * POST { "url": "...", "station": "THB" } — fetch + parse one station, nothing saved.
     */
    public function test(Request $request, ScheduleSyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', $this->urlRule()],
            'station' => ['sometimes', 'nullable', 'string', 'max:10', 'alpha_num'],
        ]);

        $station = strtoupper($validated['station'] ?? '') ?: $this->defaultStation();

        return response()->json(['data' => $sync->preview($validated['url'], $station)]);
    }

    /**
     * A valid http(s) URL containing "stationid=" or "{station}".
     */
    private function urlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || trim((string) $value) === '') {
                return;
            }

            $url = str_replace('{station}', 'THB', trim((string) $value));

            if (Validator::make(['u' => $url], ['u' => 'url:http,https'])->fails()) {
                $fail('The URL must be a valid http(s) URL.');
            } elseif (! preg_match('/[?&]stationid=/i', $url) && ! str_contains((string) $value, '{station}')) {
                $fail('The URL must contain a "stationid" query parameter or a {station} placeholder.');
            }
        };
    }

    private function defaultStation(): string
    {
        return strtoupper((string) (config('kci.sync_stations')[0] ?? 'THB'));
    }

    private function respond(ScheduleSyncService $sync): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->find(Setting::SCHEDULES_API_URL);

        return response()->json([
            'data' => [
                'url' => $sync->schedulesApiUrl(),
                'default_url' => (string) config('kci.schedules_api_url'),
                'is_default' => $setting === null,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
                'updated_by' => $setting?->updatedBy?->name,
                // Stations whose timetable is synced (KCI_SYNC_STATIONS); empty = all active.
                'sync_stations' => config('kci.sync_stations'),
                'test_station' => $this->defaultStation(),
            ],
        ]);
    }
}

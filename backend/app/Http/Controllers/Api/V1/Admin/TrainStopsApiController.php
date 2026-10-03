<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Setting;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\TrainStopSyncService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin setting: the URL with the stops of one train (used for "to station" search).
 */
class TrainStopsApiController extends Controller
{
    public function show(TrainStopSyncService $stops): JsonResponse
    {
        return $this->respond($stops);
    }

    public function update(Request $request, TrainStopSyncService $stops): JsonResponse
    {
        $validated = $request->validate(['url' => ['present', 'nullable', 'string', 'max:500', $this->urlRule()]]);

        Setting::put(Setting::TRAIN_STOPS_API_URL, trim((string) $validated['url']), $request->user()->id);

        return $this->respond($stops);
    }

    public function reset(TrainStopSyncService $stops): JsonResponse
    {
        Setting::whereKey(Setting::TRAIN_STOPS_API_URL)->delete();

        return $this->respond($stops);
    }

    /**
     * POST { "url": "...", "train": "5701C" } — fetch + parse one train, nothing saved.
     */
    public function test(Request $request, KciUrlClient $client, TrainStopSyncService $stops): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', $this->urlRule()],
            'train' => ['sometimes', 'nullable', 'string', 'max:20', 'alpha_num'],
        ]);

        // Default: a train that is in today's synced timetable.
        $train = strtoupper($validated['train'] ?? '')
            ?: (Schedule::whereDate('service_date', now()->toDateString())->orderBy('departure_time')->value('train_number') ?? '5701C');

        $result = ['ok' => false, 'url' => $validated['url'], 'train' => $train, 'count' => 0, 'stops' => [], 'error' => null];

        try {
            $result['url'] = TrainStopSyncService::urlForTrain($validated['url'], $train);
            $parsed = $stops->parse($client->fetch($result['url'], config('kci.train_stops_api_token')), $train);
        } catch (KciApiException $e) {
            return response()->json(['data' => [...$result, 'error' => $e->getMessage()]]);
        }

        return response()->json(['data' => [
            ...$result,
            'ok' => true,
            'count' => count($parsed),
            'stops' => array_map(fn (array $s) => [...$s, 'time' => substr($s['time'], 0, 5)], $parsed),
        ]]);
    }

    private function urlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || trim((string) $value) === '') {
                return;
            }

            $url = str_replace('{train}', '5701C', trim((string) $value));

            if (Validator::make(['u' => $url], ['u' => 'url:http,https'])->fails()) {
                $fail('The URL must be a valid http(s) URL.');
            } elseif (! preg_match('/[?&]trainid=/i', $url) && ! str_contains((string) $value, '{train}')) {
                $fail('The URL must contain a "trainid" query parameter or a {train} placeholder.');
            }
        };
    }

    private function respond(TrainStopSyncService $stops): JsonResponse
    {
        $setting = Setting::with('updatedBy:id,name')->find(Setting::TRAIN_STOPS_API_URL);

        return response()->json([
            'data' => [
                'url' => $stops->apiUrl(),
                'default_url' => (string) config('kci.train_stops_api_url'),
                'is_default' => $setting === null,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
                'updated_by' => $setting?->updatedBy?->name,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Services\Kci\Data\KciSchedule;
use App\Services\ScheduleCarryForwardService;
use App\Services\ScheduleSyncService;
use App\Services\TrainStopSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Receives the timetable pushed from outside (krl-sync on Vercel, because the
 * production server cannot reach KCI). Protected by the `ingest` middleware
 * (shared secret).
 *
 * Flow: start -> stations -> schedules (per station) -> stops (per train
 * chunk) -> finish. Nothing is truncated: `start` carries the last known
 * timetable forward to the run's date, and each push only replaces what it
 * sent, so stations or trains the run could not fetch keep their old data.
 */
class IngestController extends Controller
{
    public function __construct(
        private readonly ScheduleSyncService $schedules,
        private readonly TrainStopSyncService $trainStops,
        private readonly ScheduleCarryForwardService $carryForward,
    ) {}

    /**
     * What the sender should sync: the admin's "stations to sync" (an explicit
     * list; empty there = every active station) and whether it may sync on its
     * own schedule.
     */
    public function config(): JsonResponse
    {
        $codes = ScheduleSyncService::syncStationCodes();

        return response()->json(['data' => [
            'stations' => $codes ?: Station::active()->orderBy('code')->pluck('code')->all(),
            'auto_sync' => filter_var(Setting::value(Setting::INGEST_AUTO_SYNC, 'true'), FILTER_VALIDATE_BOOL),
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'stations' => ['sometimes', 'array'],
            'stations.*' => ['string', 'max:10'],
        ]);

        if (SyncLog::inProgress(SyncLog::TYPE_KCI_SCHEDULES)->exists()) {
            return response()->json(['message' => 'Another synchronization is already in progress.'], 409);
        }

        $log = SyncLog::create([
            'type' => SyncLog::TYPE_KCI_SCHEDULES,
            'status' => SyncStatus::Running,
            'trigger' => 'ingest',
            'source' => 'local',
            'started_at' => now(),
            'meta' => ['from' => $data['date'], 'days' => 1, 'stations' => $data['stations'] ?? 'all'],
        ]);

        // Until the run replaces them, the old schedules stay valid on the run's date.
        $carried = $this->carryForward->carryForward(CarbonImmutable::parse($data['date']));
        $log->update(['meta' => [...$log->meta, 'carried_forward' => $carried]]);

        return response()->json(['data' => ['run_id' => $log->id]], 201);
    }

    /**
     * Station master data: new stations are created, existing ones keep their
     * activation and manually entered coordinates.
     */
    public function stations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stations' => ['required', 'array', 'max:500'],
            'stations.*.code' => ['required', 'string', 'max:10'],
            'stations.*.name' => ['required', 'string', 'max:255'],
            'stations.*.slug' => ['nullable', 'string', 'max:255'],
            'stations.*.latitude' => ['nullable', 'numeric'],
            'stations.*.longitude' => ['nullable', 'numeric'],
            'stations.*.operational_area' => ['nullable', 'integer'],
            'stations.*.kci_enabled' => ['sometimes', 'boolean'],
            'stations.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $count = 0;
        foreach ($data['stations'] as $row) {
            $code = strtoupper(trim($row['code']));
            $station = Station::firstWhere('code', $code);

            $attributes = [
                'name' => $row['name'],
                'operational_area' => $row['operational_area'] ?? null,
                'kci_enabled' => $row['kci_enabled'] ?? true,
                'synced_at' => now(),
            ];

            if ($station) {
                $station->fill([
                    ...$attributes,
                    'latitude' => $station->latitude ?? ($row['latitude'] ?? null),
                    'longitude' => $station->longitude ?? ($row['longitude'] ?? null),
                ])->save();
            } else {
                Station::create([
                    ...$attributes,
                    'code' => $code,
                    'slug' => $row['slug'] ?? $this->uniqueSlug($row['name']),
                    'latitude' => $row['latitude'] ?? null,
                    'longitude' => $row['longitude'] ?? null,
                    'is_active' => $row['is_active'] ?? true,
                ]);
            }
            $count++;
        }

        return response()->json(['data' => ['stations' => $count]]);
    }

    /**
     * One station's timetable for the service date; replaces what was stored.
     */
    public function schedules(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer', Rule::exists('sync_logs', 'id')->where('trigger', 'ingest')],
            'date' => ['required', 'date_format:Y-m-d'],
            'station' => ['required', 'string', Rule::exists('stations', 'code')],
            'schedules' => ['present', 'array', 'max:2000'],
            'schedules.*.train_number' => ['required', 'string', 'max:20'],
            'schedules.*.line_name' => ['required', 'string', 'max:255'],
            'schedules.*.line_color' => ['nullable', 'string', 'max:20'],
            'schedules.*.route_name' => ['nullable', 'string', 'max:255'],
            'schedules.*.destination' => ['required', 'string', 'max:255'],
            'schedules.*.departure_time' => ['required', 'date_format:H:i:s'],
            'schedules.*.destination_arrival_time' => ['nullable', 'date_format:H:i:s'],
        ]);

        $station = Station::where('code', $data['station'])->firstOrFail();
        $rows = collect($data['schedules'])->map(fn (array $s) => new KciSchedule(
            trainNumber: $s['train_number'],
            lineName: $s['line_name'],
            lineColor: $s['line_color'] ?? null,
            routeName: $s['route_name'] ?? null,
            destination: $s['destination'],
            departureTime: $s['departure_time'],
            destinationArrivalTime: $s['destination_arrival_time'] ?? null,
        ));

        $count = $this->schedules->persist($station, CarbonImmutable::parse($data['date']), $rows);

        SyncLog::whereKey($data['run_id'])->increment('records_processed', $count);
        SyncLog::whereKey($data['run_id'])->increment('stations_processed');

        return response()->json(['data' => ['records' => $count]]);
    }

    /**
     * The stops of a batch of trains; each train's stops are replaced.
     */
    public function stops(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer', Rule::exists('sync_logs', 'id')->where('trigger', 'ingest')],
            'date' => ['required', 'date_format:Y-m-d'],
            'trains' => ['required', 'array', 'max:200'],
            'trains.*.train_number' => ['required', 'string', 'max:20'],
            'trains.*.stops' => ['required', 'array', 'min:1', 'max:200'],
            'trains.*.stops.*.station_code' => ['required', 'string', 'max:10'],
            'trains.*.stops.*.time' => ['required', 'date_format:H:i:s'],
            'trains.*.stops.*.is_transit' => ['sometimes', 'boolean'],
        ]);

        $stationIds = Station::pluck('id', 'code');
        $count = 0;

        foreach ($data['trains'] as $train) {
            $stops = array_map(fn (array $s) => [
                'station_code' => strtoupper($s['station_code']),
                'time' => $s['time'],
                'is_transit' => (bool) ($s['is_transit'] ?? false),
            ], $train['stops']);

            $count += $this->trainStops->store($data['date'], $train['train_number'], $stops, $stationIds);
        }

        return response()->json(['data' => ['stops' => $count]]);
    }

    /**
     * Ends the run. Nothing is removed: data the run did not send stays as it was.
     */
    public function finish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer', Rule::exists('sync_logs', 'id')->where('trigger', 'ingest')],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['success', 'partial', 'failed'])],
            'stations' => ['sometimes', 'array'],
            'stations.*' => ['string', 'max:10'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $log = SyncLog::findOrFail($data['run_id']);

        if ($data['status'] !== 'failed') {
            $this->schedules->refreshLineColors();
        }

        $log->fill([
            'status' => SyncStatus::from($data['status']),
            'error_message' => $data['error'] ?? null,
            'finished_at' => now(),
        ])->save();

        return response()->json(['data' => ['status' => $log->status->value]]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'stasiun';
        $slug = $base;

        for ($i = 2; Station::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}

<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Facades\DB;

/**
 * Manual import: an admin pastes the JSON of a KCI API response (when the
 * API cannot be reached from here) and it is stored exactly like a fetched one.
 * Works on any instance, local or server.
 */
class ManualImportService
{
    public function __construct(
        private readonly KciService $kci,
        private readonly ScheduleSyncService $schedules,
        private readonly StationSyncService $stations,
        private readonly TrainStopSyncService $trainStops,
    ) {}

    /**
     * One station's timetable (the /schedules?stationid=… response) for today.
     * Replaces that station's schedules; older days are dropped so that only
     * one service date is ever shown.
     *
     * @return array{records: int, message: string}
     *
     * @throws KciApiException
     */
    public function schedules(array $payload, Station $station, ?int $userId): array
    {
        $date = now();
        $rows = $this->kci->parseSchedules($payload, "schedules of {$station->code}");

        if ($rows->isEmpty()) {
            throw KciApiException::invalidResponse('the timetable is empty');
        }

        $log = SyncLog::create([
            'type' => SyncLog::TYPE_KCI_SCHEDULES,
            'status' => SyncStatus::Running,
            'trigger' => 'import',
            'triggered_by' => $userId,
            'source' => 'manual-json',
            'started_at' => now(),
            'meta' => ['from' => $date->toDateString(), 'days' => 1, 'stations' => [$station->code]],
        ]);

        $count = DB::transaction(function () use ($station, $date, $rows) {
            $count = $this->schedules->persist($station, $date, $rows);
            Schedule::whereDate('service_date', '<', $date->toDateString())->delete();
            TrainStop::whereDate('service_date', '<', $date->toDateString())->delete();
            $this->schedules->refreshLineColors();

            return $count;
        });

        $log->fill([
            'status' => SyncStatus::Success,
            'records_processed' => $count,
            'stations_processed' => 1,
            'finished_at' => now(),
        ])->save();

        return ['records' => $count, 'message' => "{$count} jadwal tersimpan untuk {$station->name} ({$station->code})."];
    }

    /**
     * The stops of one train (the /train-schedule?trainid=… response) for today.
     * The train number comes from the form or, failing that, from the rows' "train_id".
     *
     * @return array{records: int, message: string}
     *
     * @throws KciApiException
     */
    public function trainStops(array $payload, ?string $train): array
    {
        $rows = array_is_list($payload) ? $payload : ($payload['data'] ?? []);
        $train = strtoupper(trim((string) ($train ?: (is_array($rows[0] ?? null) ? ($rows[0]['train_id'] ?? '') : ''))));

        if ($train === '') {
            throw KciApiException::invalidResponse('the train number is missing: fill it in, or include "train_id" in the rows');
        }

        $stops = $this->trainStops->parse($payload, $train);
        $count = $this->trainStops->store(now()->toDateString(), $train, $stops, Station::pluck('id', 'code'));

        return ['records' => $count, 'message' => "{$count} pemberhentian tersimpan untuk KA {$train}."];
    }

    /**
     * The station list (the /stations response).
     *
     * @return array{records: int, message: string}
     *
     * @throws KciApiException
     */
    public function stations(array $payload, ?int $userId): array
    {
        $list = $this->kci->parseStationList($payload);
        $result = $this->stations->import($list['stations']);

        if ($list['areas'] !== []) {
            Setting::put(Setting::OPERATIONAL_AREAS, json_encode(
                array_replace(Setting::operationalAreas(), $list['areas']),
                JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE,
            ));
        }

        SyncLog::create([
            'type' => SyncLog::TYPE_KCI_STATIONS,
            'status' => SyncStatus::Success,
            'trigger' => 'import',
            'triggered_by' => $userId,
            'source' => 'manual-json',
            'records_processed' => $result['total'],
            'stations_processed' => $result['total'],
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => ['created' => $result['created'], 'updated' => $result['updated'], 'missing_from_kci' => $result['missing']],
        ]);

        return [
            'records' => $result['total'],
            'message' => "{$result['total']} stasiun diproses ({$result['created']} baru, {$result['updated']} berubah).",
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use App\Services\Concerns\FinishesSyncLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Sync Data to Prod" (runs on the local machine, which can reach KCI):
 *
 *   1. fetch the configured stations' timetable + train stops from KCI into the local database,
 *   2. push the stations, schedules and train stops to the production API (IngestController).
 *
 * Progress (phase, done/total, percent) is written to the sync log while it runs.
 */
class ProdPushService
{
    use FinishesSyncLog;

    /** Phases in run order; `weight` is the share of the progress bar (sums to 100). */
    private const PHASES = [
        'fetch_schedules' => ['weight' => 8, 'label' => 'Mengambil jadwal dari KCI'],
        'fetch_stops' => ['weight' => 40, 'label' => 'Mengambil pemberhentian kereta dari KCI'],
        'stations' => ['weight' => 2, 'label' => 'Mengirim data stasiun ke prod'],
        'push_schedules' => ['weight' => 15, 'label' => 'Mengirim jadwal ke prod'],
        'push_stops' => ['weight' => 35, 'label' => 'Mengirim pemberhentian kereta ke prod'],
    ];

    private const STOPS_CHUNK = 40;

    private float $lastWrite = 0;

    public function __construct(private readonly ScheduleSyncService $sync) {}

    public static function configured(): bool
    {
        return config('kci.push_url') !== '' && (string) config('kci.push_token') !== '';
    }

    public static function targetHost(): ?string
    {
        return config('kci.push_url') !== '' ? (parse_url((string) config('kci.push_url'), PHP_URL_HOST) ?: config('kci.push_url')) : null;
    }

    public function createLog(?int $userId, string $trigger = 'manual', bool $fetch = true): SyncLog
    {
        return SyncLog::create([
            'type' => SyncLog::TYPE_PROD_PUSH,
            'status' => SyncStatus::Queued,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            // sync_logs.source is varchar(20): the host goes in meta.target.
            'source' => 'prod',
            'meta' => ['target' => self::targetHost(), 'fetch' => $fetch, 'progress' => $this->progressMeta($fetch ? 'fetch_schedules' : 'stations', 0, 1, fetch: $fetch)],
        ]);
    }

    public function run(SyncLog $log): SyncLog
    {
        $log->update(['status' => SyncStatus::Running, 'started_at' => now()]);
        $runId = null;
        $date = now()->toDateString();

        try {
            $codes = ScheduleSyncService::syncStationCodes();
            $codes = $codes !== [] ? $codes : Station::active()->orderBy('code')->pluck('code')->all();
            $log->update(['meta' => [...$log->meta, 'stations' => $codes, 'from' => $date]]);

            // 1) KCI -> local database (skipped when sending what is already stored, e.g. after a manual JSON import)
            $fetch = (bool) ($log->meta['fetch'] ?? true);
            $fetchLog = null;

            if ($fetch) {
                $fetchLog = $this->sync->createLog('push', $log->triggered_by, SyncStatus::Running);
                $fetchLog = $this->sync->run($fetchLog, now(), null, $codes, function (string $phase, int $done, int $total) use ($log) {
                    $this->progress($log, $phase === 'stops' ? 'fetch_stops' : 'fetch_schedules', $done, $total);
                });

                if ($fetchLog->status === SyncStatus::Failed) {
                    return $this->finish($log, SyncStatus::Failed, 'Gagal mengambil data dari KCI: '.($fetchLog->error_message ?? 'unknown error'));
                }
            }

            $sent = Schedule::query()
                ->whereDate('service_date', $date)
                ->whereHas('station', fn ($q) => $q->whereIn('code', $codes))
                ->with(['station:id,code', 'trainLine:id,name,color'])
                ->get()
                ->groupBy(fn (Schedule $s) => $s->station->code);

            if ($sent->isEmpty()) {
                return $this->finish($log, SyncStatus::Failed, 'Tidak ada jadwal hari ini di database lokal untuk stasiun yang dipilih. Ambil dari KCI atau import JSON dulu.');
            }

            // The data is on this machine now: if sending fails, the admin can retry without fetching again.
            $log->update(['meta' => [...$log->fresh()->meta, 'data_ready' => true]]);

            // 2) local database -> production
            $http = $this->http();
            $this->pushStations($http, $log);
            $runId = (int) $http->post('/start', ['date' => $date, 'stations' => $sent->keys()->all()])->json('data.run_id');
            $log->update(['meta' => [...$log->fresh()->meta, 'run_id' => $runId]]);

            $records = $this->pushSchedules($http, $log, $runId, $date, $sent);
            [$trains, $stops] = $this->pushStops($http, $log, $runId, $date, $sent);

            $partial = $fetchLog?->status === SyncStatus::Partial;
            $status = $partial ? SyncStatus::Partial : SyncStatus::Success;
            $message = $partial ? 'Sebagian data gagal diambil dari KCI: '.$fetchLog->error_message : null;

            $http->post('/finish', ['run_id' => $runId, 'date' => $date, 'status' => $status->value, 'stations' => $sent->keys()->all(), 'error' => $message]);

            $log->fill([
                'records_processed' => $records,
                'stations_processed' => $sent->count(),
                'meta' => [
                    ...$log->fresh()->meta,
                    'fetch_log_id' => $fetchLog?->id,
                    'trains' => $trains,
                    'stops' => $stops,
                    'progress' => $this->progressMeta('push_stops', 1, 1, complete: true),
                ],
            ]);

            return $this->finish($log, $status, $message);
        } catch (Throwable $e) {
            Log::error('Prod push failed', ['log_id' => $log->id, 'exception' => $e]);

            if ($runId) {
                try {
                    $this->http()->post('/finish', ['run_id' => $runId, 'date' => $date, 'status' => 'failed', 'error' => 'Push dihentikan dari lokal.']);
                } catch (Throwable) {
                    // best effort: the stale run expires on its own
                }
            }

            return $this->finish($log, SyncStatus::Failed, $this->describe($e));
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('kci.push_url').'/api/v1/ingest')
            ->withToken((string) config('kci.push_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(120)
            ->retry(3, 2000)
            ->throw();
    }

    private function pushStations(PendingRequest $http, SyncLog $log): void
    {
        $this->progress($log, 'stations', 0, 1);

        $rows = Station::orderBy('code')->get()->map(fn (Station $s) => [
            'code' => $s->code,
            'name' => $s->name,
            'slug' => $s->slug,
            'latitude' => $s->latitude,
            'longitude' => $s->longitude,
            'operational_area' => $s->operational_area,
            'kci_enabled' => $s->kci_enabled,
            'is_active' => $s->is_active,
        ]);

        foreach ($rows->chunk(200) as $chunk) {
            $http->post('/stations', ['stations' => $chunk->values()->all()]);
        }

        $this->progress($log, 'stations', 1, 1);
    }

    /**
     * @param  Collection<string, Collection<int, Schedule>>  $sent
     */
    private function pushSchedules(PendingRequest $http, SyncLog $log, int $runId, string $date, Collection $sent): int
    {
        $records = 0;
        $done = 0;

        foreach ($sent as $code => $schedules) {
            $this->progress($log, 'push_schedules', $done++, $sent->count(), $code);

            $http->post('/schedules', [
                'run_id' => $runId,
                'date' => $date,
                'station' => $code,
                'schedules' => $schedules->map(fn (Schedule $s) => [
                    'train_number' => $s->train_number,
                    'line_name' => $s->trainLine?->name ?? 'KRL',
                    'line_color' => $s->color ?? $s->trainLine?->color,
                    'route_name' => $s->route_name,
                    'destination' => $s->destination,
                    'departure_time' => $s->departure_time,
                    'destination_arrival_time' => $s->destination_arrival_time,
                ])->values()->all(),
            ]);

            $records += $schedules->count();
        }

        $this->progress($log, 'push_schedules', $sent->count(), $sent->count());

        return $records;
    }

    /**
     * @param  Collection<string, Collection<int, Schedule>>  $sent
     * @return array{int, int} trains, stops
     */
    private function pushStops(PendingRequest $http, SyncLog $log, int $runId, string $date, Collection $sent): array
    {
        $trainNumbers = $sent->flatten(1)->pluck('train_number')->unique()->values();
        $stops = 0;
        $done = 0;

        foreach ($trainNumbers->chunk(self::STOPS_CHUNK) as $chunk) {
            $this->progress($log, 'push_stops', $done, $trainNumbers->count());

            $byTrain = TrainStop::whereDate('service_date', $date)
                ->whereIn('train_number', $chunk->all())
                ->orderBy('sequence')
                ->get()
                ->groupBy('train_number');

            $payload = $byTrain->map(fn (Collection $rows, $train) => [
                'train_number' => (string) $train,
                'stops' => $rows->map(fn (TrainStop $r) => [
                    'station_code' => $r->station_code,
                    'time' => $r->time,
                    'is_transit' => $r->is_transit,
                ])->values()->all(),
            ])->values()->all();

            if ($payload !== []) {
                $http->post('/stops', ['run_id' => $runId, 'date' => $date, 'trains' => $payload]);
                $stops += $byTrain->sum(fn (Collection $rows) => $rows->count());
            }

            $done += $chunk->count();
        }

        $this->progress($log, 'push_stops', $trainNumbers->count(), $trainNumbers->count());

        return [$trainNumbers->count(), $stops];
    }

    /**
     * @return array{phase: string, label: string, done: int, total: int, percent: int, detail: ?string}
     */
    private function progressMeta(string $phase, int $done, int $total, ?string $detail = null, bool $complete = false, bool $fetch = true): array
    {
        // Without fetching, the fetch phases have no share of the bar.
        $phases = array_filter(self::PHASES, fn (string $key) => $fetch || ! str_starts_with($key, 'fetch_'), ARRAY_FILTER_USE_KEY);
        $totalWeight = array_sum(array_column($phases, 'weight'));

        $before = 0;
        foreach ($phases as $key => $info) {
            if ($key === $phase) {
                break;
            }
            $before += $info['weight'];
        }

        $fraction = $total > 0 ? min(1, $done / $total) : 1;
        $percent = (int) round(100 * ($before + ($phases[$phase]['weight'] ?? 0) * $fraction) / $totalWeight);

        return [
            'phase' => $phase,
            'label' => self::PHASES[$phase]['label'],
            'done' => $done,
            'total' => $total,
            'percent' => $complete ? 100 : min(99, $percent),
            'detail' => $detail,
        ];
    }

    /**
     * Writes the progress to the log (throttled: at most about once a second,
     * always at the start and end of a phase).
     */
    private function progress(SyncLog $log, string $phase, int $done, int $total, ?string $detail = null): void
    {
        $edge = $done === 0 || $done >= $total;
        $now = microtime(true);

        if (! $edge && $now - $this->lastWrite < 1.0) {
            return;
        }

        $this->lastWrite = $now;
        $meta = SyncLog::whereKey($log->id)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : ($meta ?? []);
        $meta['progress'] = $this->progressMeta($phase, $done, $total, $detail, fetch: (bool) ($meta['fetch'] ?? true));

        SyncLog::whereKey($log->id)->update(['meta' => json_encode($meta)]);
        $log->meta = $meta;
    }

    private function describe(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $status = $e->response->status();
            $body = (string) ($e->response->json('message') ?? '');

            return "Server prod menolak permintaan (HTTP {$status})".($body !== '' ? ": {$body}" : '.');
        }

        return class_basename($e).': '.$e->getMessage();
    }
}

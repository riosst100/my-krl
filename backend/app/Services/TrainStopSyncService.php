<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use App\Services\Concerns\FinishesSyncLog;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\Kci\Exceptions\KciBlockedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Stops of each train (KCI "train-schedule?trainid=..."), stored per service
 * date. Its own sync ("Sync Kereta"): the trains in the stored timetable of
 * the selected sync stations (ScheduleSyncService::syncStationCodes()).
 */
class TrainStopSyncService
{
    use FinishesSyncLog;

    private const LOCK = 'kci-train-stop-sync';

    public function __construct(private readonly KciUrlClient $urlClient) {}

    /**
     * The Train Stops API URL in effect (admin setting, else KCI_TRAIN_STOPS_API_URL).
     * Empty = do not sync stops.
     */
    public function apiUrl(): string
    {
        return trim((string) Setting::value(Setting::TRAIN_STOPS_API_URL, (string) config('kci.train_stops_api_url')));
    }

    /**
     * Replaces a {train} placeholder or the "trainid" query value with the train number.
     *
     * @throws KciApiException
     */
    public static function urlForTrain(string $url, string $trainNumber): string
    {
        if (str_contains($url, '{train}')) {
            return str_replace('{train}', rawurlencode($trainNumber), $url);
        }

        $replaced = preg_replace('/([?&]trainid=)[^&#]*/i', '${1}'.rawurlencode($trainNumber), $url, 1, $count);

        if ($count === 0) {
            throw KciApiException::invalidResponse('Train Stops API URL must contain a "trainid" query parameter or a {train} placeholder');
        }

        return $replaced;
    }

    public function createLog(string $trigger, ?int $userId = null, SyncStatus $status = SyncStatus::Queued): SyncLog
    {
        $url = $this->apiUrl();

        return SyncLog::create([
            'type' => SyncLog::TYPE_KCI_TRAIN_STOPS,
            'status' => $status,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            'source' => 'http',
            'meta' => ['url' => $url !== '' ? $url : null],
        ]);
    }

    /**
     * Syncs the stops of every train in the selected stations' stored timetable,
     * then drops the stops of other trains and other dates. A manual run fetches
     * every train again; an automatic one skips trains already stored for the date.
     *
     * @param  (callable(string $phase, int $done, int $total): void)|null  $progress
     */
    public function run(SyncLog $log, ?callable $progress = null): SyncLog
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            return $this->finish($log, SyncStatus::Failed, 'Another train stop synchronization is already running.');
        }

        $only = ScheduleSyncService::syncStationCodes();
        $log->update([
            'status' => SyncStatus::Running,
            'started_at' => now(),
            'meta' => [...($log->meta ?? []), 'stations' => $only ?: 'all'],
        ]);

        try {
            if ($this->apiUrl() === '') {
                return $this->finish($log, SyncStatus::Failed, 'The Train Stops API URL is not set (Configuration → Sync Configuration).');
            }

            $date = $this->serviceDate($only);

            if ($date === null) {
                return $this->finish($log, SyncStatus::Failed, 'No stored schedules for the selected stations. Run the schedule sync first.');
            }

            $trains = $this->trainNumbers($date, $only);
            $result = $this->sync($trains, $date, $progress, refresh: $log->trigger !== 'schedule');

            // Only the selected stations' trains of this date stay.
            $pruned = $result['fetched'] > 0 || $result['skipped'] > 0
                ? TrainStop::where(fn ($q) => $q->where('service_date', '!=', $date->toDateString())->orWhereNotIn('train_number', $trains->all()))->delete()
                : 0;

            $log->fill([
                'records_processed' => $result['stops'],
                'stations_processed' => $only === [] ? Station::active()->count() : count($only),
                'meta' => [
                    ...$log->meta,
                    'date' => $date->toDateString(),
                    'pruned' => $pruned,
                    'train_stops' => [
                        ...collect($result)->except('failed')->all(),
                        'failed' => count($result['failed']),
                        'failed_sample' => array_slice($result['failed'], 0, 5, true),
                    ],
                ],
            ]);

            if ($result['failed'] === []) {
                return $this->finish($log, SyncStatus::Success);
            }

            $first = reset($result['failed']);
            $status = $result['fetched'] > 0 ? SyncStatus::Partial : SyncStatus::Failed;

            return $this->finish($log, $status, count($result['failed'])." train(s) without stop data. First error: {$first}");
        } catch (Throwable $e) {
            Log::error('KCI train stop sync failed', ['log_id' => $log->id, 'exception' => $e]);

            return $this->finish($log, SyncStatus::Failed, $e instanceof KciApiException
                ? $e->getMessage()
                : 'Unexpected error: '.class_basename($e).': '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * The service date to sync stops for: today when the selected stations have
     * schedules for today, otherwise their latest stored date.
     *
     * @param  list<string>  $only
     */
    private function serviceDate(array $only): ?CarbonImmutable
    {
        $dates = $this->selectedSchedules($only);

        if ((clone $dates)->whereDate('service_date', today())->exists()) {
            return CarbonImmutable::today();
        }

        $latest = $dates->max('service_date');

        return $latest ? CarbonImmutable::parse($latest)->startOfDay() : null;
    }

    /**
     * Train numbers stored for the selected stations on the given date.
     *
     * @param  list<string>  $only
     * @return Collection<int, string>
     */
    private function trainNumbers(CarbonInterface $date, array $only): Collection
    {
        return $this->selectedSchedules($only)
            ->whereDate('service_date', $date->toDateString())
            ->distinct()
            ->orderBy('train_number')
            ->pluck('train_number');
    }

    /**
     * @param  list<string>  $only  station codes; empty = every active station
     */
    private function selectedSchedules(array $only): Builder
    {
        return Schedule::query()
            ->whereHas('station', fn ($q) => $q->active()->when($only !== [], fn ($s) => $s->whereIn('code', $only)));
    }

    /**
     * Fetches and stores the stops of the given trains for one service date.
     * Trains whose stops are already stored for that date are skipped unless $refresh.
     *
     * @param  Collection<int, string>  $trainNumbers
     * @param  (callable(string $phase, int $done, int $total): void)|null  $progress  called as ("stops", done, total)
     * @return array{trains: int, fetched: int, skipped: int, stops: int, failed: array<string, string>}
     */
    public function sync(Collection $trainNumbers, CarbonInterface $date, ?callable $progress = null, bool $refresh = false): array
    {
        $result = ['trains' => 0, 'fetched' => 0, 'skipped' => 0, 'stops' => 0, 'failed' => []];
        $url = $this->apiUrl();
        $trainNumbers = $trainNumbers->unique()->values();
        $result['trains'] = $trainNumbers->count();

        if ($url === '' || $trainNumbers->isEmpty()) {
            return $result;
        }

        $serviceDate = $date->toDateString();
        $known = $refresh ? collect() : TrainStop::whereDate('service_date', $serviceDate)->distinct()->pluck('train_number')->flip();
        $stationIds = Station::pluck('id', 'code');
        $token = config('kci.train_stops_api_token');
        $concurrency = max(1, (int) config('kci.train_stops_concurrency'));
        $delay = (int) config('kci.request_delay_ms') * 1000;

        $pending = $trainNumbers->reject(fn (string $train) => $known->has($train))->values();
        $result['skipped'] = $trainNumbers->count() - $pending->count();
        $total = $pending->count();
        $progress && $progress('stops', 0, $total);

        // The upstream answers slowly (several seconds per train): fetch in
        // small parallel batches, then retry failures once, one at a time.
        // A block (KciBlockedException) aborts the whole run.
        foreach ($pending->chunk($concurrency * 4) as $chunk) {
            try {
                $urls = $chunk->mapWithKeys(fn (string $train) => [$train => self::urlForTrain($url, $train)])->all();
            } catch (KciApiException $e) {
                $result['failed'] = $pending->mapWithKeys(fn ($train) => [$train => $e->getMessage()])->all();
                break;
            }

            foreach ($this->urlClient->fetchMany($urls, $token, $concurrency) as $train => $payload) {
                $train = (string) $train;

                try {
                    if ($payload instanceof KciApiException) {
                        $delay && usleep($delay);
                        $payload = $this->urlClient->fetch($urls[$train], $token);
                    }

                    $result['stops'] += $this->store($serviceDate, $train, $this->parse($payload, $train), $stationIds);
                    $result['fetched']++;
                } catch (KciBlockedException $e) {
                    throw $e;
                } catch (KciApiException $e) {
                    $result['failed'][$train] = $e->getMessage();
                    Log::warning('KCI train stops failed', ['train' => $train, 'error' => $e->getMessage()]);
                }

                $progress && $progress('stops', $result['fetched'] + count($result['failed']), $total);
            }

            // Stop early when the source is clearly unavailable (e.g. blocked).
            if ($result['fetched'] === 0 && count($result['failed']) >= 10) {
                break;
            }

            $delay && usleep($delay);
        }

        return $result;
    }

    /**
     * @return list<array{station_code: string, time: string, is_transit: bool}>
     *
     * @throws KciApiException
     */
    public function parse(array $payload, string $train): array
    {
        if (isset($payload['status']) && is_numeric($payload['status']) && (int) $payload['status'] !== 200) {
            throw KciApiException::invalidResponse("status {$payload['status']} for stops of {$train}");
        }

        $rows = array_is_list($payload) ? $payload : ($payload['data'] ?? null);

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw KciApiException::invalidResponse("missing data array for stops of {$train}");
        }

        $stops = [];
        foreach ($rows as $row) {
            if (! is_array($row) || Validator::make($row, [
                'station_id' => ['required', 'string', 'max:10'],
                'time_est' => ['required', 'date_format:H:i:s'],
            ])->fails()) {
                continue;
            }

            $stops[] = [
                'station_code' => strtoupper(trim($row['station_id'])),
                'time' => $row['time_est'],
                'is_transit' => filter_var($row['transit_station'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        if ($stops === []) {
            throw KciApiException::invalidResponse("no stops for {$train}");
        }

        return $stops;
    }

    /**
     * @param  list<array{station_code: string, time: string, is_transit: bool}>  $stops
     * @param  Collection<string, int>  $stationIds
     */
    public function store(string $serviceDate, string $train, array $stops, Collection $stationIds): int
    {
        $now = now();
        $rows = array_map(fn (array $stop, int $i) => [
            'service_date' => $serviceDate,
            'train_number' => $train,
            'sequence' => $i + 1,
            'station_code' => $stop['station_code'],
            'station_id' => $stationIds[$stop['station_code']] ?? null,
            'time' => $stop['time'],
            'is_transit' => $stop['is_transit'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $stops, array_keys($stops));

        DB::transaction(function () use ($serviceDate, $train, $rows) {
            TrainStop::whereDate('service_date', $serviceDate)->where('train_number', $train)->delete();
            TrainStop::insert($rows);
        });

        return count($rows);
    }
}

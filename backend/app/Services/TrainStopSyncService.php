<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Station;
use App\Models\TrainStop;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Stops of each train (KCI "train-schedule?trainid=..."), stored per service
 * date. Runs as part of the schedule sync, for the trains that were synced.
 */
class TrainStopSyncService
{
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

    /**
     * Fetches and stores the stops of the given trains for one service date.
     * Trains whose stops are already stored for that date are skipped.
     *
     * @param  Collection<int, string>  $trainNumbers
     * @param  (callable(string $phase, int $done, int $total): void)|null  $progress  called as ("stops", done, total)
     * @return array{trains: int, fetched: int, skipped: int, stops: int, failed: array<string, string>}
     */
    public function sync(Collection $trainNumbers, CarbonInterface $date, ?callable $progress = null): array
    {
        $result = ['trains' => 0, 'fetched' => 0, 'skipped' => 0, 'stops' => 0, 'failed' => []];
        $url = $this->apiUrl();
        $trainNumbers = $trainNumbers->unique()->values();
        $result['trains'] = $trainNumbers->count();

        if ($url === '' || $trainNumbers->isEmpty()) {
            return $result;
        }

        $serviceDate = $date->toDateString();
        $known = TrainStop::whereDate('service_date', $serviceDate)->distinct()->pluck('train_number')->flip();
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

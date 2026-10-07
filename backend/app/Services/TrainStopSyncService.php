<?php

namespace App\Services;

use App\Models\TrainStop;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Stops of each train (KCI "train-schedule?trainid=..."), stored per service
 * date. They arrive through krl-sync on Vercel (on demand, see
 * TrainStopLookupService, or a full push to the ingest API) or a pasted KCI
 * response; this server never fetches from KCI itself.
 */
class TrainStopSyncService
{
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
            // This train's stops (this date and older days) are replaced; other trains are untouched.
            TrainStop::whereDate('service_date', '<=', $serviceDate)->where('train_number', $train)->delete();
            TrainStop::insert($rows);
        });

        return count($rows);
    }
}

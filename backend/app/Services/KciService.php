<?php

namespace App\Services;

use App\Services\Kci\Contracts\KciClient;
use App\Services\Kci\Data\KciSchedule;
use App\Services\Kci\Data\KciStation;
use App\Services\Kci\Exceptions\KciApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Validates and transforms KCI payloads into typed data objects. This is the
 * only place that knows about KCI field names (sta_id, ka_name, time_est...).
 */
class KciService
{
    public function __construct(private readonly KciClient $client) {}

    public function client(): KciClient
    {
        return $this->client;
    }

    /**
     * @return Collection<int, KciStation>
     *
     * @throws KciApiException
     */
    public function getStations(): Collection
    {
        return $this->parseStations($this->client->fetchStations());
    }

    /**
     * @return array{stations: Collection<int, KciStation>, areas: array<int, string>}
     *
     * @throws KciApiException
     */
    public function getStationList(): array
    {
        return $this->parseStationList($this->client->fetchStations());
    }

    /**
     * Field names accepted for each station attribute. The KCI "krl-webs"
     * names come first; the others cover common alternative JSON shapes,
     * since the Stations API URL is configurable.
     */
    private const STATION_FIELDS = [
        'code' => ['sta_id', 'station_id', 'code', 'station_code', 'kode', 'id'],
        'name' => ['sta_name', 'station_name', 'name', 'nama'],
        'enabled' => ['fg_enable', 'is_active', 'active', 'enabled', 'status'],
        'area' => ['group_wil', 'operational_area', 'group', 'wilayah', 'daop'],
    ];

    /**
     * Turns a station-list payload into KciStation objects. Accepts a list at
     * the top level or under "data", "stations", "result" or "data.stations".
     *
     * @return Collection<int, KciStation>
     *
     * @throws KciApiException
     */
    public function parseStations(array $payload): Collection
    {
        return $this->parseStationList($payload)['stations'];
    }

    /**
     * Like parseStations(), but also returns operational-area names. KCI mixes
     * area header rows into the list (e.g. sta_id "WIL0", sta_name
     * "AREA JABODETABEK"); those are not stations.
     *
     * @return array{stations: Collection<int, KciStation>, areas: array<int, string>}
     *
     * @throws KciApiException
     */
    public function parseStationList(array $payload): array
    {
        $all = $this->parseStationRows($payload);
        $areas = [];

        $stations = $all->reject(function (KciStation $station) use (&$areas) {
            if (! self::isAreaHeader($station->code, $station->name)) {
                return false;
            }

            $id = preg_match('/^WIL(\d+)$/i', $station->code, $m) ? (int) $m[1] : $station->operationalArea;
            if ($id !== null) {
                $areas[$id] = Str::title(Str::lower(preg_replace('/^AREA\s+/i', '', $station->name)));
            }

            return true;
        })->values();

        ksort($areas);

        return ['stations' => $stations, 'areas' => $areas];
    }

    public static function isAreaHeader(string $code, string $name): bool
    {
        return (bool) preg_match('/^WIL\d+$/i', $code) || (bool) preg_match('/^AREA\s/i', $name);
    }

    /**
     * @return Collection<int, KciStation>
     */
    private function parseStationRows(array $payload): Collection
    {
        if (isset($payload['status']) && is_numeric($payload['status']) && (int) $payload['status'] !== 200) {
            throw KciApiException::invalidResponse("status {$payload['status']} for stations");
        }

        $rows = array_is_list($payload) ? $payload : (
            $payload['data']['stations'] ?? $payload['data'] ?? $payload['stations'] ?? $payload['result'] ?? null
        );

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw KciApiException::invalidResponse('no station list found (expected a JSON array, or one under "data"/"stations")');
        }

        $stations = collect($rows)
            ->map(fn ($row) => is_array($row) ? $this->mapStationRow($row) : null)
            ->filter()
            ->unique('code')
            ->values();

        if ($stations->isEmpty() && $rows !== []) {
            throw KciApiException::invalidResponse('station rows found, but none had a recognisable code and name ('.implode(', ', array_keys((array) $rows[0])).')');
        }

        return $stations;
    }

    private function mapStationRow(array $row): ?KciStation
    {
        $row = array_change_key_case($row, CASE_LOWER);
        $pick = function (string $attribute) use ($row) {
            foreach (self::STATION_FIELDS[$attribute] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                    return $row[$field];
                }
            }

            return null;
        };

        $code = $pick('code');
        $name = $pick('name');

        if (! is_scalar($code) || ! is_string($name) || strlen((string) $code) > 10 || mb_strlen($name) > 100) {
            Log::warning('Station row skipped', ['row' => $row]);

            return null;
        }

        $enabled = $pick('enabled');
        $area = $pick('area');

        return new KciStation(
            code: strtoupper(trim((string) $code)),
            name: trim($name),
            enabled: $enabled === null || in_array(strtolower((string) $enabled), ['1', 'true', 'active', 'aktif', 'y', 'yes'], true),
            operationalArea: is_numeric($area) ? (int) $area : null,
        );
    }

    /**
     * @return Collection<int, KciSchedule>
     *
     * @throws KciApiException
     */
    public function getStationSchedules(string $stationCode, CarbonInterface $date): Collection
    {
        return $this->parseSchedules($this->client->fetchStationSchedules($stationCode, $date), "schedules of {$stationCode}");
    }

    /**
     * Turns a KCI schedule payload ({"status":200,"data":[{"train_id",...}]})
     * into KciSchedule objects. Invalid rows are skipped and logged.
     *
     * @return Collection<int, KciSchedule>
     *
     * @throws KciApiException
     */
    public function parseSchedules(array $payload, string $what = 'schedules'): Collection
    {
        $rows = array_is_list($payload) ? $payload : $this->extractData($payload, $what);

        return $this->validRows($rows, [
            'train_id' => ['required', 'max:20'],
            'ka_name' => ['required', 'string', 'max:100'],
            'route_name' => ['nullable', 'string', 'max:150'],
            'dest' => ['required', 'string', 'max:100'],
            'time_est' => ['required', 'date_format:H:i:s'],
            'dest_time' => ['nullable', 'date_format:H:i:s'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], 'schedule')->map(fn (array $row) => new KciSchedule(
            trainNumber: trim((string) $row['train_id']),
            lineName: trim($row['ka_name']),
            lineColor: $row['color'] ?? null,
            routeName: isset($row['route_name']) ? trim($row['route_name']) : null,
            destination: trim($row['dest']),
            departureTime: $row['time_est'],
            destinationArrivalTime: $row['dest_time'] ?? null,
        ))->unique('trainNumber')->values();
    }

    private function extractData(array $payload, string $what): array
    {
        if (isset($payload['status']) && (int) $payload['status'] !== 200) {
            throw KciApiException::invalidResponse("status {$payload['status']} for {$what}");
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            throw KciApiException::invalidResponse("missing data array for {$what}");
        }

        return $payload['data'];
    }

    /**
     * Drops (and logs) individual rows that fail validation instead of
     * failing the whole response.
     */
    private function validRows(array $rows, array $rules, string $type): Collection
    {
        return collect($rows)->filter(function ($row) use ($rules, $type) {
            if (! is_array($row)) {
                return false;
            }

            $validator = Validator::make($row, $rules);

            if ($validator->fails()) {
                Log::warning("KCI {$type} row skipped", ['row' => $row, 'errors' => $validator->errors()->all()]);

                return false;
            }

            return true;
        });
    }
}

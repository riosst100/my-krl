<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainLine;
use App\Models\TrainStop;
use App\Models\User;
use App\Services\Concerns\FinishesSyncLog;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Data\KciSchedule;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\Kci\Exceptions\KciBlockedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily schedule sync: KCI → validate/transform (KciService) → upsert into
 * PostgreSQL. Station master data is synced separately (StationSyncService,
 * monthly); it is only bootstrapped here when the stations table is empty.
 * Every run is recorded in sync_logs.
 */
class ScheduleSyncService
{
    use FinishesSyncLog;

    private const LOCK = 'kci-schedule-sync';

    /** @var array<string, int> line name => id */
    private array $lineIds = [];

    /** @var array<string, string>|null normalized name => station name */
    private ?array $stationNames = null;

    public function __construct(
        private readonly KciService $kci,
        private readonly StationSyncService $stationSync,
        private readonly KciUrlClient $urlClient,
        private readonly TrainStopSyncService $trainStops,
    ) {}

    /**
     * The Schedules API URL in effect: the admin setting if saved, otherwise
     * KCI_SCHEDULES_API_URL. Empty string = use the KCI client (http/mock).
     */
    public function schedulesApiUrl(): string
    {
        return trim((string) Setting::value(Setting::SCHEDULES_API_URL, (string) config('kci.schedules_api_url')));
    }

    /**
     * Station codes whose timetable is synced: the admin setting if saved,
     * otherwise KCI_SYNC_STATIONS (empty = every active station).
     *
     * @return list<string>
     */
    public static function syncStationCodes(): array
    {
        $saved = Setting::value(Setting::SYNC_STATIONS);
        $codes = $saved !== null ? explode(',', $saved) : config('kci.sync_stations');

        return array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes))));
    }

    /**
     * The URL for one station: replaces a {station} placeholder, or the value
     * of the "stationid" query parameter, with the station code.
     *
     * @throws KciApiException when the URL has neither
     */
    public static function urlForStation(string $url, string $stationCode): string
    {
        if (str_contains($url, '{station}')) {
            return str_replace('{station}', rawurlencode($stationCode), $url);
        }

        $replaced = preg_replace('/([?&]stationid=)[^&#]*/i', '${1}'.rawurlencode($stationCode), $url, 1, $count);

        if ($count === 0) {
            throw KciApiException::invalidResponse('Schedules API URL must contain a "stationid" query parameter or a {station} placeholder');
        }

        return $replaced;
    }

    public function createLog(string $trigger, ?int $userId = null, SyncStatus $status = SyncStatus::Queued): SyncLog
    {
        $url = $this->schedulesApiUrl();

        return SyncLog::create([
            'type' => SyncLog::TYPE_KCI_SCHEDULES,
            'status' => $status,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            'source' => $url !== '' ? 'http' : $this->kci->client()->name(),
            'meta' => ['url' => $url !== '' ? $url : null],
        ]);
    }

    /**
     * Dry run for the admin "Tes URL" button: fetch + parse one station's
     * timetable, no database writes.
     *
     * @return array{ok: bool, url: string, station: string, count: int, first: ?string, last: ?string, lines: list<string>, sample: list<array<string, mixed>>, error: ?string}
     */
    public function preview(string $url, string $stationCode): array
    {
        $result = ['ok' => false, 'url' => $url, 'station' => $stationCode, 'count' => 0, 'first' => null, 'last' => null, 'lines' => [], 'sample' => [], 'error' => null];

        try {
            $result['url'] = self::urlForStation($url, $stationCode);
            $schedules = $this->kci->parseSchedules(
                $this->urlClient->fetch($result['url'], config('kci.schedules_api_token')),
                "schedules of {$stationCode}",
            )->sortBy('departureTime')->values();
        } catch (KciApiException $e) {
            return [...$result, 'error' => $e->getMessage()];
        }

        return [
            ...$result,
            'ok' => $schedules->isNotEmpty(),
            'count' => $schedules->count(),
            'first' => $schedules->first() ? substr($schedules->first()->departureTime, 0, 5) : null,
            'last' => $schedules->last() ? substr($schedules->last()->departureTime, 0, 5) : null,
            'lines' => $schedules->pluck('lineName')->unique()->values()->all(),
            'sample' => $schedules->take(5)->map(fn (KciSchedule $s) => [
                'train_number' => $s->trainNumber,
                'line' => $s->lineName,
                'route_name' => $s->routeName,
                'destination' => $this->destinationName($s->destination),
                'departure_time' => substr($s->departureTime, 0, 5),
                'destination_arrival_time' => $s->destinationArrivalTime ? substr($s->destinationArrivalTime, 0, 5) : null,
            ])->all(),
            'error' => $schedules->isEmpty() ? 'The timetable is empty.' : null,
        ];
    }

    /**
     * @param  list<string>|null  $stationCodes  Only sync these station codes; null = KCI_SYNC_STATIONS (empty = all).
     * @param  (callable(string $phase, int $done, int $total): void)|null  $progress  phase is "schedules" or "stops"
     */
    public function run(SyncLog $log, ?CarbonInterface $from = null, ?int $days = null, ?array $stationCodes = null, ?callable $progress = null): SyncLog
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            return $this->finish($log, SyncStatus::Failed, 'Another synchronization is already running.');
        }

        $url = (string) ($log->meta['url'] ?? '');
        $from = CarbonImmutable::parse(($from ?? now())->toDateString());
        // The Schedules API URL returns the current, undated timetable: store it
        // for one service day only instead of copying it onto future dates.
        $days = $url !== '' ? 1 : max(1, $days ?? config('kci.sync_days'));
        $dates = collect(range(0, $days - 1))->map(fn (int $i) => $from->addDays($i));
        $only = array_values(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $stationCodes ?? self::syncStationCodes(),
        )));

        $startedAt = now();
        $log->update([
            'status' => SyncStatus::Running,
            'started_at' => $startedAt,
            'meta' => [...($log->meta ?? []), 'from' => $from->toDateString(), 'days' => $days, 'stations' => $only ?: 'all'],
        ]);

        try {
            if (! Station::query()->exists()) {
                $this->stationSync->import($this->kci->getStations());
            }

            [$records, $stations, $failures] = $this->syncSchedules($dates, $only, $url, $progress);
            $this->refreshLineColors();

            // Stops per train (for "to station" search). Only for real KCI train numbers.
            $stops = $url !== '' ? $this->trainStops->sync($this->syncedTrainNumbers($from, $only), $from, $progress) : null;

            if ($url !== '') {
                // The Schedules API only ever returns the current timetable: everything this
                // run did not write is stale, so the tables end up fresh (a truncate that
                // only happens once the new data is in).
                $pruned = $stations > 0 ? Schedule::where('updated_at', '<', $startedAt)->delete() : 0;
                if ($stations > 0) {
                    TrainStop::where('service_date', '!=', $from->toDateString())->delete();
                }
            } else {
                $cutoff = now()->subDays(config('kci.retention_days'))->toDateString();
                $pruned = Schedule::where('service_date', '<', $cutoff)->delete();
                TrainStop::where('service_date', '<', $cutoff)->delete();
            }

            $log->fill([
                'records_processed' => $records,
                'stations_processed' => $stations,
                'meta' => [
                    ...$log->meta,
                    'failed_stations' => array_slice($failures, 0, 50, true),
                    'pruned' => $pruned,
                    'train_stops' => $stops ? [
                        ...collect($stops)->except('failed')->all(),
                        'failed' => count($stops['failed']),
                        'failed_sample' => array_slice($stops['failed'], 0, 5, true),
                    ] : null,
                ],
            ]);

            if ($failures === [] && ($stops === null || $stops['failed'] === [])) {
                return $this->finish($log, SyncStatus::Success);
            }

            if ($failures === []) {
                $first = reset($stops['failed']);

                return $this->finish($log, SyncStatus::Partial, count($stops['failed'])." train(s) without stop data. First error: {$first}");
            }

            $status = $stations > 0 ? SyncStatus::Partial : SyncStatus::Failed;
            $message = count($failures).' station(s) failed. First error: '.reset($failures);

            return $this->finish($log, $status, $message);
        } catch (Throwable $e) {
            Log::error('KCI schedule sync failed', ['log_id' => $log->id, 'exception' => $e]);

            return $this->finish($log, SyncStatus::Failed, $e instanceof KciApiException
                ? $e->getMessage()
                : 'Unexpected error: '.class_basename($e).': '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $dates
     * @param  list<string>  $only  station codes to sync; empty = every active station
     * @param  string  $url  Schedules API URL; empty = use the KCI client
     * @return array{int, int, array<string, string>}
     */
    private function syncSchedules(Collection $dates, array $only = [], string $url = '', ?callable $progress = null): array
    {
        $client = $this->kci->client();
        $delay = ($url !== '' || $client->name() === 'http') ? config('kci.request_delay_ms') * 1000 : 0;
        $dateSpecific = $url === '' && $client->isDateSpecific();
        $records = 0;
        $succeeded = 0;
        $failures = [];

        $stations = Station::active()
            ->when($only !== [], fn ($q) => $q->whereIn('code', $only))
            ->orderBy('code')
            ->get();

        $done = 0;

        foreach ($stations as $station) {
            $progress && $progress('schedules', $done++, $stations->count());

            try {
                $shared = null;

                foreach ($dates as $date) {
                    if ($dateSpecific || $shared === null) {
                        $schedules = $url !== ''
                            ? $this->kci->parseSchedules(
                                $this->urlClient->fetch(self::urlForStation($url, $station->code), config('kci.schedules_api_token')),
                                "schedules of {$station->code}",
                            )
                            : $this->kci->getStationSchedules($station->code, $date);
                        $shared = $schedules;
                        $delay && usleep($delay);
                    } else {
                        $schedules = $shared;
                    }

                    $records += $this->persist($station, $date, $schedules);
                }

                $succeeded++;
            } catch (KciBlockedException $e) {
                // Every remaining station would be refused too.
                throw $e;
            } catch (KciApiException $e) {
                $failures[$station->code] = $e->getMessage();
                Log::warning('KCI schedule sync failed for station', ['station' => $station->code, 'error' => $e->getMessage()]);
            }
        }

        $progress && $progress('schedules', $stations->count(), $stations->count());

        return [$records, $succeeded, $failures];
    }

    /**
     * @param  Collection<int, KciSchedule>  $schedules
     */
    public function persist(Station $station, CarbonInterface $date, Collection $schedules): int
    {
        $serviceDate = $date->toDateString();
        $now = now();

        $rows = $schedules->map(fn (KciSchedule $s) => [
            'station_id' => $station->id,
            'train_line_id' => $this->lineId($s->lineName, $s->lineColor),
            'color' => $s->lineColor,
            'train_number' => $s->trainNumber,
            'route_name' => $s->routeName,
            'destination' => $this->destinationName($s->destination),
            'departure_time' => $s->departureTime,
            'destination_arrival_time' => $s->destinationArrivalTime,
            'service_date' => $serviceDate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::transaction(function () use ($rows, $station, $serviceDate) {
            foreach ($rows->chunk(500) as $chunk) {
                Schedule::upsert(
                    $chunk->values()->all(),
                    ['station_id', 'service_date', 'train_number'],
                    ['train_line_id', 'color', 'route_name', 'destination', 'departure_time', 'destination_arrival_time', 'updated_at'],
                );
            }

            // Remove trains that are no longer in the published timetable.
            Schedule::where('station_id', $station->id)
                ->whereDate('service_date', $serviceDate)
                ->whereNotIn('train_number', $rows->pluck('train_number')->all() ?: [''])
                ->delete();
        });

        return $rows->count();
    }

    /**
     * Train numbers stored for the synced stations on the given date.
     *
     * @param  list<string>  $only
     * @return Collection<int, string>
     */
    private function syncedTrainNumbers(CarbonInterface $date, array $only): Collection
    {
        return Schedule::query()
            ->whereDate('service_date', $date->toDateString())
            ->whereHas('station', fn ($q) => $q->active()->when($only !== [], fn ($s) => $s->whereIn('code', $only)))
            ->distinct()
            ->orderBy('train_number')
            ->pluck('train_number');
    }

    /**
     * KCI sends destinations without spaces ("JAKARTAKOTA"); use the matching
     * station name ("Jakarta Kota") when there is one. A routing suffix is kept
     * as published: "KAMPUNGBANDAN VIA MRI" -> "Kampung Bandan via MRI".
     */
    private function destinationName(string $destination): string
    {
        $this->stationNames ??= Station::pluck('name')
            ->mapWithKeys(fn (string $name) => [$this->normalize($name) => $name])
            ->all();

        $via = null;
        if (preg_match('/^(.*?)\s+VIA\s+(.+)$/i', trim($destination), $m)) {
            [$destination, $via] = [$m[1], strtoupper(trim($m[2]))];
        }

        $name = $this->stationNames[$this->normalize($destination)] ?? Str::title(Str::lower($destination));

        return $via ? "{$name} via {$via}" : $name;
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($name));
    }

    private function lineId(string $name, ?string $color): int
    {
        return $this->lineIds[$name] ??= TrainLine::firstOrCreate(
            ['name' => $name],
            ['color' => $color],
        )->id;
    }

    /**
     * A line's colour is the one most of its trains use (KCI colours are per
     * train, so a single odd train must not recolour the whole line).
     */
    public function refreshLineColors(): void
    {
        $dominant = Schedule::query()
            ->whereNotNull('train_line_id')
            ->whereNotNull('color')
            ->select('train_line_id', 'color', DB::raw('count(*) as uses'))
            ->groupBy('train_line_id', 'color')
            ->orderByDesc('uses')
            ->get()
            ->unique('train_line_id');

        foreach ($dominant as $row) {
            TrainLine::whereKey($row->train_line_id)->where(fn ($q) => $q->whereNull('color')->orWhere('color', '!=', $row->color))
                ->update(['color' => $row->color]);
        }
    }
}

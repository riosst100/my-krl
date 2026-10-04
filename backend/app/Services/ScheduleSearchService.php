<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\Schedule;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\TrainStop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side for schedules. Always served from PostgreSQL, never from KCI.
 */
class ScheduleSearchService
{
    /**
     * @param  array{date: string, station?: string, direction?: string, line?: string, train_number?: string, time_from?: string, time_to?: string}  $filters
     */
    public function query(array $filters, bool $activeStationsOnly = true): Builder
    {
        // Columns are qualified: forStation() may join train_stops (same column names).
        return Schedule::query()
            ->select('schedules.*')
            ->with(['trainLine', 'station'])
            ->whereDate('schedules.service_date', $filters['date'])
            ->when($activeStationsOnly, fn (Builder $q) => $q->whereHas('station', fn (Builder $s) => $s->active()))
            ->when($filters['station'] ?? null, fn (Builder $q, string $code) => $q->whereHas(
                'station', fn (Builder $s) => $s->where('code', strtoupper($code))
            ))
            ->when($filters['direction'] ?? null, fn (Builder $q, string $direction) => $q->whereLike('schedules.destination', '%'.$direction.'%'))
            ->when($filters['line'] ?? null, fn (Builder $q, string $line) => $q->whereHas(
                'trainLine', fn (Builder $l) => $l->whereLike('name', '%'.$line.'%')
            ))
            ->when($filters['train_number'] ?? null, fn (Builder $q, string $number) => $q->whereLike('schedules.train_number', $number.'%'))
            ->when($filters['time_from'] ?? null, fn (Builder $q, string $time) => $q->where('schedules.departure_time', '>=', $time))
            ->when($filters['time_to'] ?? null, fn (Builder $q, string $time) => $q->where('schedules.departure_time', '<=', $time.':59'))
            ->orderBy('schedules.departure_time')
            ->orderBy('schedules.train_number');
    }

    /**
     * @return array{schedules: Collection<int, Schedule>, meta: array<string, mixed>}
     */
    public function forStation(Station $station, array $filters, ?Station $to = null): array
    {
        $filters['station'] = $station->code;
        $query = $this->query($filters);

        if ($to) {
            $this->joinTrip($query, $to);
        }

        // Facets are computed over the whole day so the UI can offer them as filters.
        $day = Schedule::query()
            ->where('station_id', $station->id)
            ->whereDate('service_date', $filters['date'])
            ->with('trainLine')
            ->get(['id', 'destination', 'train_line_id']);

        return [
            'schedules' => $query->get(),
            'meta' => [
                'station' => ['code' => $station->code, 'name' => $station->name, 'slug' => $station->slug],
                'to' => $to ? ['code' => $to->code, 'name' => $to->name, 'slug' => $to->slug] : null,
                // Whether stop data exists, i.e. whether "to station" search is possible for this date.
                'stops_available' => TrainStop::whereDate('service_date', $filters['date'])->where('station_id', $station->id)->exists(),
                'date' => $filters['date'],
                'total_for_date' => $day->count(),
                'destinations' => $day->pluck('destination')->unique()->sort()->values(),
                'lines' => $day->pluck('trainLine')->filter()->unique('id')->map->only(['id', 'name', 'color'])->sortBy('name')->values(),
                'last_synced_at' => $this->lastSyncedAt(),
            ],
        ];
    }

    /**
     * Only trains that stop at $to after the schedule's station, with the arrival time there.
     */
    private function joinTrip(Builder $query, Station $to): void
    {
        $query->join('train_stops as from_stop', function ($join) {
            $join->on('from_stop.service_date', '=', 'schedules.service_date')
                ->on('from_stop.train_number', '=', 'schedules.train_number')
                ->on('from_stop.station_id', '=', 'schedules.station_id');
        })->join('train_stops as to_stop', function ($join) use ($to) {
            $join->on('to_stop.service_date', '=', 'from_stop.service_date')
                ->on('to_stop.train_number', '=', 'from_stop.train_number')
                ->on('to_stop.sequence', '>', 'from_stop.sequence')
                ->where('to_stop.station_id', '=', $to->id);
        })->addSelect([
            'to_stop.time as to_station_time',
            DB::raw('(to_stop.sequence - from_stop.sequence) as stops_to_station'),
        ]);
    }

    /**
     * The next trains from $from that stop at $to, starting now (Asia/Jakarta); rolls
     * over to the next synced service date when fewer than $limit are left today.
     *
     * @return array{departures: Collection<int, Schedule>, has_schedules_today: bool}
     */
    public function nextRouteDepartures(Station $from, Station $to, int $limit, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $today = $now->toDateString();
        $base = fn (string $date) => tap(
            $this->query(['date' => $date, 'station' => $from->code]),
            fn (Builder $q) => $this->joinTrip($q, $to),
        );

        $departures = $base($today)->where('schedules.departure_time', '>=', $now->format('H:i:s'))->limit($limit)->get();

        if ($departures->count() < $limit) {
            $nextDate = Schedule::query()
                ->where('station_id', $from->id)
                ->whereDate('service_date', '>', $today)
                ->min('service_date');

            if ($nextDate) {
                $departures = $departures->concat($base(substr((string) $nextDate, 0, 10))->limit($limit - $departures->count())->get());
            }
        }

        return [
            'departures' => $departures,
            'has_schedules_today' => $this->hasSchedulesToday($from, $now),
        ];
    }

    /**
     * The next departures from each station, starting now (Asia/Jakarta). When
     * fewer than $limit trains are left today, the first trains of the next
     * synced service date fill the list.
     *
     * @param  Collection<int, Station>  $stations
     * @return Collection<int, array{station: Station, departures: Collection<int, Schedule>, has_schedules_today: bool}>
     */
    public function nextDepartures(Collection $stations, int $limit, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $today = $now->toDateString();

        return $stations->map(function (Station $station) use ($limit, $now, $today) {
            $base = fn () => Schedule::query()->with('trainLine')->where('station_id', $station->id);

            $departures = $base()
                ->whereDate('service_date', $today)
                ->where('departure_time', '>=', $now->format('H:i:s'))
                ->orderBy('departure_time')
                ->limit($limit)
                ->get();

            if ($departures->count() < $limit) {
                $nextDate = $base()->whereDate('service_date', '>', $today)->min('service_date');

                if ($nextDate) {
                    $departures = $departures->concat(
                        $base()->whereDate('service_date', $nextDate)->orderBy('departure_time')->limit($limit - $departures->count())->get()
                    );
                }
            }

            return [
                'station' => $station,
                'departures' => $departures,
                'has_schedules_today' => $base()->whereDate('service_date', $today)->exists(),
            ];
        });
    }

    /**
     * The soonest departures starting now (Asia/Jakarta), across all active
     * stations or from one station. Rolls over to the next synced service
     * date when needed.
     *
     * @return Collection<int, Schedule>
     */
    public function upcoming(int $limit, ?Station $station = null, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $today = $now->toDateString();
        $base = fn () => Schedule::query()
            ->with(['trainLine', 'station'])
            ->when(
                $station,
                fn (Builder $q) => $q->where('station_id', $station->id),
                fn (Builder $q) => $q->whereHas('station', fn (Builder $s) => $s->active()),
            );

        $departures = $base()
            ->whereDate('service_date', $today)
            ->where('departure_time', '>=', $now->format('H:i:s'))
            ->orderBy('departure_time')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($departures->count() < $limit) {
            $nextDate = $base()->whereDate('service_date', '>', $today)->min('service_date');

            if ($nextDate) {
                $departures = $departures->concat(
                    $base()->whereDate('service_date', $nextDate)->orderBy('departure_time')->orderBy('id')->limit($limit - $departures->count())->get()
                );
            }
        }

        return $departures;
    }

    /**
     * Whether any schedule exists today (for one station, or any active station).
     * Lets the UI tell "no more trains today" apart from "not synced".
     */
    public function hasSchedulesToday(?Station $station = null, ?CarbonInterface $now = null): bool
    {
        return Schedule::query()
            ->whereDate('service_date', ($now ?? now())->toDateString())
            ->when(
                $station,
                fn (Builder $q) => $q->where('station_id', $station->id),
                fn (Builder $q) => $q->whereHas('station', fn (Builder $s) => $s->active()),
            )
            ->exists();
    }

    /**
     * Stations reachable from $station on a date (they appear later in the
     * stop list of a train calling at $station), with the number of trains.
     *
     * @return Collection<int, array{code: string, name: string, slug: string, trains: int}>
     */
    public function reachableStations(Station $station, string $date): Collection
    {
        return TrainStop::query()
            ->from('train_stops as from_stop')
            ->join('train_stops as to_stop', function ($join) {
                $join->on('to_stop.service_date', '=', 'from_stop.service_date')
                    ->on('to_stop.train_number', '=', 'from_stop.train_number')
                    ->on('to_stop.sequence', '>', 'from_stop.sequence');
            })
            ->join('stations', 'stations.id', '=', 'to_stop.station_id')
            ->whereDate('from_stop.service_date', $date)
            ->where('from_stop.station_id', $station->id)
            ->where('stations.is_active', true)
            ->groupBy('stations.id', 'stations.code', 'stations.name', 'stations.slug')
            ->orderBy('stations.name')
            ->get(['stations.code', 'stations.name', 'stations.slug', DB::raw('count(distinct to_stop.train_number) as trains')])
            ->map(fn ($row) => ['code' => $row->code, 'name' => $row->name, 'slug' => $row->slug, 'trains' => (int) $row->trains]);
    }

    /**
     * @return Collection<int, string>
     */
    public function availableDates(): Collection
    {
        return Schedule::query()
            ->select('service_date')
            ->distinct()
            ->orderBy('service_date')
            ->pluck('service_date')
            ->map(fn ($date) => $date->toDateString());
    }

    public function lastSyncedAt(): ?string
    {
        return SyncLog::where('type', SyncLog::TYPE_KCI_SCHEDULES)
            ->whereIn('status', [SyncStatus::Success, SyncStatus::Partial])
            ->latest('finished_at')
            ->value('finished_at')?->toIso8601String();
    }
}

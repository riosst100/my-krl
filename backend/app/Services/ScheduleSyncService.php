<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Station;
use App\Models\TrainLine;
use App\Services\Kci\Data\KciSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores station timetables. The data comes from krl-sync on Vercel (ingest
 * API) or a pasted KCI response (manual import); this server never fetches
 * from KCI itself.
 */
class ScheduleSyncService
{
    /** @var array<string, int> line name => id */
    private array $lineIds = [];

    /** @var array<string, string>|null normalized name => station name */
    private ?array $stationNames = null;

    /**
     * Station codes whose timetable krl-sync syncs (GET /ingest/config): the
     * admin setting if saved, otherwise KCI_SYNC_STATIONS (empty = every active station).
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
     * A synced station's days before $date are replaced by the new timetable.
     * Only that station is touched; every other station keeps its data.
     */
    public function dropOlderDays(Station $station, CarbonInterface $date): int
    {
        return Schedule::where('station_id', $station->id)
            ->whereDate('service_date', '<', $date->toDateString())
            ->delete();
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

            $station->forceFill(['schedules_synced_at' => now()])->save();
        });

        return $rows->count();
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

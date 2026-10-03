<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Station;
use App\Models\TrainLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminStationService
{
    /**
     * Everything we know about a station: master data plus a summary of the
     * schedules stored for it.
     *
     * @return array<string, mixed>
     */
    public function detail(Station $station): array
    {
        $today = now()->toDateString();

        $lines = TrainLine::query()
            ->whereIn('id', Schedule::where('station_id', $station->id)->whereNotNull('train_line_id')->select('train_line_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $byDate = Schedule::query()
            ->where('station_id', $station->id)
            ->groupBy('service_date')
            ->orderBy('service_date')
            ->get([
                'service_date',
                DB::raw('count(*) as trains'),
                DB::raw('min(departure_time) as first_departure'),
                DB::raw('max(departure_time) as last_departure'),
            ])
            ->map(fn ($row) => [
                'date' => $row->service_date->toDateString(),
                'trains' => (int) $row->trains,
                'first_departure' => substr($row->first_departure, 0, 5),
                'last_departure' => substr($row->last_departure, 0, 5),
            ]);

        $destinations = Schedule::query()
            ->where('station_id', $station->id)
            ->whereDate('service_date', $today)
            ->select('destination', DB::raw('count(*) as trains'))
            ->groupBy('destination')
            ->orderByDesc('trains')
            ->get()
            ->map(fn ($row) => ['destination' => $row->destination, 'trains' => (int) $row->trains]);

        return [
            'lines' => $lines,
            'schedules_by_date' => $byDate,
            'destinations_today' => $destinations,
            'last_schedule_update' => ($updated = Schedule::where('station_id', $station->id)->max('updated_at'))
                ? Carbon::parse($updated)->toIso8601String()
                : null,
        ];
    }
}

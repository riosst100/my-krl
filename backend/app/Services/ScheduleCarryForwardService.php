<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\TrainStop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the last known timetable valid until a sync replaces it.
 *
 * The read side looks up one service date, so a station that was not synced
 * for that date would show nothing. Instead, every station without schedules
 * on the date gets a copy of its most recent synced day, and every train
 * without stops on the date a copy of its most recent stops. A sync then only
 * overwrites what it actually fetched; nothing is ever deleted here.
 */
class ScheduleCarryForwardService
{
    /**
     * @return array{schedules: int, stops: int}
     */
    public function carryForward(CarbonInterface $date): array
    {
        $day = $date->toDateString();

        return DB::transaction(fn () => [
            'schedules' => $this->schedules($day),
            'stops' => $this->stops($day),
        ]);
    }

    private function schedules(string $day): int
    {
        // Per station without rows on $day: its latest earlier service date.
        $latest = Schedule::query()
            ->select('station_id')
            ->selectRaw('max(service_date) as latest_date')
            ->where('service_date', '<', $day)
            ->whereNotIn('station_id', Schedule::query()->select('station_id')->where('service_date', $day))
            ->groupBy('station_id');

        $columns = ['station_id', 'train_line_id', 'color', 'train_number', 'route_name', 'destination', 'departure_time', 'destination_arrival_time'];

        return $this->copy('schedules', $columns, Schedule::query()
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('latest.station_id', '=', 'schedules.station_id')
                ->on('latest.latest_date', '=', 'schedules.service_date')), $day);
    }

    private function stops(string $day): int
    {
        // Trains running on $day (after the schedules above) whose stops are not known for $day.
        $trains = Schedule::query()
            ->where('service_date', $day)
            ->whereNotIn('train_number', TrainStop::query()->select('train_number')->where('service_date', $day))
            ->distinct()
            ->pluck('train_number');

        $columns = ['train_number', 'sequence', 'station_code', 'station_id', 'time', 'is_transit'];
        $copied = 0;

        foreach ($trains->chunk(1000) as $chunk) {
            $latest = TrainStop::query()
                ->select('train_number')
                ->selectRaw('max(service_date) as latest_date')
                ->where('service_date', '<', $day)
                ->whereIn('train_number', $chunk->values()->all())
                ->groupBy('train_number');

            // Marked as carried: the train's own stops for $day are still to be fetched.
            $copied += $this->copy('train_stops', $columns, TrainStop::query()
                ->joinSub($latest, 'latest', fn ($join) => $join
                    ->on('latest.train_number', '=', 'train_stops.train_number')
                    ->on('latest.latest_date', '=', 'train_stops.service_date')), $day, ['carried_forward' => true]);
        }

        return $copied;
    }

    /**
     * INSERT … SELECT of $columns from $source, re-dated to $day, plus fixed boolean $flags.
     *
     * @param  list<string>  $columns
     * @param  array<string, bool>  $flags
     */
    private function copy(string $table, array $columns, Builder $source, string $day, array $flags = []): int
    {
        $now = now()->toDateTimeString();
        $select = $source->toBase()
            ->select(array_map(fn (string $c) => "{$table}.{$c}", $columns))
            ->selectRaw('CAST(? AS DATE)', [$day])
            ->selectRaw('CAST(? AS TIMESTAMP)', [$now])
            ->selectRaw('CAST(? AS TIMESTAMP)', [$now]);

        foreach ($flags as $value) {
            $select->selectRaw($value ? 'TRUE' : 'FALSE');
        }

        return DB::table($table)->insertUsing([...$columns, 'service_date', 'created_at', 'updated_at', ...array_keys($flags)], $select);
    }
}

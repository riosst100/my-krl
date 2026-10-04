<?php

namespace App\Console\Commands;

use App\Services\KciTimetableWatchService;
use Illuminate\Console\Command;

class WatchKciTimetable extends Command
{
    protected $signature = 'kci:watch-timetable {--station= : Station code to watch, defaults to KCI_WATCH_STATION or the first synced station}';

    protected $description = 'Fingerprint KCI\'s current timetable to detect when KCI publishes a new one';

    public function handle(KciTimetableWatchService $watch): int
    {
        $check = $watch->check($this->option('station') ?: null);

        if (! $check->ok) {
            $this->warn("{$check->station_code}: check failed — {$check->error}");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s: %d trains (%s–%s)%s',
            $check->station_code,
            $check->trains,
            $check->first_departure,
            $check->last_departure,
            $check->changed ? ' — CHANGED since the previous check' : '',
        ));

        return self::SUCCESS;
    }
}

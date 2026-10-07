<?php

namespace App\Console\Commands;

use App\Services\ScheduleCarryForwardService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CarryForwardSchedules extends Command
{
    protected $signature = 'schedules:carry-forward {--date= : Service date (Y-m-d), defaults to today}';

    protected $description = 'Copy the latest known schedules and stops to a date that has not been synced yet';

    public function handle(ScheduleCarryForwardService $carry): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $result = $carry->carryForward($date);

        $this->line(sprintf('%s: %d schedules, %d stops carried forward', $date->toDateString(), $result['schedules'], $result['stops']));

        return self::SUCCESS;
    }
}

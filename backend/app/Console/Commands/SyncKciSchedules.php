<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Services\ScheduleSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncKciSchedules extends Command
{
    protected $signature = 'kci:sync-schedules
        {--date= : First service date to sync (Y-m-d), defaults to today}
        {--day-offset=0 : Without --date: sync for today + this many days (the scheduled runs use 0)}
        {--days= : Number of days to sync, defaults to KCI_SYNC_DAYS}
        {--station=* : Only sync these station codes (repeatable), defaults to KCI_SYNC_STATIONS}
        {--trigger=console : Recorded in sync_logs (console or schedule)}';

    protected $description = 'Synchronize KRL stations and schedules from the KCI API into the database';

    public function handle(ScheduleSyncService $sync): int
    {
        $from = $this->option('date')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('date'))
            : CarbonImmutable::today()->addDays((int) $this->option('day-offset'));
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        $log = $sync->createLog($this->option('trigger'), status: SyncStatus::Running);
        $this->info("Sync #{$log->id} started (source: {$log->source})...");

        $log = $sync->run($log, $from, $days, $this->option('station') ?: null);

        $this->line("Status: {$log->status->value}");
        $this->line("Stations: {$log->stations_processed}, records: {$log->records_processed}");

        if ($log->error_message) {
            $this->warn($log->error_message);
        }

        return $log->status === SyncStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}

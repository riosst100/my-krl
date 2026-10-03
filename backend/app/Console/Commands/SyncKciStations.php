<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Services\StationSyncService;
use Illuminate\Console\Command;

class SyncKciStations extends Command
{
    protected $signature = 'kci:sync-stations {--trigger=console : Recorded in sync_logs (console or schedule)}';

    protected $description = 'Synchronize the KRL station list from the KCI API (runs monthly)';

    public function handle(StationSyncService $sync): int
    {
        $log = $sync->createLog($this->option('trigger'), status: SyncStatus::Running);
        $this->info("Station sync #{$log->id} started (source: {$log->source})...");

        $log = $sync->run($log);

        $this->line("Status: {$log->status->value}");
        $this->line("Stations: {$log->records_processed} (new: ".($log->meta['created'] ?? 0).', changed: '.($log->meta['updated'] ?? 0).')');

        if ($log->error_message) {
            $this->warn($log->error_message);
        }

        return $log->status === SyncStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}

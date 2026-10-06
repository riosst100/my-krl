<?php

namespace App\Console\Commands;

use App\Services\AutoSyncService;
use Illuminate\Console\Command;

class AutoSyncKci extends Command
{
    protected $signature = 'kci:auto-sync';

    protected $description = 'Start the KCI sync when one of the configured times of day is due (run every minute by the scheduler)';

    public function handle(AutoSyncService $auto): int
    {
        foreach ($auto->runDue() as $log) {
            $this->info("Sync #{$log->id} ({$log->type}) queued.");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\StationSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncKciStationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $syncLogId) {}

    public function handle(StationSyncService $sync): void
    {
        $log = SyncLog::find($this->syncLogId);

        if ($log) {
            $sync->run($log);
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncLog::whereKey($this->syncLogId)
            ->whereIn('status', [SyncStatus::Queued, SyncStatus::Running])
            ->update([
                'status' => SyncStatus::Failed,
                'error_message' => 'Sync job crashed: '.($exception?->getMessage() ?? 'unknown error'),
                'finished_at' => now(),
            ]);
    }
}

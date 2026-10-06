<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\KciDirectSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Sync dari KCI" on the server (manual button or automatic schedule).
 */
class SyncKciJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $syncLogId) {}

    public function handle(KciDirectSyncService $sync): void
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
                'error_message' => 'Job berhenti: '.($exception?->getMessage() ?? 'unknown error'),
                'finished_at' => now(),
            ]);
    }
}

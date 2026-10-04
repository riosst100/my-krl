<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\ScheduleSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncKciSchedulesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  string|null  $serviceDate  Y-m-d to store the timetable for (null = today)
     */
    public function __construct(
        public readonly int $syncLogId,
        public readonly ?string $serviceDate = null,
    ) {}

    public function handle(ScheduleSyncService $sync): void
    {
        $log = SyncLog::find($this->syncLogId);

        if ($log) {
            $sync->run($log, $this->serviceDate ? CarbonImmutable::parse($this->serviceDate) : null);
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

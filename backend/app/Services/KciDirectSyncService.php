<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\Kci\KciRequestLog;

/**
 * "Sync dari KCI": fetch the configured stations' timetable and train stops
 * from KCI straight into this database (through the kci-fetch sidecar), with
 * progress (phase, done/total, percent) on the sync log.
 */
class KciDirectSyncService
{
    /** Phases in run order; `weight` is the share of the progress bar (sums to 100). */
    private const PHASES = [
        'fetch_schedules' => ['weight' => 15, 'label' => 'Mengambil jadwal dari KCI'],
        'fetch_stops' => ['weight' => 85, 'label' => 'Mengambil pemberhentian kereta dari KCI'],
    ];

    private float $lastWrite = 0;

    public function __construct(
        private readonly ScheduleSyncService $sync,
        private readonly KciRequestLog $requests,
    ) {}

    public function createLog(?int $userId, string $trigger = 'manual'): SyncLog
    {
        $log = $this->sync->createLog($trigger, $userId);
        $log->update(['meta' => [...$log->meta, 'progress' => $this->progressMeta('fetch_schedules', 0, 1)]]);

        return $log;
    }

    public function run(SyncLog $log): SyncLog
    {
        // Every KCI request of this run is listed in the admin panel (Log request).
        $this->requests->begin($log);

        try {
            $log = $this->sync->run($log, now(), null, null, function (string $phase, int $done, int $total) use ($log) {
                $this->progress($log, $phase === 'stops' ? 'fetch_stops' : 'fetch_schedules', $done, $total);
            });
        } finally {
            $this->requests->end();
        }

        $log->update(['meta' => [...$log->meta, 'progress' => $this->progressMeta('fetch_stops', 1, 1, complete: $log->status !== SyncStatus::Failed)]]);

        return $log;
    }

    /**
     * @return array{phase: string, label: string, done: int, total: int, percent: int, detail: null}
     */
    private function progressMeta(string $phase, int $done, int $total, bool $complete = false): array
    {
        $before = $phase === 'fetch_stops' ? self::PHASES['fetch_schedules']['weight'] : 0;
        $fraction = $total > 0 ? min(1, $done / $total) : 1;
        $percent = (int) round($before + self::PHASES[$phase]['weight'] * $fraction);

        return [
            'phase' => $phase,
            'label' => self::PHASES[$phase]['label'],
            'done' => $done,
            'total' => $total,
            'percent' => $complete ? 100 : min(99, $percent),
            'detail' => null,
        ];
    }

    /**
     * Writes the progress to the log (at most about once a second, always at
     * the start and end of a phase). The log instance is the one the sync is
     * updating, so its in-memory meta is kept in step.
     */
    private function progress(SyncLog $log, string $phase, int $done, int $total): void
    {
        $edge = $done === 0 || $done >= $total;
        $now = microtime(true);

        if (! $edge && $now - $this->lastWrite < 1.0) {
            return;
        }

        $this->lastWrite = $now;
        $log->update(['meta' => [...($log->meta ?? []), 'progress' => $this->progressMeta($phase, $done, $total)]]);
    }
}

<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Services\Kci\KciRequestLog;

/**
 * "Sync dari KCI": fetch stations, the configured stations' timetable, or the
 * stops of their trains from KCI straight into this database (through the
 * kci-fetch sidecar). Each kind is its own run with its own sync log and
 * progress (phase, done/total, percent).
 */
class KciDirectSyncService
{
    /** Progress label per sync log type. */
    private const PHASES = [
        SyncLog::TYPE_KCI_STATIONS => ['phase' => 'stations', 'label' => 'Mengambil daftar stasiun dari KCI'],
        SyncLog::TYPE_KCI_SCHEDULES => ['phase' => 'fetch_schedules', 'label' => 'Mengambil jadwal dari KCI'],
        SyncLog::TYPE_KCI_TRAIN_STOPS => ['phase' => 'fetch_stops', 'label' => 'Mengambil pemberhentian kereta dari KCI'],
    ];

    private float $lastWrite = 0;

    public function __construct(
        private readonly StationSyncService $stations,
        private readonly ScheduleSyncService $sync,
        private readonly TrainStopSyncService $trainStops,
        private readonly KciRequestLog $requests,
    ) {}

    /**
     * @param  string  $type  a SyncLog::KCI_TYPES value
     */
    public function createLog(string $type, ?int $userId, string $trigger = 'manual'): SyncLog
    {
        $log = match ($type) {
            SyncLog::TYPE_KCI_STATIONS => $this->stations->createLog($trigger, $userId),
            SyncLog::TYPE_KCI_TRAIN_STOPS => $this->trainStops->createLog($trigger, $userId),
            default => $this->sync->createLog($trigger, $userId),
        };
        $log->update(['meta' => [...($log->meta ?? []), 'progress' => $this->progressMeta($log->type, 0, 1)]]);

        return $log;
    }

    public function run(SyncLog $log): SyncLog
    {
        // Every KCI request of this run is listed in the admin panel (Log request).
        $this->requests->begin($log);
        $progress = fn (string $phase, int $done, int $total) => $this->progress($log, $done, $total);

        try {
            $log = match ($log->type) {
                SyncLog::TYPE_KCI_STATIONS => $this->stations->run($log),
                SyncLog::TYPE_KCI_TRAIN_STOPS => $this->trainStops->run($log, $progress),
                default => $this->sync->run($log, now(), null, null, $progress),
            };
        } finally {
            $this->requests->end();
        }

        $log->update(['meta' => [...($log->meta ?? []), 'progress' => $this->progressMeta($log->type, 1, 1, complete: $log->status !== SyncStatus::Failed)]]);

        return $log;
    }

    /**
     * @return array{phase: string, label: string, done: int, total: int, percent: int, detail: null}
     */
    private function progressMeta(string $type, int $done, int $total, bool $complete = false): array
    {
        $phase = self::PHASES[$type] ?? self::PHASES[SyncLog::TYPE_KCI_SCHEDULES];
        $fraction = $total > 0 ? min(1, $done / $total) : 1;

        return [
            'phase' => $phase['phase'],
            'label' => $phase['label'],
            'done' => $done,
            'total' => $total,
            'percent' => $complete ? 100 : min(99, (int) round(100 * $fraction)),
            'detail' => null,
        ];
    }

    /**
     * Writes the progress to the log (at most about once a second, always at
     * the start and end of a phase). The log instance is the one the sync is
     * updating, so its in-memory meta is kept in step.
     */
    private function progress(SyncLog $log, int $done, int $total): void
    {
        $edge = $done === 0 || $done >= $total;
        $now = microtime(true);

        if (! $edge && $now - $this->lastWrite < 1.0) {
            return;
        }

        $this->lastWrite = $now;
        $log->update(['meta' => [...($log->meta ?? []), 'progress' => $this->progressMeta($log->type, $done, $total)]]);
    }
}

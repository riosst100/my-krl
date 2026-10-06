<?php

namespace App\Services\Kci;

use App\Models\SyncLog;
use App\Models\SyncLogRequest;
use Throwable;

/**
 * Records the requests KciUrlClient sends while a sync run is active, so the
 * admin panel can show "GET <url> → OK / HTTP 403 ..." (never the body).
 * Bound as a singleton: the sync service opens it, the client writes to it.
 */
class KciRequestLog
{
    /** Request logs of older syncs are removed when a new run starts. */
    private const RETENTION_DAYS = 7;

    private ?int $syncLogId = null;

    public function begin(SyncLog $log): void
    {
        $this->syncLogId = $log->id;

        SyncLogRequest::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    public function end(): void
    {
        $this->syncLogId = null;
    }

    public function record(string $url, bool $ok, ?int $status = null, ?string $message = null, ?float $seconds = null): void
    {
        if ($this->syncLogId === null) {
            return;
        }

        try {
            SyncLogRequest::create([
                'sync_log_id' => $this->syncLogId,
                'url' => $url,
                'status_code' => $status,
                'ok' => $ok,
                'message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'duration_ms' => $seconds !== null ? (int) round($seconds * 1000) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // The log is informational: never let it break the sync.
        }
    }
}

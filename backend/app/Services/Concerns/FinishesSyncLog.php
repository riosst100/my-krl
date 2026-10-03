<?php

namespace App\Services\Concerns;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Log;

trait FinishesSyncLog
{
    private function finish(SyncLog $log, SyncStatus $status, ?string $error = null): SyncLog
    {
        $log->fill([
            'status' => $status,
            'error_message' => $error,
            'started_at' => $log->started_at ?? now(),
            'finished_at' => now(),
        ])->save();

        Log::info('KCI sync finished', [
            'log_id' => $log->id,
            'type' => $log->type,
            'status' => $status->value,
            'records' => $log->records_processed,
        ]);

        return $log;
    }
}

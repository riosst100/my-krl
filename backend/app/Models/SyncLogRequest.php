<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sync_log_id', 'url', 'status_code', 'ok', 'message', 'duration_ms', 'created_at'])]
class SyncLogRequest extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'station_code', 'checked_at', 'ok', 'trains', 'first_departure', 'last_departure',
    'content_hash', 'changed', 'diff', 'error',
])]
class KciTimetableCheck extends Model
{
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
            'changed' => 'boolean',
            'diff' => 'array',
        ];
    }
}

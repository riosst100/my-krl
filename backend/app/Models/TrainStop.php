<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_date', 'train_number', 'sequence', 'station_code', 'station_id', 'time', 'is_transit'])]
class TrainStop extends Model
{
    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'is_transit' => 'boolean',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'station_id', 'train_line_id', 'color', 'train_number', 'route_name', 'destination',
    'departure_time', 'destination_arrival_time', 'service_date',
])]
class Schedule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function trainLine(): BelongsTo
    {
        return $this->belongsTo(TrainLine::class);
    }

    public function scopeOnDate(Builder $query, string $date): void
    {
        $query->whereDate('service_date', $date);
    }
}

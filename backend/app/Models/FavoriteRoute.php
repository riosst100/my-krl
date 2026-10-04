<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'from_station_id', 'to_station_id', 'position'])]
class FavoriteRoute extends Model
{
    public function from(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }
}

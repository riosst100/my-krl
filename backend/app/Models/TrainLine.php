<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'color'])]
class TrainLine extends Model
{
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}

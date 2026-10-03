<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'slug', 'latitude', 'longitude', 'operational_area', 'kci_enabled', 'is_active', 'synced_at'])]
class Station extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'kci_enabled' => 'boolean',
            'operational_area' => 'integer',
            'synced_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $term = '%'.trim($term).'%';
        $query->where(fn (Builder $q) => $q->whereLike('name', $term)->orWhereLike('code', $term));
    }

    /**
     * Public routes resolve an *active* station by code (BKS) or slug (bekasi);
     * routes with an explicit field (e.g. {station:id} in admin) use the default.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return $this->newQuery()
            ->active()
            ->where(fn (Builder $q) => $q->where('code', strtoupper($value))->orWhere('slug', strtolower($value)))
            ->first();
    }
}

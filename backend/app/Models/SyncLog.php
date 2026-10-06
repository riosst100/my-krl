<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type', 'status', 'trigger', 'triggered_by', 'source', 'records_processed',
    'stations_processed', 'error_message', 'meta', 'started_at', 'finished_at',
])]
class SyncLog extends Model
{
    public const TYPE_KCI_SCHEDULES = 'kci_schedules';

    public const TYPE_KCI_STATIONS = 'kci_stations';

    public const TYPE_KCI_TRAIN_STOPS = 'kci_train_stops';

    /** The separate "Sync dari KCI" runs, by API name, in the order they depend on each other. */
    public const KCI_TYPES = [
        'stations' => self::TYPE_KCI_STATIONS,
        'schedules' => self::TYPE_KCI_SCHEDULES,
        'trains' => self::TYPE_KCI_TRAIN_STOPS,
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Queued or running logs that are recent enough to still be alive.
     */
    public function scopeInProgress(Builder $query, ?string $type = null): void
    {
        $query->when($type, fn (Builder $q) => $q->where('type', $type))
            ->whereIn('status', [SyncStatus::Queued, SyncStatus::Running])
            ->where('created_at', '>=', now()->subMinutes(config('kci.stale_after_minutes')));
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SyncLog */
class SyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status->value,
            'trigger' => $this->trigger,
            'source' => $this->source,
            'triggered_by' => $this->whenLoaded('triggeredBy', fn () => $this->triggeredBy?->only(['id', 'name', 'email'])),
            'records_processed' => $this->records_processed,
            'stations_processed' => $this->stations_processed,
            'error_message' => $this->error_message,
            'meta' => $this->meta,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

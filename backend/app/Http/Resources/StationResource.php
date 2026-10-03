<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Station */
class StationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'operational_area' => $this->operational_area,
            'operational_area_name' => $this->operational_area !== null
                ? (Setting::operationalAreas()[$this->operational_area] ?? null)
                : null,
            'kci_enabled' => $this->kci_enabled,
            'is_active' => $this->is_active,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'schedules_count' => $this->whenCounted('schedules'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

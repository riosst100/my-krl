<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Schedule */
class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'train_number' => $this->train_number,
            'line' => new TrainLineResource($this->whenLoaded('trainLine')),
            // Colour of this train as published by KCI (falls back to the line colour).
            'color' => $this->color ?? $this->trainLine?->color,
            'route_name' => $this->route_name,
            // KCI identifies direction by the train's final destination.
            'destination' => $this->destination,
            'departure_time' => substr($this->departure_time, 0, 5),
            'destination_arrival_time' => $this->destination_arrival_time ? substr($this->destination_arrival_time, 0, 5) : null,
            'service_date' => $this->service_date->toDateString(),
            // Only present when searching with ?to=: arrival at the chosen station.
            'to_station_arrival_time' => $this->when(
                isset($this->to_station_time),
                fn () => substr($this->to_station_time, 0, 5),
            ),
            'stops_to_station' => $this->when(isset($this->stops_to_station), fn () => (int) $this->stops_to_station),
            'station' => new StationResource($this->whenLoaded('station')),
        ];
    }
}

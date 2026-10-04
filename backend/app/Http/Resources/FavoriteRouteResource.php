<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FavoriteRoute */
class FavoriteRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $station = fn ($s) => ['code' => $s->code, 'name' => $s->name, 'slug' => $s->slug];

        return [
            'id' => $this->id,
            'position' => $this->position,
            'from' => $station($this->from),
            'to' => $station($this->to),
        ];
    }
}

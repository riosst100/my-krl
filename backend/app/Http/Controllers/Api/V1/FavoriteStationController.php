<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFavoriteStationsRequest;
use App\Http\Resources\StationResource;
use App\Services\FavoriteStationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET/PUT /me/favorite-stations — the signed-in user's favourite departure stations.
 */
class FavoriteStationController extends Controller
{
    public function __construct(private readonly FavoriteStationService $favorites) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return StationResource::collection($this->favorites->forUser($request->user()))
            ->additional(['meta' => ['required' => FavoriteStationService::REQUIRED]]);
    }

    public function update(UpdateFavoriteStationsRequest $request): AnonymousResourceCollection
    {
        return StationResource::collection($this->favorites->replace($request->user(), $request->codes()))
            ->additional(['meta' => ['required' => FavoriteStationService::REQUIRED]]);
    }
}

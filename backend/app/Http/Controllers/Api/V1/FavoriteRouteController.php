<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFavoriteRoutesRequest;
use App\Http\Resources\FavoriteRouteResource;
use App\Http\Resources\ScheduleResource;
use App\Services\FavoriteRouteService;
use App\Services\ScheduleSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /me/favorite-routes — the signed-in user's favourite routes (departure -> destination station).
 */
class FavoriteRouteController extends Controller
{
    public function __construct(private readonly FavoriteRouteService $favorites) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->respond($this->favorites->forUser($request->user()));
    }

    public function update(UpdateFavoriteRoutesRequest $request): AnonymousResourceCollection
    {
        return $this->respond($this->favorites->replace($request->user(), $request->routes()));
    }

    /**
     * GET /me/favorite-routes/departures?limit=2 — the next trains of every favourite route.
     */
    public function departures(Request $request, ScheduleSearchService $search): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:5']]);
        $limit = (int) ($validated['limit'] ?? 2);

        $data = $this->favorites->forUser($request->user())->map(function ($route) use ($search, $limit) {
            $result = $search->nextRouteDepartures($route->from, $route->to, $limit);

            return [
                ...(new FavoriteRouteResource($route))->resolve(),
                'has_schedules_today' => $result['has_schedules_today'],
                'departures' => ScheduleResource::collection($result['departures'])->resolve(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'now' => now()->toIso8601String(),
                'timezone' => config('app.timezone'),
                'last_synced_at' => $search->lastSyncedAt(),
            ],
        ]);
    }

    private function respond($routes): AnonymousResourceCollection
    {
        return FavoriteRouteResource::collection($routes)->additional([
            'meta' => ['min' => FavoriteRouteService::MIN, 'max' => FavoriteRouteService::MAX],
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\FavoriteRoute;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FavoriteRouteService
{
    /** A user keeps at least this many favourite routes... */
    public const MIN = 1;

    /** ...and at most this many. */
    public const MAX = 4;

    /**
     * @return Collection<int, FavoriteRoute> ordered by position, with both stations loaded
     */
    public function forUser(User $user): Collection
    {
        return $user->favoriteRoutes()->with(['from', 'to'])->get();
    }

    /**
     * Replaces the user's favourite routes with the given ones, in order.
     *
     * @param  list<array{from: string, to: string}>  $routes  station codes
     * @return Collection<int, FavoriteRoute>
     */
    public function replace(User $user, array $routes): Collection
    {
        $codes = collect($routes)->flatMap(fn (array $r) => [$r['from'], $r['to']])->unique()->all();
        $stations = Station::active()->whereIn('code', $codes)->get()->keyBy('code');

        DB::transaction(function () use ($user, $routes, $stations) {
            $user->favoriteRoutes()->delete();

            foreach (array_values($routes) as $i => $route) {
                $user->favoriteRoutes()->create([
                    'from_station_id' => $stations[$route['from']]->id,
                    'to_station_id' => $stations[$route['to']]->id,
                    'position' => $i + 1,
                ]);
            }
        });

        return $this->forUser($user);
    }
}

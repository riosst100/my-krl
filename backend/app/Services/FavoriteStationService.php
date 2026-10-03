<?php

namespace App\Services;

use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FavoriteStationService
{
    /** The homepage asks every user for exactly this many favourite stations. */
    public const REQUIRED = 2;

    /**
     * @return Collection<int, Station> ordered by position
     */
    public function forUser(User $user): Collection
    {
        return $user->favoriteStations()->orderByPivot('position')->get();
    }

    /**
     * Replaces the user's favourites with the given station codes, in order.
     *
     * @param  list<string>  $codes
     * @return Collection<int, Station>
     */
    public function replace(User $user, array $codes): Collection
    {
        $stations = Station::active()->whereIn('code', $codes)->get()->keyBy('code');

        DB::transaction(function () use ($user, $codes, $stations) {
            $user->favoriteStations()->detach();

            foreach (array_values($codes) as $i => $code) {
                $user->favoriteStations()->attach($stations[$code]->id, ['position' => $i + 1]);
            }
        });

        return $this->forUser($user);
    }
}

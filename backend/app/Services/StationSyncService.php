<?php

namespace App\Services;

use App\Models\Station;
use App\Services\Kci\Data\KciStation;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores KCI station master data (from a pasted KCI response, or the seeder).
 * krl-sync on Vercel sends stations through the ingest API instead.
 */
class StationSyncService
{
    /**
     * Upserts KCI stations. New stations take KCI's enabled flag as their
     * initial is_active; afterwards is_active belongs to the admin.
     *
     * @param  Collection<int, KciStation>  $stations
     * @return array{total: int, created: int, updated: int, missing: list<string>, removed: list<string>}
     */
    public function import(Collection $stations): array
    {
        if ($stations->isEmpty()) {
            throw KciApiException::invalidResponse('station list is empty');
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($stations, &$created, &$updated) {
            foreach ($stations as $data) {
                $station = Station::firstOrNew(['code' => $data->code]);
                $station->name = Str::title(Str::lower($data->name));
                $station->kci_enabled = $data->enabled;
                $station->operational_area = $data->operationalArea;

                if (! $station->exists) {
                    $station->is_active = $data->enabled;
                    $station->slug = $this->uniqueSlug($station->name, $data->code);
                    $created++;
                } elseif ($station->isDirty()) {
                    $updated++;
                }

                $station->synced_at = now();
                $station->save();
            }
        });

        // Clean up area header rows ("WIL0 / AREA JABODETABEK") saved by older syncs.
        $removed = Station::where(fn ($q) => $q->where('code', 'like', 'WIL%')->orWhere('name', 'ilike', 'area %'))
            ->whereDoesntHave('schedules')
            ->get()
            ->filter(fn (Station $s) => KciService::isAreaHeader($s->code, $s->name))
            ->each->delete()
            ->pluck('code')
            ->all();

        $missing = Station::whereNotIn('code', $stations->pluck('code'))->orderBy('code')->pluck('code')->all();

        return ['total' => $stations->count(), 'created' => $created, 'updated' => $updated, 'missing' => $missing, 'removed' => $removed];
    }

    private function uniqueSlug(string $name, string $code): string
    {
        $slug = Str::slug($name);

        return Station::where('slug', $slug)->exists() ? Str::slug("{$name}-{$code}") : $slug;
    }
}

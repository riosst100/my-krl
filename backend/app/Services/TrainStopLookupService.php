<?php

namespace App\Services;

use App\Models\Station;
use App\Models\TrainStop;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A train's stops for today, fetched when its detail is opened instead of by
 * the daily sync. The server cannot reach KCI, so krl-sync on Vercel fetches
 * them (GET ?train=…); the result is stored, and later lookups today are
 * served from the database.
 */
class TrainStopLookupService
{
    public function __construct(private readonly TrainStopSyncService $trainStops) {}

    /**
     * Whether today's stops of $train are stored (fetched now if needed).
     * Carried-forward stops do not count: they are refreshed, and kept when
     * the fetch fails.
     */
    public function ensureToday(string $train): bool
    {
        $today = CarbonImmutable::today()->toDateString();

        if ($this->fetched($today, $train)) {
            return true;
        }

        $url = trim((string) config('kci.stops_proxy_url'));
        $token = (string) config('kci.ingest_token');

        if ($url === '' || $token === '') {
            return false;
        }

        // One fetch per train at a time: concurrent viewers wait for it.
        return (bool) Cache::lock("train-stops:{$today}:{$train}", 40)->block(35, function () use ($today, $train, $url, $token) {
            if ($this->fetched($today, $train)) {
                return true;
            }

            try {
                $response = Http::withToken($token)->acceptJson()->timeout(30)->get($url, ['train' => $train]);
            } catch (ConnectionException $e) {
                Log::warning('krl-sync train stops unreachable', ['train' => $train, 'error' => $e->getMessage()]);

                return false;
            }

            $stops = $response->json('stops');

            if (! $response->successful() || ! is_array($stops) || $stops === []) {
                Log::warning('krl-sync train stops failed', ['train' => $train, 'status' => $response->status(), 'error' => $response->json('error')]);

                return false;
            }

            $rows = array_map(fn (array $s) => [
                'station_code' => strtoupper((string) $s['station_code']),
                'time' => (string) $s['time'],
                'is_transit' => (bool) ($s['is_transit'] ?? false),
            ], $stops);

            $this->trainStops->store($today, $train, $rows, Station::pluck('id', 'code'));

            return true;
        });
    }

    private function fetched(string $date, string $train): bool
    {
        return TrainStop::whereDate('service_date', $date)
            ->where('train_number', $train)
            ->where('carried_forward', false)
            ->exists();
    }
}
